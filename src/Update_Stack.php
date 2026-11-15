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
		ksort( $headers );
		$identity = hash( 'sha256', (string) wp_json_encode( array( $url, $headers, $entity, (string) $id, 'meta' === $entity ? ( $payload['meta_type'] ?? '' ) : '' ) ) );
		$this->store->locked(
			$identity,
			function () use ( $identity, $url, $entity, $id, $payload, $headers ): void {
				$now    = $this->now();
				$key    = $this->store->latest_key( $identity );
				$record = null === $key ? null : $this->store->get( $key );
				if ( null === $record || null !== $record['body'] || $record['scheduled_for'] <= $now ) {
					// This private storage token exists before enqueue; the public ID is the first action ID.
					$key    = $identity . ':' . wp_generate_uuid4();
					$record = array(
						'url'           => $url,
						'entity'        => $entity,
						'id'            => $id,
						'headers'       => $headers,
						'events'        => array(),
						'opened_at'     => $now,
						'scheduled_for' => $now + 60,
						'due'           => $now + 60,
						'attempt'       => 0,
						'delivery_id'   => 0,
						'body'          => null,
					);
				}
				$record['events'][] = array(
					'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
					'payload'   => $payload,
				);
				$json               = wp_json_encode( $record );
				if ( false === $json || self::MAX_EVENTS < count( $record['events'] ) || self::MAX_BYTES < strlen( $json ) ) {
					throw new WP_Exception( 'webhook_stack_capacity_exceeded' );
				}
				$this->store->put( $key, $record );
				$this->enqueue( $key );
			}
		);
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
		$group     = 'wpwf-stack-' . hash( 'sha256', $key );
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
		$action_id = empty( $ids ) ? as_schedule_single_action( max( $this->now(), $record['due'] ), self::HOOK, $args, $group, true ) : (int) reset( $ids );
		if ( 0 === $action_id ) {
			throw new WP_Exception( 'webhook_stack_schedule_failed' );
		}
		if ( 0 === $record['delivery_id'] ) {
			$record['delivery_id'] = $action_id;
			$this->store->put( $key, $record );
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
						try {
							$webhook = $this->webhook( $record );
							if ( $record['attempt'] > $webhook->get_max_retries() ) {
								throw new WP_Exception( 'webhook_stack_attempts_exhausted' );
							}
							if ( null === $record['body'] ) {
								$latest         = $record['events'][ count( $record['events'] ) - 1 ];
								$body           = array(
									'schema_version'    => 1,
									'delivery_id'       => $record['delivery_id'],
									'window'            => array(
										'opened_at'     => gmdate( 'Y-m-d\TH:i:s\Z', $record['opened_at'] ),
										'scheduled_for' => gmdate( 'Y-m-d\TH:i:s\Z', $record['scheduled_for'] ),
									),
									'timestamp'         => $latest['timestamp'],
									'payload'           => $latest['payload'],
									'aggregated_events' => $record['events'],
								);
								$record['body'] = $this->dispatcher->seal_stacked_body( $body, $record['entity'], $record['id'], $latest['payload'], $webhook );
							}
							// Plan recovery before HTTP; a hard exit must consume a bounded retry budget.
							++$record['attempt'];
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
					$this->dispatcher->process_stacked_webhook( $record['url'], $record['entity'], $record['id'], (string) $record['body'], $headers );
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
	 * Schedule retries after Action Scheduler releases its running uniqueness slot.
	 *
	 * @param int $action_id Finished scheduler attempt.
	 */
	public function after_action( int $action_id ): void {
		if ( empty( $this->processed_keys ) ) {
			return;
		}
		$action = ActionScheduler::store()->fetch_action( $action_id );
		$key    = (string) ( $action->get_args()['key'] ?? '' );
		if ( self::HOOK !== $action->get_hook() || ! isset( $this->processed_keys[ $key ] ) ) {
			return;
		}
		unset( $this->processed_keys[ $key ] );
		$this->store->locked( $this->identity( $key ), fn() => $this->enqueue( $key ) );
	}

	/** Keep a bounded recovery pass scheduled while this framework is registered. */
	public function register_recovery(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ActionScheduler::is_initialized() && ! as_has_scheduled_action( self::RECOVERY_HOOK, array(), 'wpwf' ) ) {
			if ( 0 === as_schedule_recurring_action( time() + 60, 60, self::RECOVERY_HOOK, array(), 'wpwf', true ) && ! as_has_scheduled_action( self::RECOVERY_HOOK, array(), 'wpwf' ) ) {
				wp_trigger_error( __METHOD__, 'webhook_stack_recovery_schedule_failed' );
			}
		}
	}

	/** Visit at most 100 surviving stacks; a cursor prevents a backlog starving newer keys. */
	public function recover(): void {
		$this->store->locked(
			'recovery',
			function (): void {
				$keys = $this->store->pending_keys( (int) get_option( 'wpwf_recovery_cursor', 0 ) );
				foreach ( $keys as $key ) {
					try {
						$this->store->locked( $this->identity( $key ), fn() => $this->enqueue( $key ), 0 );
					} catch ( WP_Exception $error ) {
						wp_trigger_error( __METHOD__, $error->getMessage() );
					}
				}
				update_option( 'wpwf_recovery_cursor', empty( $keys ) ? 0 : max( array_keys( $keys ) ), false );
			},
			0
		);
	}
}
