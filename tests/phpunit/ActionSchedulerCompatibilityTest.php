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
	 * Extended scheduler arguments must preserve first-pending-payload deduplication.
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
		$this->assertSame( $payload, $actions[0]['payload'] );
	}

	/**
	 * Dependency upgrades must not replace pending content or change the legacy identity.
	 */
	public function test_first_pending_payload_and_headers_survive_for_every_action(): void {
		$dispatcher = Service_Provider::get_dispatcher();
		$url        = $this->get_receiver_webhook_url( 'compat-first' );
		foreach ( array( 'create', 'update', 'delete', 'custom' ) as $action ) {
			$dispatcher->schedule(
				$action,
				'custom',
				'42',
				$url,
				array( 'version' => 'first' ),
				array(
					'wpwf-webhook-name' => 'custom_primary',
					'custom'            => 'first',
				)
			);
			$dispatcher->schedule(
				$action,
				'custom',
				42,
				$url,
				array( 'version' => 'second' ),
				array(
					'wpwf-webhook-name' => 'another',
					'custom'            => 'second',
				)
			);
		}
		$actions = $this->get_scheduled_webhook_actions();
		$this->assertCount( 4, $actions );
		foreach ( $actions as $action ) {
			$this->assertSame( array( 'version' => 'first' ), $action['payload'] );
			$this->assertSame( 'first', $action['headers']['custom'] );
			$this->assertSame( 'custom_primary', $action['headers']['wpwf-webhook-name'] );
		}
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
	public function test_deduplication_searches_beyond_the_first_page(): void {
		$dispatcher = Service_Provider::get_dispatcher();
		$url        = $this->get_receiver_webhook_url( 'compat-pages' );
		for ( $id = 1; $id <= 105; ++$id ) {
			$dispatcher->schedule( 'update', 'custom', 42, $url . '/' . $id );
		}
		$dispatcher->schedule( 'update', 'custom', '42', $url . '/105' );
		$this->assertCount(
			105,
			as_get_scheduled_actions(
				array(
					'hook'     => 'wpwf_send_webhook',
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 200,
				),
				'ids'
			)
		);
	}

	/**
	 * Unrelated queued IDs must not each require a fetch when checking one identity.
	 */
	public function test_numeric_lookup_does_not_hydrate_the_entire_queue(): void {
		global $wpdb;
		$dispatcher = Service_Provider::get_dispatcher();
		$url        = $this->get_receiver_webhook_url( 'compat-query-budget' );
		for ( $id = 1; $id <= 120; ++$id ) {
			$dispatcher->schedule( 'update', 'custom', $id, $url, array( 'large' => str_repeat( 'x', 1000 ) ) );
		}
		$before = $wpdb->num_queries;
		$dispatcher->schedule( 'update', 'custom', '120', $url, array( 'changed' => true ) );
		$this->assertLessThan( 20, $wpdb->num_queries - $before );
		$this->assertCount(
			120,
			as_get_scheduled_actions(
				array(
					'hook'     => 'wpwf_send_webhook',
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 200,
				),
				'ids'
			)
		);
	}

	/**
	 * The fallback must retain escaped string IDs without treating them as SQL patterns.
	 */
	public function test_custom_string_ids_retain_the_first_payload(): void {
		$dispatcher = Service_Provider::get_dispatcher();
		$url        = $this->get_receiver_webhook_url( 'compat-string-ids' );
		foreach ( array( '0042', 'a"b', 'a/b', 'a%b_c', 'über', '9223372036854775808' ) as $id ) {
			$dispatcher->schedule( 'update', 'custom', $id, $url, array( 'version' => 'first' ) );
			$dispatcher->schedule( 'update', 'custom', $id, $url, array( 'version' => 'second' ) );
		}
		$actions = $this->get_scheduled_webhook_actions();
		$this->assertCount( 6, $actions );
		foreach ( $actions as $action ) {
			$this->assertSame( array( 'version' => 'first' ), $action['payload'] );
		}
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
