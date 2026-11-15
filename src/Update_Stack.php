<?php
/**
 * Fixed-window update deliveries with immutable retry bodies.
 *
 * @package juvo\WP_Webhook_Framework
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework;

use ActionScheduler;
use ActionScheduler_Store;
use Throwable;
use WP_Exception;

/**
 * Keeps accepted events durable while scheduler actions serve as delivery wake-ups.
 *
 * @phpstan-import-type StackRecord from Stack_Store
 */
final class Update_Stack {

	/** Scheduler callback with only an internal storage key in its arguments. */
	public const HOOK = 'wpwf_send_stack';

	/** Recovery revisits records whose scheduler or worker was interrupted. */
	public const RECOVERY_HOOK = 'wpwf_recover_stacks';

	/** Bound memory and persistence without silently dropping accepted events. */
	public const MAX_EVENTS = 100;

	/** Maximum encoded unsealed record, including events and routing metadata. */
	public const MAX_BYTES = 262144;

	/**
	 * Durable state shared by all PHP processes.
	 *
	 * @var Stack_Store
	 */
	private Stack_Store $store;

	/**
	 * Avoid querying unrelated global scheduler completions.
	 *
	 * @var array<string,true>
	 */
	private array $processed_keys = array();

	/**
	 * Reuse the dispatcher's HTTP, filter and failure contracts.
	 *
	 * @param Dispatcher $dispatcher Shared delivery boundary.
	 */
	public function __construct( private Dispatcher $dispatcher ) {
		$this->store = new Stack_Store();
	}

	/**
	 * Accept an event under its identity mutex without extending the first deadline.
	 *
	 * @param string              $url Destination after URL filtering.
	 * @param string              $entity Entity type.
	 * @param int|string          $id Entity ID.
	 * @param array<string,mixed> $payload Event-time notification body.
	 * @param array<string,mixed> $headers Configured and emission-specific headers.
	 * @throws WP_Exception If the stack cannot accept the event.
	 */
	public function schedule( string $url, string $entity, int|string $id, array $payload, array $headers ): void {
		if ( array_key_exists( 'timestamp', $payload ) || array_key_exists( 'aggregated_events', $payload ) ) {
			throw new WP_Exception( 'webhook_stack_reserved_field' );
		}
		ksort( $headers );
		$identity = hash( 'sha256', (string) wp_json_encode( array( $url, $headers, $entity, (string) $id, 'meta' === $entity ? ( $payload['meta_type'] ?? '' ) : '' ) ) );
		$this->store->locked(
			$identity,
			function () use ( $identity, $url, $entity, $id, $payload, $headers ): void {
				$key = $this->store->locked(
					'recovery',
					function () use ( $identity, $url, $entity, $id, $payload, $headers ): string {
						// Storage hooks can emit events: read the current window only after recovery is armed.
						$this->ensure_recovery_action();
						$now    = $this->now();
						$key    = $this->store->latest_key( $identity );
						$record = null === $key ? null : $this->store->get( $key );
						$event  = array(
							'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
							'payload'   => $payload,
						);
						if ( null !== $record && null === $record['body'] && $record['scheduled_for'] > $now ) {
							$record['events'][] = $event;
						} else {
							$record = null;
						}
						if ( null === $record || ! $this->fits( $record ) ) {
							// Overflow starts an additional window; the full window and its deadline remain intact.
							$key    = $identity . ':' . wp_generate_uuid4();
							$record = array(
								'url'           => $url,
								'entity'        => $entity,
								'id'            => $id,
								'headers'       => $headers,
								'events'        => array( $event ),
								'opened_at'     => $now,
								'scheduled_for' => $now + 60,
								'due'           => $now + 60,
								'attempt'       => 0,
								'delivery_id'   => 0,
								'body'          => null,
							);
							if ( ! $this->fits( $record ) ) {
								throw new WP_Exception( 'webhook_stack_capacity_exceeded' );
							}
						}
						$this->store->put( $key, $record );
						return $key;
					}
				);
				$this->enqueue( $key );
			}
		);
	}

	/**
	 * Bound individual records without truncating events already accepted.
	 *
	 * @param array<string,mixed> $record Candidate window.
	 * @phpstan-param StackRecord $record
	 * @return bool
	 */
	private function fits( array $record ): bool {
		$json = wp_json_encode( $record );
		return false !== $json && self::MAX_EVENTS >= count( $record['events'] ) && self::MAX_BYTES >= strlen( $json );
	}

	/**
	 * Isolate the clock for deterministic boundary tests; production defaults to UTC epoch time.
	 *
	 * @return int
	 */
	private function now(): int {
		return (int) apply_filters( 'wpwf_stack_time', time() );
	}

	/**
	 * Recover the first action ID even if a worker died between enqueue and saving it.
	 * Called only while holding the identity mutex.
	 *
	 * @param string $key Durable record key.
	 * @throws WP_Exception If the scheduler rejects the action.
	 */
	private function enqueue( string $key ): void {
		$record = $this->store->get( $key );
		if ( null === $record ) {
			return;
		}
		$args      = array( 'key' => $key );
		$group     = 'wpwf-stacks';
		$ids       = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'args'     => $args,
				'group'    => $group,
				'status'   => array( ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ),
				'orderby'  => 'action_id',
				'order'    => 'ASC',
				'per_page' => 1,
			),
			'ids'
		);
		$action_id = empty( $ids ) ? as_schedule_single_action( max( $this->now(), $record['due'] ), self::HOOK, $args, $group ) : (int) reset( $ids );
		if ( 0 === $action_id ) {
			throw new WP_Exception( 'webhook_stack_schedule_failed' );
		}
		if ( 0 === $record['delivery_id'] ) {
			// Scheduler storage hooks may emit another event in this same PHP request.
			$record = $this->store->get( $key );
			if ( null !== $record && 0 === $record['delivery_id'] ) {
				$record['delivery_id'] = $action_id;
				$this->store->put( $key, $record );
			}
		}
	}

	/**
	 * Send at most one attempt per stack concurrently; never reopen a sealed window.
	 *
	 * @param string $key Durable record key from the scheduler.
	 * @throws Throwable If preparing or sending this attempt fails.
	 */
	public function process( string $key ): void {
		$this->processed_keys[ $key ] = true;
		$this->store->locked(
			'send:' . $key,
			function () use ( $key ): void {
				$record = $this->store->locked(
					$this->identity( $key ),
					function () use ( $key ): ?array {
						$record = $this->store->get( $key );
						if ( null === $record || $record['due'] > $this->now() ) {
							return null;
						}
						$this->enqueue( $key );
						$record = $this->store->get( $key );
						if ( null === $record ) {
							return null;
						}
						// Checkpoint before consumer filters: fatal preparation failures must consume a finite budget.
						$previous_attempt = $record['attempt'];
						++$record['attempt'];
						$record['due'] = $this->now() + 300;
						$this->store->put( $key, $record );
						try {
							$webhook = $this->webhook( $record );
							if ( $previous_attempt > $webhook->get_max_retries() ) {
								throw new WP_Exception( 'webhook_stack_attempts_exhausted' );
							}
							if ( null === $record['body'] ) {
								$latest         = $record['events'][ count( $record['events'] ) - 1 ];
								$body           = $this->prepare_body( $record );
								$record['body'] = $this->dispatcher->seal_stacked_body( $body, $record['entity'], $record['id'], $latest['payload'], $webhook );
							}
							// Successful preparation replaces the fallback delay with configured backoff.
							$record['due'] = $this->now() + $webhook->calculate_retry_delay( $record['attempt'] );
						} catch ( Throwable $error ) {
							$this->store->delete( $key );
							throw $error;
						}
						$this->store->put( $key, $record );
						return $record;
					}
				);
				if ( null === $record ) {
					return;
				}
				try {
					$headers = $record['headers'];
					if ( 1 < $record['attempt'] ) {
						$headers['wpwf-retry-count'] = $record['attempt'] - 1;
					}
					$this->dispatcher->process_stacked_webhook(
						$record['url'],
						$record['entity'],
						$record['id'],
						(string) $record['body'],
						$headers,
						array(
							'wpwf-delivery-id'      => (string) $record['delivery_id'],
							'wpwf-window-opened-at' => gmdate( 'Y-m-d\TH:i:s\Z', $record['opened_at'] ),
							'wpwf-window-closed-at' => gmdate( 'Y-m-d\TH:i:s\Z', $record['scheduled_for'] ),
						)
					);
				} catch ( Throwable $error ) {
					$this->store->locked(
						$this->identity( $key ),
						function () use ( $key, $record, $error ): void {
							if ( ! ( $error instanceof WP_Exception ) || 'webhook_delivery_failed' !== $error->getMessage() || $record['attempt'] > $this->webhook( $record )->get_max_retries() ) {
								$this->store->delete( $key );
							}
						}
					);
					throw $error;
				}
				$this->store->locked( $this->identity( $key ), fn() => $this->store->delete( $key ) );
			},
			0
		);
	}

	/**
	 * Flatten events while preserving already accepted pre-upgrade field collisions.
	 *
	 * @param array<string,mixed> $record Stored stack.
	 * @phpstan-param StackRecord $record
	 * @return array<string,mixed>
	 */
	private function prepare_body( array $record ): array {
		$events = array();
		$legacy = false;
		foreach ( $record['events'] as $event ) {
			$legacy   = $legacy || array_key_exists( 'timestamp', $event['payload'] ) || array_key_exists( 'aggregated_events', $event['payload'] );
			$events[] = $event['payload'] + array( 'timestamp' => $event['timestamp'] );
		}
		$latest = $record['events'][ count( $record['events'] ) - 1 ];
		if ( ! $legacy ) {
			return $events[ count( $events ) - 1 ] + array( 'aggregated_events' => $events );
		}

		// A previous version accepted these names; never overwrite them during upgrade.
		$body     = $latest['payload'];
		$metadata = array(
			'schema_version' => 2,
			'delivery_id'    => $record['delivery_id'],
			'window'         => array(
				'opened_at'     => gmdate( 'Y-m-d\TH:i:s\Z', $record['opened_at'] ),
				'scheduled_for' => gmdate( 'Y-m-d\TH:i:s\Z', $record['scheduled_for'] ),
			),
			'timestamp'      => $latest['timestamp'],
		);
		if ( array_key_exists( '_wpwf', $body ) || array_key_exists( 'aggregated_events', $body ) ) {
			$metadata['schema_version'] = 1;
			$body                       = $metadata + array( 'payload' => $latest['payload'] );
		} else {
			$body['_wpwf'] = $metadata;
		}
		$body['aggregated_events'] = $record['events'];
		return $body;
	}

	/**
	 * Resolve current configuration without persisting objects or callbacks.
	 *
	 * @param array<string,mixed> $record Stored stack.
	 * @phpstan-param StackRecord $record
	 * @return Webhook
	 * @throws WP_Exception If the owning webhook is no longer registered.
	 */
	private function webhook( array $record ): Webhook {
		$webhook = Webhook_Registry::instance()->get( (string) ( $record['headers']['wpwf-webhook-name'] ?? '' ) );
		if ( null === $webhook ) {
			throw new WP_Exception( 'webhook_not_found' );
		}
		return $webhook;
	}

	/**
	 * Recover the mutex namespace without reading mutable payload state.
	 *
	 * @param string $key Durable record key.
	 * @return string
	 */
	private function identity( string $key ): string {
		return explode( ':', $key, 2 )[0];
	}

	/**
	 * Schedule retries after Action Scheduler records the preceding attempt as finished.
	 *
	 * @param int $action_id Finished scheduler attempt.
	 */
	public function after_action( int $action_id ): void {
		if ( empty( $this->processed_keys ) ) {
			return;
		}
		try {
			$action = ActionScheduler::store()->fetch_action( $action_id );
			$key    = (string) ( $action->get_args()['key'] ?? '' );
			if ( self::HOOK !== $action->get_hook() || ! isset( $this->processed_keys[ $key ] ) ) {
				return;
			}
			unset( $this->processed_keys[ $key ] );
			$this->store->locked( $this->identity( $key ), fn() => $this->enqueue( $key ) );
		} catch ( Throwable $error ) {
			// wp_trigger_error() can itself throw under the scheduler's error handler and abort its batch.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational failure must be visible without throwing from a completion callback.
			error_log( 'WPWF stack recovery deferred: ' . $error->getMessage() );
		}
	}

	/**
	 * Retain recovery after interrupted work or a completed database-fallback wake-up.
	 *
	 * @param int $action_id Finished recovery action.
	 */
	public function after_recovery( int $action_id ): void {
		if ( 'action_scheduler_completed_action' === current_filter() && Stack_Store::is_supported() ) {
			return;
		}
		try {
			if ( self::RECOVERY_HOOK !== ActionScheduler::store()->fetch_action( $action_id )->get_hook() ) {
				return;
			}
			if ( ! Stack_Store::is_supported() ) {
				// Only retain a wake-up while a drop-in is active; never read or mutate stack payloads here.
				$this->ensure_recovery_action( true );
				return;
			}
			$this->store->locked(
				'recovery',
				function (): void {
					if ( ! empty( $this->store->pending_keys() ) ) {
						$this->ensure_recovery_action();
					}
				}
			);
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Failure hooks must not abort the scheduler cleanup batch.
			error_log( 'WPWF recovery restart failed: ' . $error->getMessage() );
		}
	}

	/**
	 * Arm a future pass under the mutex, or use native uniqueness during database fallback.
	 *
	 * @param bool $unique Use scheduler uniqueness when the database cannot provide our lock.
	 * @throws WP_Exception If recovery cannot be scheduled before accepting work.
	 */
	private function ensure_recovery_action( bool $unique = false ): void {
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::RECOVERY_HOOK,
				'group'    => 'wpwf',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		if ( empty( $pending ) && 0 === as_schedule_single_action( time() + 300, self::RECOVERY_HOOK, array(), 'wpwf', $unique ) ) {
			throw new WP_Exception( 'webhook_stack_recovery_schedule_failed' );
		}
	}

	/** Recover at most 100 records per pass and retire when no accepted work remains. */
	public function recover(): void {
		if ( ! Stack_Store::is_supported() ) {
			// Re-arm after completion: a running action prevents its unique successor being stored.
			return;
		}
		$this->store->locked(
			'recovery-sweep',
			function (): void {
				// Schedule the successor first so interruption of this pass does not strand records.
				$this->store->locked( 'recovery', fn() => $this->ensure_recovery_action() );
				$keys = $this->store->pending_keys( (int) get_option( 'wpwf_recovery_cursor', 0 ) );
				foreach ( $keys as $key ) {
					try {
						$this->store->locked( $this->identity( $key ), fn() => $this->enqueue( $key ), 0 );
					} catch ( Throwable $error ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Continue recovering other records even under Action Scheduler's throwing error handler.
						error_log( 'WPWF stack recovery deferred: ' . $error->getMessage() );
					}
				}
				update_option( 'wpwf_recovery_cursor', empty( $keys ) ? 0 : max( array_keys( $keys ) ), false );
				// Never hold the recovery mutex while waiting on an identity mutex.
				$this->store->locked(
					'recovery',
					function (): void {
						if ( empty( $this->store->pending_keys() ) ) {
							as_unschedule_all_actions( self::RECOVERY_HOOK, array(), 'wpwf' );
							delete_option( 'wpwf_recovery_cursor' );
						}
					}
				);
			},
			0
		);
	}
}
