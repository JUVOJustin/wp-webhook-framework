<?php
/**
 * Covers retries, backoff scheduling, and URL blocking behavior.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

use juvo\WP_Webhook_Framework\Delivery_Mode;
use juvo\WP_Webhook_Framework\Failure;
use juvo\WP_Webhook_Framework\Service_Provider;
use juvo\WP_Webhook_Framework\Webhook;

/**
 * Verifies failure handling behavior for scheduled and immediate delivery paths.
 */
final class WebhookFailureHandlingTest extends WPWF_Webhook_Test_Case {

	/**
	 * Ensures failed scheduled deliveries queue retries with increasing retry metadata.
	 */
	public function test_scheduled_failures_schedule_retries_with_backoff(): void {
		$webhook = $this->register_failure_webhook(
			'backoff',
			$this->get_receiver_webhook_url( 'failure-backoff', 'fail' ),
			Delivery_Mode::SCHEDULED,
			2,
			99
		);

		$retry_base_filter = static function ( int $base_time, string $webhook_name ) use ( $webhook ): int {
			if ( $webhook->get_name() !== $webhook_name ) {
				return $base_time;
			}

			return 3;
		};

		add_filter( 'wpwf_retry_base_time', $retry_base_filter, 10, 3 );

		try {
			$webhook->trigger_event( 'job-backoff' );

			$initial_actions = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() );
			$this->assertCount( 1, $initial_actions );

			$this->run_scheduled_webhooks( true );

			$first_retry_actions = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() );
			$this->assertCount( 1, $first_retry_actions );
			$this->assertSame( 1, (int) $first_retry_actions[0]['headers']['wpwf-retry-count'] );

			$this->run_scheduled_webhooks( true );

			$second_retry_actions = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() );
			$this->assertCount( 1, $second_retry_actions );
			$this->assertSame( 2, (int) $second_retry_actions[0]['headers']['wpwf-retry-count'] );
			$this->assertArrayHasKey( 'scheduled_timestamp', $first_retry_actions[0] );
			$this->assertArrayHasKey( 'scheduled_timestamp', $second_retry_actions[0] );
			$this->assertGreaterThan(
				(int) $first_retry_actions[0]['scheduled_timestamp'],
				(int) $second_retry_actions[0]['scheduled_timestamp']
			);

			$this->run_scheduled_webhooks( true );

			$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() ) );
			$this->assertCount( 3, $this->get_captured_requests() );
		} finally {
			remove_filter( 'wpwf_retry_base_time', $retry_base_filter, 10 );
		}
	}

	/**
	 * Ensures repeated failed events block the destination URL at the configured threshold.
	 */
	public function test_failure_tracking_blocks_url_after_threshold(): void {
		$webhook = $this->register_failure_webhook(
			'blocked',
			$this->get_receiver_webhook_url( 'failure-blocked', 'fail' ),
			Delivery_Mode::SCHEDULED,
			0,
			2
		);

		$webhook->trigger_event( 'job-block-1' );
		$this->run_scheduled_webhooks( true );

		$first_state = Failure::from_transient( $webhook->get_webhook_url() );
		$this->assertSame( 1, $first_state->get_count() );
		$this->assertFalse( $first_state->is_blocked() );

		$webhook->trigger_event( 'job-block-2' );
		$this->run_scheduled_webhooks( true );

		$second_state = Failure::from_transient( $webhook->get_webhook_url() );
		$this->assertSame( 2, $second_state->get_count() );
		$this->assertTrue( $second_state->is_blocked() );

		$this->reset_captured_requests();

		$this->run_ignoring_emit_warning(
			static function () use ( $webhook ): void {
				$webhook->trigger_event( 'job-block-3' );
			}
		);

		$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() ) );
		$this->assertCount( 0, $this->get_captured_requests() );
	}

	/**
	 * Ensures immediate failures still queue asynchronous retries.
	 */
	public function test_immediate_failure_schedules_async_retry(): void {
		$webhook = $this->register_failure_webhook(
			'immediate',
			$this->get_receiver_webhook_url( 'failure-immediate', 'fail' ),
			Delivery_Mode::IMMEDIATE,
			1,
			99
		);

		$retry_base_filter = static function ( int $base_time, string $webhook_name ) use ( $webhook ): int {
			if ( $webhook->get_name() !== $webhook_name ) {
				return $base_time;
			}

			return 2;
		};

		add_filter( 'wpwf_retry_base_time', $retry_base_filter, 10, 3 );

		try {
			$this->run_ignoring_emit_warning(
				static function () use ( $webhook ): void {
					$webhook->trigger_event( 'job-immediate-retry' );
				}
			);

			$this->assertCount( 1, $this->get_captured_requests() );

			$retry_actions = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() );
			$this->assertCount( 1, $retry_actions );
			$this->assertSame( 1, (int) $retry_actions[0]['headers']['wpwf-retry-count'] );

			$this->run_scheduled_webhooks( true );

			$this->assertCount( 2, $this->get_captured_requests() );
			$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() ) );
		} finally {
			remove_filter( 'wpwf_retry_base_time', $retry_base_filter, 10 );
		}
	}

	/**
	 * A rejected retry must still update blocking state and notify failure observers.
	 */
	public function test_rejected_retry_still_records_delivery_failure(): void {
		$webhook = $this->register_failure_webhook(
			'rejected-retry',
			$this->get_receiver_webhook_url( 'rejected-retry', 'fail' ),
			Delivery_Mode::SCHEDULED,
			1,
			99
		);
		$webhook->trigger_event( 'rejected-retry' );
		$actions = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() );
		$filter  = static fn(): int => 0;
		add_filter( 'pre_as_schedule_single_action', $filter );
		try {
			ActionScheduler_QueueRunner::instance()->process_action( (int) $actions[0]['action_id'], 'wpwf-test' );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $filter );
		}
		$this->assertSame( ActionScheduler_Store::STATUS_FAILED, ActionScheduler::store()->get_status( (int) $actions[0]['action_id'] ) );
		$this->assertSame( 1, Failure::from_transient( $webhook->get_webhook_url() )->get_count() );
		$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() ) );
	}

	/**
	 * Register a failure scenario webhook with unique state and URL.
	 *
	 * @param string        $suffix                  Unique test suffix.
	 * @param string        $url                     Destination URL.
	 * @param Delivery_Mode $delivery_mode           Delivery mode under test.
	 * @param int           $max_retries             Maximum retries.
	 * @param int           $max_consecutive_failures Failure blocking threshold.
	 * @return Failure_Test_Webhook
	 */
	private function register_failure_webhook( string $suffix, string $url, Delivery_Mode $delivery_mode, int $max_retries, int $max_consecutive_failures ): Failure_Test_Webhook {
		$name = 'failure_test_' . $suffix . '_' . str_replace( '.', '', uniqid( '', true ) );

		$webhook = new Failure_Test_Webhook( $name, $url, $delivery_mode );
		$webhook->max_retries( $max_retries );
		$webhook->max_consecutive_failures( $max_consecutive_failures );

		Service_Provider::get_registry()->register( $webhook );

		return $webhook;
	}

	/**
	 * Execute a callback while ignoring framework warning emissions for delivery failures.
	 *
	 * @param callable $callback The callback to execute.
	 */
	private function run_ignoring_emit_warning( callable $callback ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Tests assert the documented warning path.
		set_error_handler(
			static function ( int $error_level, string $message ): bool {
				if ( E_USER_WARNING !== $error_level ) {
					return false;
				}

				return str_contains( $message, 'Failed to emit webhook' );
			}
		);

		try {
			$callback();
		} finally {
			restore_error_handler();
		}
	}
}

/**
 * Emits fixture payloads for failure handling tests.
 */
final class Failure_Test_Webhook extends Webhook {

	/**
	 * Configure URL and delivery mode for this fixture webhook.
	 *
	 * @param string        $name          Unique webhook name.
	 * @param string        $url           Delivery URL.
	 * @param Delivery_Mode $delivery_mode Delivery mode under test.
	 */
	public function __construct( string $name, string $url, Delivery_Mode $delivery_mode ) {
		parent::__construct( $name );

		$this->webhook_url( $url );
		$this->delivery_mode( $delivery_mode );
	}

	/**
	 * No WordPress hooks are needed for this fixture webhook.
	 */
	public function init(): void {
	}

	/**
	 * Emit a deterministic payload for assertions.
	 *
	 * @param string $reference Stable ID used by assertions.
	 */
	public function trigger_event( string $reference ): void {
		$this->emit(
			'trigger',
			'failure_test',
			$reference,
			array(
				'reference' => $reference,
			)
		);
	}
}
