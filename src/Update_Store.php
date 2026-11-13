<?php
/**
 * Durable latest-state records for coalesced deliveries.
 *
 * @package juvo\WP_Webhook_Framework
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework;

use WP_Exception;

/**
 * Keeps pending payloads out of scheduler arguments and the WordPress option cache.
 *
 * Named database locks serialize writers across PHP processes without holding a
 * transaction open in the consuming plugin. Records are non-autoloaded JSON.
 *
 * @phpstan-type UpdateRecord array{revision:string,url:string,entity:string,id:int|string,payload:array<string,mixed>,headers:array<string,mixed>,due:int,attempt:int}
 */
final class Update_Store {

	/** Prefix isolates internal records from ordinary WordPress options. */
	public const PREFIX = 'wpwf_pending_update_';

	/**
	 * Serialize one identity's mutations on the site's primary MySQL connection.
	 *
	 * @template T
	 * @param string        $key Identity or processing lock key.
	 * @param callable(): T $callback Operation protected by the lock.
	 * @param int           $timeout Maximum wait in seconds.
	 * @return T
	 * @throws WP_Exception If the database cannot provide the lock.
	 */
	public function locked( string $key, callable $callback, int $timeout = 5 ): mixed {
		global $wpdb;
		// Older servers release the send mutex when acquiring the nested state mutex.
		$minimum = str_contains( $wpdb->db_server_info(), 'MariaDB' ) ? '10.1' : '5.7.5';
		if ( version_compare( $wpdb->db_version(), $minimum, '<' ) ) {
			throw new WP_Exception( 'webhook_update_database_unsupported' );
		}
		$name = 'wpwf:' . hash( 'sha256', (string) constant( 'DB_NAME' ) . ':' . $wpdb->prefix . ':' . $key );
		$name = substr( $name, 0, 64 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Connection-scoped mutexes cannot use the object cache.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );
		if ( '1' !== (string) $acquired ) {
			throw new WP_Exception( 'webhook_update_lock_unavailable' );
		}
		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release only the mutex acquired by this connection.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Read directly so another worker's committed revision is immediately visible.
	 *
	 * @param string $key Delivery identity.
	 * @return array<string,mixed>|null
	 * @phpstan-return UpdateRecord|null
	 * @throws WP_Exception If persisted data is invalid or the read fails.
	 */
	public function get( string $key ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- This internal outbox deliberately bypasses per-request option caches.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::PREFIX . $key ) );
		if ( '' !== $wpdb->last_error ) {
			throw new WP_Exception( 'webhook_update_read_failed' );
		}
		if ( null === $value ) {
			return null;
		}
		$record = json_decode( $value, true );
		if ( ! is_array( $record ) || ! isset( $record['revision'], $record['url'], $record['entity'], $record['id'], $record['payload'], $record['headers'], $record['due'], $record['attempt'] )
			|| ! is_string( $record['revision'] ) || ! is_string( $record['url'] ) || ! is_string( $record['entity'] )
			|| ( ! is_int( $record['id'] ) && ! is_string( $record['id'] ) ) || ! is_array( $record['payload'] ) || ! is_array( $record['headers'] )
			|| ! is_int( $record['due'] ) || ! is_int( $record['attempt'] ) ) {
			throw new WP_Exception( 'webhook_update_state_invalid' );
		}
		return $record;
	}

	/**
	 * Persist the latest snapshot before attempting to enqueue its wake-up action.
	 *
	 * @param string              $key Delivery identity.
	 * @param array<string,mixed> $record Snapshot to retain until delivered or exhausted.
	 * @phpstan-param UpdateRecord $record
	 * @throws WP_Exception If serialization or persistence fails.
	 */
	public function put( string $key, array $record ): void {
		global $wpdb;
		$json = wp_json_encode( $record );
		if ( false === $json ) {
			throw new WP_Exception( 'webhook_update_encoding_failed' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Durable internal records use SQL upserts under the identity mutex, not cached options.
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $wpdb->options, self::PREFIX . $key, $json ) );
		if ( false === $result ) {
			throw new WP_Exception( 'webhook_update_write_failed' );
		}
	}

	/**
	 * Remove acknowledged state while the caller holds the identity mutex.
	 *
	 * @param string $key Delivery identity.
	 * @throws WP_Exception If deleting the state fails.
	 */
	public function delete( string $key ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Records never enter the option cache.
		$result = $wpdb->delete( $wpdb->options, array( 'option_name' => self::PREFIX . $key ), array( '%s' ) );
		if ( false === $result ) {
			throw new WP_Exception( 'webhook_update_delete_failed' );
		}
	}

	/**
	 * Page through surviving records to recover interruptions between persistence and enqueue.
	 *
	 * @param int $after Last visited option ID.
	 * @return array<int,string> Option IDs mapped to delivery identities.
	 * @throws WP_Exception If the recovery query fails.
	 */
	public function pending_keys( int $after = 0 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded recovery scan; cursor prevents busy records starving later identities.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_id, option_name FROM %i WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT 100', $wpdb->options, $wpdb->esc_like( self::PREFIX ) . '%', $after ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new WP_Exception( 'webhook_update_read_failed' );
		}
		$keys = array();
		foreach ( $rows as $row ) {
			$keys[ (int) $row['option_id'] ] = substr( $row['option_name'], strlen( self::PREFIX ) );
		}
		return $keys;
	}
}
