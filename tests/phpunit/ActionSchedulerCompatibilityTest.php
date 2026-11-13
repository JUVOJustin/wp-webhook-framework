<?php
/**
 * Exercises the scheduler store and runner used in production.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

use juvo\WP_Webhook_Framework\Service_Provider;

/**
 * Pins the integration contracts across supported scheduler versions.
 */
final class ActionSchedulerCompatibilityTest extends WPWF_Webhook_Test_Case {

	/**
	 * Large payloads live outside the scheduler while stable identities coalesce.
	 */
	public function test_large_payload_is_deduplicated_without_merging_destinations(): void {
		$dispatcher = Service_Provider::get_dispatcher();
		$url        = $this->get_receiver_webhook_url( 'compat-large' );
		$payload    = array( 'content' => str_repeat( 'x', 1000 ) );
		$headers    = array( 'wpwf-webhook-name' => 'custom_primary' );

		$dispatcher->schedule( 'update', 'custom', 'large', $url, $payload, $headers );
		$dispatcher->schedule( 'update', 'custom', 'large', $url, array( 'changed' => true ), $headers );
		$dispatcher->schedule( 'update', 'custom', 'large', $this->get_receiver_webhook_url( 'compat-other' ), $payload, $headers );

		$actions = $this->get_scheduled_webhook_actions();
		$this->assertCount( 2, $actions );
		$this->assertSame( array( 'changed' => true ), $actions[0]['payload'] );
	}

	/**
	 * The real runner must persist completion and allow a later event with the same identity.
	 */
	public function test_runner_completes_delivery_and_allows_rescheduling(): void {
		do_action( 'wpwf_test_custom_event', 'compat-runner', array( 'status' => 'ready' ) );
		$actions   = $this->get_scheduled_actions_by_webhook_name( 'custom_primary' );
		$action_id = (int) $actions[0]['action_id'];

		ActionScheduler_QueueRunner::instance()->process_action( $action_id, 'wpwf-test' );

		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( $action_id ) );
		$request = $this->get_captured_request_by_webhook_name( 'custom_primary' );
		$this->assertSame( 'compat-runner', $request['body']['id'] );

		do_action( 'wpwf_test_custom_event', 'compat-runner', array( 'status' => 'updated' ) );
		$this->assertCount( 1, $this->get_scheduled_actions_by_webhook_name( 'custom_primary' ) );
	}
	/**
	 * A store rejection must not be reported as successfully queued work.
	 */
	public function test_scheduler_rejection_is_reported(): void {
		$filter = static fn(): int => 0;
		add_filter( 'pre_as_schedule_single_action', $filter );

		try {
			$this->expectException( \WP_Exception::class );
			$this->expectExceptionMessage( 'webhook_schedule_failed' );
			Service_Provider::get_dispatcher()->schedule( 'trigger', 'custom', 'rejected', $this->get_receiver_webhook_url( 'rejected' ) );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $filter );
			$this->assertCount( 0, $this->get_scheduled_webhook_actions() );
		}
	}

	/**
	 * Many distinct identities must each retain one wake-up action.
	 */
	public function test_coalescing_keeps_distinct_identities(): void {
		$dispatcher = Service_Provider::get_dispatcher();
		$url        = $this->get_receiver_webhook_url( 'compat-pages' );
		for ( $id = 1; $id <= 26; ++$id ) {
			$dispatcher->schedule( 'update', 'custom', $id, $url );
		}
		$dispatcher->schedule( 'update', 'custom', 26, $url );
		$this->assertCount( 26, $this->get_scheduled_webhook_actions() );
	}

	/**
	 * Loaded functions must not allow dispatch before the scheduler store is ready.
	 */
	public function test_uninitialized_store_is_rejected(): void {
		// Reproduce the plugins_loaded-to-init window without reloading WordPress.
		$property = new ReflectionProperty( ActionScheduler::class, 'data_store_initialized' );
		$property->setAccessible( true );
		$initialized = $property->getValue();
		$property->setValue( null, false );
		try {
			$this->expectException( \WP_Exception::class );
			$this->expectExceptionMessage( 'action_scheduler_not_active' );
			Service_Provider::get_dispatcher()->schedule( 'trigger', 'custom', 'early', $this->get_receiver_webhook_url( 'early' ) );
		} finally {
			$property->setValue( null, $initialized );
		}
	}
}
