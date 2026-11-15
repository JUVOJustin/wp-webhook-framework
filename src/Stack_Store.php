<?php
/**
 * Durable stacked records for coalesced deliveries.
 *
 * @package juvo\WP_Webhook_Framework
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework;

use WP_Exception;

/**
 * Keeps stack payloads out of scheduler arguments and the WordPress option cache.
 *
 * Named database locks serialize writers across PHP processes without holding a
 * transaction open in the consuming plugin. Records are non-autoloaded JSON.
 *
 * @phpstan-type StackRecord array{url:string,entity:string,id:int|string,headers:array<string,mixed>,events:list<array{timestamp:string,payload:array<string,mixed>}>,opened_at:int,scheduled_for:int,due:int,attempt:int,delivery_id:int,body:?string}
 */
final class Stack_Store {

	/** Prefix isolates internal records from ordinary WordPress options. */
	public const PREFIX = 'wpwf_stack_';

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
			throw new WP_Exception( 'webhook_stack_database_unsupported' );
		}
		$name = 'wpwf:' . hash( 'sha256', (string) constant( 'DB_NAME' ) . ':' . $wpdb->prefix . ':' . $key );
		$name = substr( $name, 0, 64 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Connection-scoped mutexes cannot use the object cache.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );
		if ( '1' !== (string) $acquired ) {
			throw new WP_Exception( 'webhook_stack_lock_unavailable' );
		}
		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release only the mutex acquired by this connection.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Read directly so another worker's accepted events is immediately visible.
	 *
	 * @param string $key Delivery identity.
	 * @return array<string,mixed>|null
	 * @phpstan-return StackRecord|null
	 * @throws WP_Exception If persisted data is invalid or the read fails.
	 */
	public function get( string $key ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- This internal outbox deliberately bypasses per-request option caches.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::PREFIX . $key ) );
		if ( '' !== $wpdb->last_error ) {
			throw new WP_Exception( 'webhook_stack_read_failed' );
		}
		if ( null === $value ) {
			return null;
		}
		// Cast only the envelope: nested payload objects (including {}) must keep their JSON shape.
		$decoded = json_decode( $value );
		$record  = is_object( $decoded ) ? (array) $decoded : null;
		if ( is_array( $record ) ) {
			if ( isset( $record['headers'] ) && is_object( $record['headers'] ) ) {
				$record['headers'] = (array) $record['headers'];
			}
			if ( isset( $record['events'] ) && is_array( $record['events'] ) ) {
				foreach ( $record['events'] as &$event ) {
					if ( is_object( $event ) ) {
						$event = (array) $event;
						if ( isset( $event['payload'] ) && is_object( $event['payload'] ) ) {
							$event['payload'] = (array) $event['payload'];
						}
					}
				}
				unset( $event );
			}
		}
		if ( ! is_array( $record ) || ! isset( $record['url'], $record['entity'], $record['id'], $record['headers'], $record['events'], $record['opened_at'], $record['scheduled_for'], $record['due'], $record['attempt'], $record['delivery_id'] )
			|| ! is_string( $record['url'] ) || ! is_string( $record['entity'] )
			|| ( ! is_int( $record['id'] ) && ! is_string( $record['id'] ) ) || ! is_array( $record['headers'] )
			|| ! is_array( $record['events'] ) || ! array_is_list( $record['events'] ) || empty( $record['events'] )
			|| ! is_int( $record['opened_at'] ) || ! is_int( $record['scheduled_for'] ) || ! is_int( $record['due'] ) || ! is_int( $record['attempt'] ) || ! is_int( $record['delivery_id'] )
			|| ! array_key_exists( 'body', $record ) || ( null !== $record['body'] && ! is_string( $record['body'] ) ) ) {
			throw new WP_Exception( 'webhook_stack_state_invalid' );
		}
		foreach ( $record['events'] as $event ) {
			if ( ! is_array( $event ) || ! isset( $event['timestamp'], $event['payload'] ) || ! is_string( $event['timestamp'] ) || ! is_array( $event['payload'] ) ) {
				throw new WP_Exception( 'webhook_stack_state_invalid' );
			}
		}

		return $record;
	}

	/**
	 * Find only the latest stack for an identity through the indexed option-name prefix.
	 *
	 * @param string $identity Destination and entity identity hash.
	 * @return string|null
	 * @throws WP_Exception If the lookup fails.
	 */
	public function latest_key( string $identity ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Durable stack state bypasses request-local option caches.
		$name = $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 1', $wpdb->options, $wpdb->esc_like( self::PREFIX . $identity . ':' ) . '%' ) );
		if ( '' !== $wpdb->last_error ) {
			throw new WP_Exception( 'webhook_stack_read_failed' );
		}
		return null === $name ? null : substr( $name, strlen( self::PREFIX ) );
	}

	/**
	 * Persist the stack before attempting to enqueue its wake-up action.
	 *
	 * @param string              $key Delivery identity.
	 * @param array<string,mixed> $record Snapshot to retain until delivered or exhausted.
	 * @phpstan-param StackRecord $record
	 * @throws WP_Exception If serialization or persistence fails.
	 */
	public function put( string $key, array $record ): void {
		global $wpdb;
		$json = wp_json_encode( $record );
		if ( false === $json ) {
			throw new WP_Exception( 'webhook_stack_encoding_failed' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Durable internal records use SQL upserts under the identity mutex, not cached options.
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $wpdb->options, self::PREFIX . $key, $json ) );
		if ( false === $result ) {
			throw new WP_Exception( 'webhook_stack_write_failed' );
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
			throw new WP_Exception( 'webhook_stack_delete_failed' );
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
			throw new WP_Exception( 'webhook_stack_read_failed' );
		}
		$keys = array();
		foreach ( $rows as $row ) {
			$keys[ (int) $row['option_id'] ] = substr( $row['option_name'], strlen( self::PREFIX ) );
		}
		return $keys;
	}
}
