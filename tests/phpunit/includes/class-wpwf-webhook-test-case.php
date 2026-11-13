<?php
/**
 * Shared helpers for exercising webhook scheduling and delivery.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

/**
 * Provides stable assertions around scheduled actions and dispatched payloads.
 */
abstract class WPWF_Webhook_Test_Case extends WP_UnitTestCase {

	/**
	 * Prepare a clean queue and receiver state before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once \ABSPATH . 'wp-admin/includes/user.php';

		$this->clear_scheduled_webhooks();
		$this->reset_receiver_logs( true );
	}

	/**
	 * Remove queued actions and captured receiver logs after each test.
	 */
	public function tear_down(): void {
		$this->clear_scheduled_webhooks();
		$this->reset_receiver_logs( false );

		parent::tear_down();
	}

	/**
	 * Resolve the receiver base URL used by fixture webhook senders.
	 *
	 * @return string
	 */
	protected function get_receiver_base_url(): string {
		$base_url = getenv( 'WPWF_TEST_RECEIVER_BASE_URL' );
		if ( ! is_string( $base_url ) || '' === $base_url ) {
			$base_url = 'http://tests-wordpress';
		}

		return \untrailingslashit( $base_url );
	}

	/**
	 * Build a fixture receiver webhook URL.
	 *
	 * @param string $target Receiver target identifier.
	 * @param string $mode   Receiver mode (`success` or `fail`).
	 * @return string
	 */
	protected function get_receiver_webhook_url( string $target, string $mode = 'success' ): string {
		return $this->get_receiver_base_url() . '/index.php?rest_route=/wpwf-test/v1/receive/' . rawurlencode( $mode ) . '/' . rawurlencode( $target );
	}

	/**
	 * Returns scheduled webhook actions keyed by Action Scheduler arguments.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_scheduled_webhook_actions(): array {
		$actions = array();
		$store   = $this->get_action_store();

		$action_ids = array();
		foreach ( array( 'wpwf_send_webhook', \juvo\WP_Webhook_Framework\Update_Queue::HOOK ) as $hook ) {
			$action_ids = array_merge(
				$action_ids,
				\as_get_scheduled_actions(
					array(
						'hook'     => $hook,
						'status'   => \ActionScheduler_Store::STATUS_PENDING,
						'orderby'  => 'date',
						'order'    => 'ASC',
						'per_page' => 100,
					),
					'ids'
				)
			);
		}

		foreach ( $action_ids as $action_id ) {
			$action = $store->fetch_action( $action_id );
			$args   = $action->get_args();
			if ( \juvo\WP_Webhook_Framework\Update_Queue::HOOK === $action->get_hook() ) {
				$key    = $args['key'];
				$record = ( new \juvo\WP_Webhook_Framework\Update_Store() )->get( $key );
				if ( null === $record ) {
					continue;
				}
				$args               = $record;
				$args['action']     = 'update';
				$args['update_key'] = $key;
				if ( 0 < $record['attempt'] ) {
					$args['headers']['wpwf-retry-count'] = $record['attempt'];
				}
			}

			if ( ! is_array( $args ) ) {
				continue;
			}

			$schedule = $action->get_schedule();
			if ( is_object( $schedule ) && method_exists( $schedule, 'get_date' ) ) {
				$date = $schedule->get_date();
				if ( $date instanceof \DateTimeInterface ) {
					$args['scheduled_timestamp'] = $date->getTimestamp();
				}
			}

			$args['action_id'] = $action_id;
			$actions[]         = $args;
		}

		return $actions;
	}

	/**
	 * Returns scheduled webhook actions for a single webhook name.
	 *
	 * @param string $webhook_name The webhook identifier.
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_scheduled_actions_by_webhook_name( string $webhook_name ): array {
		$matches = array();

		foreach ( $this->get_scheduled_webhook_actions() as $action ) {
			$headers = $action['headers'] ?? array();
			$name    = is_array( $headers ) ? (string) ( $headers['wpwf-webhook-name'] ?? '' ) : '';

			if ( $webhook_name !== $name ) {
				continue;
			}

			$matches[] = $action;
		}

		return $matches;
	}

	/**
	 * Executes all queued framework webhook actions immediately.
	 *
	 * @param bool $allow_failures Whether delivery failures should be swallowed.
	 * @throws \Throwable If a delivery fails and failures are not allowed.
	 */
	protected function run_scheduled_webhooks( bool $allow_failures = false ): void {
		$actions = $this->get_scheduled_webhook_actions();
		$store   = $this->get_action_store();

		foreach ( $actions as $action ) {
			$action_id     = (int) $action['action_id'];
			$stored_action = $store->fetch_action( $action_id );

			try {
				$stored_action->execute();
				$store->mark_complete( $action_id );
			} catch ( \Throwable $throwable ) {
				if ( method_exists( $store, 'mark_failure' ) ) {
					$store->mark_failure( $action_id );
					do_action( 'action_scheduler_failed_execution', $action_id, $throwable, 'wpwf-test' );
				}

				if ( ! $allow_failures ) {
					throw $throwable;
				}
			} finally {
				$store->delete_action( $action_id );
			}
		}
	}

	/**
	 * Removes all queued framework webhook actions.
	 */
	protected function clear_scheduled_webhooks(): void {
		$store = $this->get_action_store();
		foreach ( array( 'wpwf_send_webhook', \juvo\WP_Webhook_Framework\Update_Queue::HOOK ) as $hook ) {
			foreach ( \as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'per_page' => 100,
				),
				'ids'
			) as $action_id ) {
				$store->delete_action( $action_id );
			}
		}
		$updates = new \juvo\WP_Webhook_Framework\Update_Store();
		foreach ( $updates->pending_keys() as $key ) {
			$updates->delete( $key );
		}
	}

	/**
	 * Resolves the Action Scheduler store used by fixture actions.
	 *
	 * @return object
	 */
	private function get_action_store() {
		$store = \ActionScheduler::store();

		if ( $store instanceof \ActionScheduler_HybridStore ) {
			return new \ActionScheduler_DBStore();
		}

		return $store;
	}

	/**
	 * Clears captured webhook requests between assertion phases.
	 */
	protected function reset_captured_requests(): void {
		$this->reset_receiver_logs( true );
	}

	/**
	 * Returns all intercepted requests for the current test.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_captured_requests(): array {
		return $this->get_receiver_logs();
	}

	/**
	 * Returns the first captured request for a webhook name.
	 *
	 * @param string $webhook_name The webhook identifier.
	 * @return array<string,mixed>
	 */
	protected function get_captured_request_by_webhook_name( string $webhook_name ): array {
		foreach ( $this->get_receiver_logs() as $request ) {
			if ( (string) ( $request['webhook_name'] ?? '' ) === $webhook_name ) {
				return $request;
			}
		}

		$this->fail( sprintf( 'Failed to capture a request for webhook "%s".', $webhook_name ) );

		return array();
	}

	/**
	 * Retrieve webhook request logs from the receiver fixture API.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_receiver_logs(): array {
		$response = \wp_remote_post(
			$this->get_receiver_logs_url(),
			array(
				'timeout' => 10,
			)
		);

		if ( \is_wp_error( $response ) ) {
			$this->fail( 'Failed to load receiver logs: ' . $response->get_error_message() );
			return array();
		}

		$status_code = (int) \wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$this->fail( sprintf( 'Failed to load receiver logs. Status code: %d.', $status_code ) );
			return array();
		}

		$decoded = json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['logs'] ) || ! is_array( $decoded['logs'] ) ) {
			$this->fail( 'Receiver returned invalid logs response.' );
			return array();
		}

		return array_values( $decoded['logs'] );
	}

	/**
	 * Reset receiver logs through the fixture API.
	 *
	 * @param bool $strict Whether failures should fail the current test.
	 */
	private function reset_receiver_logs( bool $strict ): void {
		$response = \wp_remote_post(
			$this->get_receiver_reset_url(),
			array(
				'timeout' => 10,
			)
		);

		if ( \is_wp_error( $response ) ) {
			if ( $strict ) {
				$this->fail( 'Failed to reset receiver logs: ' . $response->get_error_message() );
			}
			return;
		}

		$status_code = (int) \wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code && $strict ) {
			$this->fail( sprintf( 'Failed to reset receiver logs. Status code: %d.', $status_code ) );
		}
	}

	/**
	 * Build the receiver logs endpoint URL.
	 *
	 * @return string
	 */
	private function get_receiver_logs_url(): string {
		return $this->get_receiver_base_url() . '/index.php?rest_route=/wpwf-test/v1/logs';
	}

	/**
	 * Build the receiver logs reset endpoint URL.
	 *
	 * @return string
	 */
	private function get_receiver_reset_url(): string {
		return $this->get_receiver_base_url() . '/index.php?rest_route=/wpwf-test/v1/logs/reset';
	}
}
