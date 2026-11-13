<?php
/**
 * Covers every WP_Exception throw site in the dispatcher.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

use juvo\WP_Webhook_Framework\Delivery_Mode;
use juvo\WP_Webhook_Framework\Dispatcher;
use juvo\WP_Webhook_Framework\Failure;
use juvo\WP_Webhook_Framework\Service_Provider;
use juvo\WP_Webhook_Framework\Webhook;

/**
 * Verifies that dispatcher failure paths surface as catchable WP_Exception instances.
 *
 * Action Scheduler only records an action as failed when the callback throws, so the
 * exception contract is load-bearing for the queue. These tests pin both the exception
 * type and the message identifier used at each throw site.
 */
final class DispatcherExceptionTest extends WPWF_Webhook_Test_Case {

	/**
	 * Dispatcher under test.
	 *
	 * @var Dispatcher
	 */
	private Dispatcher $dispatcher;

	/**
	 * Registered fixture webhook.
	 *
	 * @var Exception_Test_Webhook
	 */
	private Exception_Test_Webhook $webhook;

	/**
	 * Register a uniquely named fixture webhook for each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->dispatcher = Service_Provider::get_dispatcher();
		$this->webhook    = $this->register_exception_webhook( 'default', $this->get_receiver_webhook_url( 'exception-default' ) );
	}

	/**
	 * Guards the WordPress 6.7 baseline required by every throw site.
	 *
	 * WP_Exception ships with WordPress core as of 6.7.0. On older releases every
	 * `throw new WP_Exception(...)` in the dispatcher becomes a fatal "class not found"
	 * error that `Webhook::emit()` cannot catch.
	 */
	public function test_core_wp_exception_class_is_available(): void {
		$this->assertTrue(
			class_exists( '\WP_Exception' ),
			'WP_Exception is missing. The framework requires WordPress 6.7 or newer.'
		);
		$this->assertTrue( is_subclass_of( '\WP_Exception', '\Exception' ) );
	}

	/**
	 * Ensures an unset URL aborts scheduling instead of queueing an undeliverable action.
	 */
	public function test_schedule_throws_when_webhook_url_is_not_set(): void {
		$this->expectException( \WP_Exception::class );
		$this->expectExceptionMessage( 'webhook_url_not_set' );

		$this->dispatcher->schedule( 'trigger', 'exception_test', 'no-url', '', array( 'reference' => 'no-url' ) );
	}

	/**
	 * Ensures the same guard applies to the immediate delivery path.
	 */
	public function test_dispatch_immediately_throws_when_webhook_url_is_not_set(): void {
		$this->expectException( \WP_Exception::class );
		$this->expectExceptionMessage( 'webhook_url_not_set' );

		$this->dispatcher->dispatch_immediately( 'trigger', 'exception_test', 'no-url-immediate', '', array( 'reference' => 'no-url-immediate' ) );
	}

	/**
	 * Ensures a blocked destination is rejected before an action is queued.
	 */
	public function test_schedule_throws_when_url_is_blocked(): void {
		$url = $this->get_receiver_webhook_url( 'exception-blocked-schedule' );
		$this->block_url( $url );

		try {
			$this->dispatcher->schedule( 'trigger', 'exception_test', 'blocked', $url, array( 'reference' => 'blocked' ) );
			$this->fail( 'Expected a WP_Exception for a blocked webhook URL.' );
		} catch ( \WP_Exception $exception ) {
			$this->assertSame( 'webhook_url_blocked', $exception->getMessage() );
		}

		$this->assertCount( 0, $this->get_scheduled_webhook_actions() );
	}

	/**
	 * Ensures a payload filter returning a non-array value is rejected.
	 */
	public function test_schedule_throws_when_payload_filter_returns_non_array(): void {
		$filter = static fn(): string => 'not-an-array';
		add_filter( 'wpwf_payload', $filter, 99 );

		try {
			$this->dispatcher->schedule(
				'trigger',
				'exception_test',
				'invalid-payload',
				$this->get_receiver_webhook_url( 'exception-invalid-payload' ),
				array( 'reference' => 'invalid-payload' )
			);
			$this->fail( 'Expected a WP_Exception for a non-array payload.' );
		} catch ( \WP_Exception $exception ) {
			$this->assertSame( 'webhook_payload_invalid', $exception->getMessage() );
		} finally {
			remove_filter( 'wpwf_payload', $filter, 99 );
		}

		$this->assertCount( 0, $this->get_scheduled_webhook_actions() );
	}

	/**
	 * Ensures a filter that empties a previously populated payload is treated as an error.
	 */
	public function test_schedule_throws_when_payload_filter_empties_a_populated_payload(): void {
		$filter = static fn(): array => array();
		add_filter( 'wpwf_payload', $filter, 99 );

		try {
			$this->dispatcher->schedule(
				'trigger',
				'exception_test',
				'empty-payload',
				$this->get_receiver_webhook_url( 'exception-empty-payload' ),
				array( 'reference' => 'empty-payload' )
			);
			$this->fail( 'Expected a WP_Exception for an emptied payload.' );
		} catch ( \WP_Exception $exception ) {
			$this->assertSame( 'webhook_payload_empty', $exception->getMessage() );
		} finally {
			remove_filter( 'wpwf_payload', $filter, 99 );
		}

		$this->assertCount( 0, $this->get_scheduled_webhook_actions() );
	}

	/**
	 * Ensures an opt-out payload filter cancels dispatch without raising an exception.
	 */
	public function test_schedule_skips_silently_when_payload_filter_opts_out(): void {
		$filter = static fn(): bool => false;
		add_filter( 'wpwf_payload', $filter, 99 );

		try {
			$this->dispatcher->schedule(
				'trigger',
				'exception_test',
				'opt-out',
				$this->get_receiver_webhook_url( 'exception-opt-out' ),
				array( 'reference' => 'opt-out' )
			);
		} finally {
			remove_filter( 'wpwf_payload', $filter, 99 );
		}

		$this->assertCount( 0, $this->get_scheduled_webhook_actions() );
	}

	/**
	 * Ensures a queued action for an unregistered webhook fails loudly.
	 *
	 * This happens when a plugin that owns a webhook is deactivated while its
	 * actions are still pending in the queue.
	 */
	public function test_process_scheduled_webhook_throws_when_webhook_is_not_registered(): void {
		$this->expectException( \WP_Exception::class );
		$this->expectExceptionMessage( 'webhook_not_found' );

		$this->dispatcher->process_scheduled_webhook(
			$this->get_receiver_webhook_url( 'exception-unregistered' ),
			'trigger',
			'exception_test',
			'unregistered',
			array( 'reference' => 'unregistered' ),
			array( 'wpwf-webhook-name' => 'wpwf_missing_webhook_name' )
		);
	}

	/**
	 * Ensures a queued action whose URL became blocked after scheduling fails loudly.
	 */
	public function test_process_scheduled_webhook_throws_when_url_is_blocked(): void {
		$url = $this->get_receiver_webhook_url( 'exception-blocked-delivery' );
		$this->block_url( $url );

		$this->expectException( \WP_Exception::class );
		$this->expectExceptionMessage( 'webhook_url_blocked' );

		$this->dispatcher->process_scheduled_webhook(
			$url,
			'trigger',
			'exception_test',
			'blocked-delivery',
			array( 'reference' => 'blocked-delivery' ),
			$this->webhook->get_headers()
		);
	}

	/**
	 * Ensures a non-200 response throws so Action Scheduler records the action as failed.
	 */
	public function test_delivery_failure_throws_when_blocking_is_disabled(): void {
		$url     = $this->get_receiver_webhook_url( 'exception-fail-unblocked', 'fail' );
		$webhook = $this->register_exception_webhook( 'unblocked', $url );
		$webhook->max_consecutive_failures( 0 );

		try {
			$this->dispatcher->process_scheduled_webhook(
				$url,
				'trigger',
				'exception_test',
				'fail-unblocked',
				array( 'reference' => 'fail-unblocked' ),
				$webhook->get_headers()
			);
			$this->fail( 'Expected a WP_Exception for a failed webhook delivery.' );
		} catch ( \WP_Exception $exception ) {
			$this->assertSame( 'webhook_delivery_failed', $exception->getMessage() );
		}

		$this->assertCount( 1, $this->get_captured_requests() );

		// Blocking disabled means no failure state is accumulated.
		$state = Failure::from_transient( $url );
		$this->assertSame( 0, $state->get_count() );
		$this->assertFalse( $state->is_blocked() );
	}

	/**
	 * Ensures the throw still happens on the branch that tracks and blocks failures.
	 */
	public function test_delivery_failure_throws_and_blocks_url_at_threshold(): void {
		$url     = $this->get_receiver_webhook_url( 'exception-fail-blocking', 'fail' );
		$webhook = $this->register_exception_webhook( 'blocking', $url );
		$webhook->max_consecutive_failures( 1 );

		try {
			$this->dispatcher->process_scheduled_webhook(
				$url,
				'trigger',
				'exception_test',
				'fail-blocking',
				array( 'reference' => 'fail-blocking' ),
				$webhook->get_headers()
			);
			$this->fail( 'Expected a WP_Exception for a failed webhook delivery.' );
		} catch ( \WP_Exception $exception ) {
			$this->assertSame( 'webhook_delivery_failed', $exception->getMessage() );
		}

		$state = Failure::from_transient( $url );
		$this->assertSame( 1, $state->get_count() );
		$this->assertTrue( $state->is_blocked() );
	}

	/**
	 * Ensures `Webhook::emit()` converts dispatcher exceptions into a warning.
	 *
	 * Callers using the `Webhook` façade must never see an uncaught exception.
	 */
	public function test_emit_converts_dispatcher_exception_into_warning(): void {
		$url     = $this->get_receiver_webhook_url( 'exception-emit-blocked' );
		$webhook = $this->register_exception_webhook( 'emit', $url );
		$webhook->delivery_mode( Delivery_Mode::IMMEDIATE );
		$this->block_url( $url );

		$messages = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Tests assert the documented warning path.
		set_error_handler(
			static function ( int $error_level, string $message ) use ( &$messages ): bool {
				if ( E_USER_WARNING !== $error_level ) {
					return false;
				}

				$messages[] = $message;
				return true;
			}
		);

		try {
			$webhook->trigger_event( 'emit-blocked' );
		} finally {
			restore_error_handler();
		}

		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'Failed to emit webhook', $messages[0] );
		$this->assertStringContainsString( 'webhook_url_blocked', $messages[0] );
	}

	/**
	 * Persist a blocked failure state for a URL.
	 *
	 * @param string $url The webhook URL to block.
	 */
	private function block_url( string $url ): void {
		Failure::create_blocked()->save( $url );
	}

	/**
	 * Register a uniquely named fixture webhook.
	 *
	 * @param string $suffix Unique test suffix.
	 * @param string $url    Destination URL.
	 * @return Exception_Test_Webhook
	 */
	private function register_exception_webhook( string $suffix, string $url ): Exception_Test_Webhook {
		$name = 'exception_test_' . $suffix . '_' . str_replace( '.', '', uniqid( '', true ) );

		$webhook = new Exception_Test_Webhook( $name, $url );
		$webhook->max_retries( 0 );

		Service_Provider::get_registry()->register( $webhook );

		return $webhook;
	}
}

/**
 * Minimal fixture webhook used to exercise dispatcher exception paths.
 */
final class Exception_Test_Webhook extends Webhook {

	/**
	 * Configure the fixture endpoint.
	 *
	 * @param string $name Unique webhook name.
	 * @param string $url  Delivery URL.
	 */
	public function __construct( string $name, string $url ) {
		parent::__construct( $name );

		$this->webhook_url( $url );
	}

	/**
	 * No WordPress hooks are needed for this fixture webhook.
	 */
	public function init(): void {
	}

	/**
	 * Emit a deterministic payload through the public façade.
	 *
	 * @param string $reference Stable ID used by assertions.
	 */
	public function trigger_event( string $reference ): void {
		$this->emit(
			'trigger',
			'exception_test',
			$reference,
			array(
				'reference' => $reference,
			)
		);
	}
}
