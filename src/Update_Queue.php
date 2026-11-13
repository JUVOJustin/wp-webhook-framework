<?php
/**
 * Latest-state delivery for scheduled update notifications.
 *
 * @package juvo\WP_Webhook_Framework
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework;

use Throwable;
use WP_Exception;

/**
 * Coalesces pending changes without making mutable payloads part of native uniqueness.
 *
 * @phpstan-import-type UpdateRecord from Update_Store
 */
final class Update_Queue {

	/** Stable hook remains compatible with records queued by other plugin copies. */
	public const HOOK = 'wpwf_send_update';

	/** Periodic recovery also handles hard worker exits with no completion callback. */
	public const RECOVERY_HOOK = 'wpwf_recover_updates';

	/**
	 * Durable snapshots shared across requests.
	 *
	 * @var Update_Store
	 */
	private Update_Store $store;

	/**
	 * Avoid fetching unrelated WooCommerce actions from global completion hooks.
	 *
	 * @var array<string,true>
	 */
	private array $processed_keys = array();

	/**
	 * Share the dispatcher so immediate and queued deliveries keep the same HTTP contract.
	 *
	 * @param Dispatcher $dispatcher Framework delivery boundary.
	 */
	public function __construct( private Dispatcher $dispatcher ) {
		$this->store = new Update_Store();
	}

	/**
	 * Keep the first deadline while replacing explicitly supplied change hints.
	 *
	 * @param string              $url Destination.
	 * @param string              $entity Entity type.
	 * @param int|string          $id Entity ID.
	 * @param array<string,mixed> $payload Latest change hints, not an accumulated event log.
	 * @param array<string,mixed> $headers Latest delivery headers.
	 */
	public function schedule( string $url, string $entity, int|string $id, array $payload, array $headers ): void {
		$key = hash( 'sha256', (string) wp_json_encode( array( $url, $headers['wpwf-webhook-name'] ?? '', $entity, (string) $id, 'meta' === $entity ? ( $payload['meta_type'] ?? '' ) : '' ) ) );
		$this->store->locked(
			$key,
			function () use ( $key, $url, $entity, $id, $payload, $headers ): void {
				$previous = $this->store->get( $key );
				$this->store->put(
					$key,
					array(
						'revision' => wp_generate_uuid4(),
						'url'      => $url,
						'entity'   => $entity,
						'id'       => $id,
						'payload'  => $payload,
						'headers'  => $headers,
						'due'      => $previous['due'] ?? time() + 5,
						'attempt'  => 0,
					)
				);
				$this->enqueue( $key );
			}
		);
	}

	/**
	 * Enqueue only under the identity mutex, including on legacy or migration stores.
	 *
	 * @param string $key Delivery identity.
	 * @throws WP_Exception If the scheduler rejects the wake-up action.
	 */
	private function enqueue( string $key ): void {
		$record = $this->store->get( $key );
		if ( null === $record ) {
			return;
		}
		$args  = array( 'key' => $key );
		$group = 'wpwf-update-' . $key;
		if ( as_has_scheduled_action( self::HOOK, $args, $group ) ) {
			return;
		}
		if ( 0 === as_schedule_single_action( max( time(), $record['due'] ), self::HOOK, $args, $group, true ) ) {
			// State remains durable; recovery can retry storage failures without losing changes.
			throw new WP_Exception( 'webhook_schedule_failed' );
		}
	}

	/**
	 * Send a snapshot, acknowledging only that revision so concurrent updates survive.
	 *
	 * @param string $key Delivery identity persisted in the scheduler.
	 */
	public function process( string $key ): void {
		$this->processed_keys[ $key ] = true;
		$this->store->locked(
			'send:' . $key,
			function () use ( $key ): void {
				$record = $this->store->get( $key );
				if ( null === $record ) {
					return;
				}
				$headers                   = $record['headers'];
				$headers['wpwf-update-id'] = $record['revision'];
				if ( 0 < $record['attempt'] ) {
					$headers['wpwf-retry-count'] = $record['attempt'];
				}
				try {
					$this->dispatcher->process_scheduled_webhook( $record['url'], 'update', $record['entity'], $record['id'], $record['payload'], $headers, false );
				} catch ( Throwable $error ) {
					$this->acknowledge( $key, $record, $error );
					throw $error;
				}
				$this->acknowledge( $key, $record );
			},
			0
		);
	}

	/**
	 * Never let an older attempt delete or retry over a newer accepted event.
	 *
	 * @param string              $key Delivery identity.
	 * @param array<string,mixed> $sent Snapshot used by this attempt.
	 * @param Throwable|null      $error Delivery outcome.
	 * @phpstan-param UpdateRecord $sent
	 */
	private function acknowledge( string $key, array $sent, ?Throwable $error = null ): void {
		$this->store->locked(
			$key,
			function () use ( $key, $sent, $error ): void {
				$current = $this->store->get( $key );
				if ( null === $current || $current['revision'] !== $sent['revision'] ) {
					return;
				}
				$name    = (string) ( $sent['headers']['wpwf-webhook-name'] ?? '' );
				$webhook = Webhook_Registry::instance()->get( $name );
				if ( $error instanceof WP_Exception && 'webhook_delivery_failed' === $error->getMessage() && null !== $webhook && $sent['attempt'] < $webhook->get_max_retries() ) {
					++$current['attempt'];
					$current['due'] = time() + $webhook->calculate_retry_delay( $current['attempt'] );
					$this->store->put( $key, $current );
					return;
				}
				$this->store->delete( $key );
			}
		);
	}

	/**
	 * Run after the scheduler releases uniqueness, on both completion and failure.
	 *
	 * @param int $action_id Completed or failed scheduler action.
	 */
	public function after_action( int $action_id ): void {
		if ( empty( $this->processed_keys ) ) {
			return;
		}
		$action = \ActionScheduler::store()->fetch_action( $action_id );
		if ( self::HOOK !== $action->get_hook() ) {
			return;
		}
		$key = (string) ( $action->get_args()['key'] ?? '' );
		unset( $this->processed_keys[ $key ] );
		$this->store->locked( $key, fn() => $this->enqueue( $key ) );
	}

	/** Ensure persisted changes remain recoverable after an interrupted enqueue or worker. */
	public function register_recovery(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! \ActionScheduler::is_initialized() ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::RECOVERY_HOOK, array(), 'wpwf' ) ) {
			as_schedule_recurring_action( time() + 60, 60, self::RECOVERY_HOOK, array(), 'wpwf', true );
		}
	}

	/** Revisit durable records in bounded pages, including records with no scheduler action. */
	public function recover(): void {
		$this->store->locked(
			'recovery',
			function (): void {
				$keys = $this->store->pending_keys( (int) get_option( 'wpwf_recovery_cursor', 0 ) );
				foreach ( $keys as $key ) {
					try {
						$this->store->locked( $key, fn() => $this->enqueue( $key ), 0 );
					} catch ( WP_Exception $error ) {
						// Busy producers are revisited next pass without delaying the whole recovery batch.
						if ( 'webhook_update_lock_unavailable' !== $error->getMessage() ) {
							wp_trigger_error( __METHOD__, $error->getMessage() );
						}
					}
				}
				update_option( 'wpwf_recovery_cursor', empty( $keys ) ? 0 : max( array_keys( $keys ) ), false );
			},
			0
		);
	}
}
