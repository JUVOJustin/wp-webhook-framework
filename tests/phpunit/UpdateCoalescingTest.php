<?php
/**
 * Exercises the latest-state notification contract with real persistence and delivery.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

use juvo\WP_Webhook_Framework\Failure;
use juvo\WP_Webhook_Framework\Service_Provider;
use juvo\WP_Webhook_Framework\Update_Queue;
use juvo\WP_Webhook_Framework\Update_Store;
use juvo\WP_Webhook_Framework\Webhook;

/**
 * Keeps mutable change hints separate from immutable scheduler arguments.
 */
final class UpdateCoalescingTest extends WPWF_Webhook_Test_Case {

	/**
	 * Restore the application's store after testing native uniqueness.
	 *
	 * @var ActionScheduler_Store
	 */
	private ActionScheduler_Store $original_store;

	/** Exercise the production database store, including release of its unique index. */
	public function set_up(): void {
		parent::set_up();
		$this->original_store = ActionScheduler::store();
		$this->select_store( new ActionScheduler_DBStore() );
	}

	/** Keep native-store selection local to these scenarios. */
	public function tear_down(): void {
		try {
			parent::tear_down();
		} finally {
			$this->select_store( $this->original_store );
		}
	}

	/**
	 * Switch the singleton because fresh test installations may still use HybridStore.
	 *
	 * @param ActionScheduler_Store $store Store under test.
	 */
	private function select_store( ActionScheduler_Store $store ): void {
		$property = new ReflectionProperty( ActionScheduler_Store::class, 'store' );
		$property->setAccessible( true );
		$property->setValue( null, $store );
	}

	/** Migration stores must preserve changes arriving during delivery as well. */
	public function test_migration_store_preserves_inflight_changes(): void {
		$this->select_store( new ActionScheduler_HybridStore() );
		$this->test_change_during_execution_gets_a_follow_up();
	}

	/** Older servers must fail before their single-lock behavior can break serialization. */
	public function test_unsupported_database_is_rejected_before_locking(): void {
		global $wpdb;
		$original = $wpdb;
		foreach ( array( '5.6.51', '10.0.1-MariaDB' ) as $server ) {
			$database = $this->getMockBuilder( wpdb::class )->disableOriginalConstructor()->onlyMethods( array( 'db_version', 'db_server_info', 'get_var' ) )->getMock();
			$database->method( 'db_version' )->willReturn( $server );
			$database->method( 'db_server_info' )->willReturn( $server );
			$database->expects( $this->never() )->method( 'get_var' );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate only the capability boundary; delivery tests use the real database.
			$wpdb = $database;
			try {
				( new Update_Store() )->locked( 'unsupported', static fn(): bool => true );
				$this->fail( 'Expected unsupported database to be rejected.' );
			} catch ( WP_Exception $error ) {
				$this->assertSame( 'webhook_update_database_unsupported', $error->getMessage() );
			} finally {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the actual application connection before any cleanup.
				$wpdb = $original;
			}
		}
	}

	/**
	 * Replacing hints must retain the deadline, job ID and constant-size scheduler envelope.
	 */
	public function test_latest_hints_and_headers_replace_pending_content(): void {
		$webhook = $this->fixture();
		$this->queue( $webhook, array( 'changes' => array( 'title' => 'first' ) ) );
		$first = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() )[0];
		$webhook->headers( array_merge( $webhook->get_headers(), array( 'X-Revision-Hint' => 'latest' ) ) );
		$latest = array(
			'changes' => array( 'title' => 'latest' ),
			'extra'   => str_repeat( 'x', 10000 ),
		);
		$this->queue( $webhook, $latest );
		$actions = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() );
		$this->assertCount( 1, $actions );
		$this->assertSame( $first['action_id'], $actions[0]['action_id'] );
		$this->assertSame( $first['scheduled_timestamp'], $actions[0]['scheduled_timestamp'] );
		$this->assertSame( array( 'key' => $first['update_key'] ), ActionScheduler::store()->fetch_action( (int) $first['action_id'] )->get_args() );
		$this->run_scheduled_webhooks();
		$request = $this->get_captured_request_by_webhook_name( $webhook->get_name() );
		$this->assertSame( $latest['changes'], $request['body']['changes'] );
		$this->assertSame( $latest['extra'], $request['body']['extra'] );
		$this->assertSame( 'latest', $request['headers']['x-revision-hint'] );
		$this->assertSame( $actions[0]['revision'], $request['headers']['wpwf-update-id'] );
		$this->assertNull( ( new Update_Store() )->get( $first['update_key'] ) );
	}

	/**
	 * Equal object IDs must not mix consumers, destinations, entity types or meta owners.
	 */
	public function test_identity_separates_independent_subscriptions(): void {
		$a = $this->fixture();
		$b = $this->fixture();
		$b->webhook_url( $a->get_webhook_url() );
		$this->queue( $a, array(), 42 );
		$this->queue( $b, array(), 42 );
		$this->queue( $a, array(), 43 );
		$this->queue( $a, array(), 42, 'another' );
		$this->queue( $a, array( 'meta_type' => 'post' ), 42, 'meta' );
		$this->queue( $a, array( 'meta_type' => 'user' ), 42, 'meta' );
		$a->webhook_url( $this->get_receiver_webhook_url( 'another-destination' ) );
		$this->queue( $a, array(), 42 );
		$this->assertCount( 7, $this->get_scheduled_webhook_actions() );
	}

	/**
	 * A change accepted during HTTP delivery must survive acknowledgment of the older snapshot.
	 */
	public function test_change_during_execution_gets_a_follow_up(): void {
		$webhook = $this->fixture();
		$this->queue( $webhook, array( 'version' => 'first' ) );
		$first  = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() )[0];
		$filter = function ( array $body ) use ( $webhook ): array {
			if ( 'first' === ( $body['version'] ?? '' ) ) {
				$this->queue( $webhook, array( 'version' => 'during-send' ) );
				$this->queue( $webhook, array( 'version' => 'latest' ) );
			}
			return $body;
		};
		add_filter( 'wpwf_request_body', $filter );
		try {
			( new ActionScheduler_QueueRunner( ActionScheduler::store() ) )->process_action( (int) $first['action_id'], 'wpwf-test' );
		} finally {
			remove_filter( 'wpwf_request_body', $filter );
		}
		$pending = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() );
		$this->assertCount( 1, $pending );
		$this->assertNotSame( $first['action_id'], $pending[0]['action_id'] );
		$this->assertSame( 'latest', $pending[0]['payload']['version'] );
		$this->run_scheduled_webhooks();
		$this->assertSame( array( 'first', 'latest' ), array_column( array_column( $this->get_captured_requests(), 'body' ), 'version' ) );
		$this->assertNull( ( new Update_Store() )->get( $first['update_key'] ) );
	}

	/**
	 * The failure of an older attempt must not overwrite newer content with a retry snapshot.
	 */
	public function test_new_event_supersedes_failed_in_flight_content(): void {
		$webhook = $this->fixture( 'fail' )->max_retries( 1 );
		$this->queue( $webhook, array( 'version' => 'old' ) );
		$first  = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() )[0];
		$filter = function ( array $body ) use ( $webhook ): array {
			$this->queue( $webhook, array( 'version' => 'new' ) );
			return $body;
		};
		add_filter( 'wpwf_request_body', $filter );
		try {
			( new ActionScheduler_QueueRunner( ActionScheduler::store() ) )->process_action( (int) $first['action_id'], 'wpwf-test' );
		} finally {
			remove_filter( 'wpwf_request_body', $filter );
		}
		$pending = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'new', $pending[0]['payload']['version'] );
		$this->assertSame( 0, $pending[0]['attempt'] );
		$this->assertSame( ActionScheduler_Store::STATUS_FAILED, ActionScheduler::store()->get_status( (int) $first['action_id'] ) );
	}

	/**
	 * Repeated failures must obey configured backoff and exhaustion without leaking durable state.
	 */
	public function test_retry_budget_and_revision_are_preserved(): void {
		$webhook = $this->fixture( 'fail' )->max_retries( 1 );
		$this->queue( $webhook, array( 'changes' => array( 'status' => 'ready' ) ) );
		$first = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() )[0];
		$this->run_scheduled_webhooks( true );
		$retry = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() )[0];
		$this->assertSame( 1, $retry['attempt'] );
		$this->assertSame( $first['revision'], $retry['revision'] );
		$this->assertGreaterThanOrEqual( time() + 55, $retry['scheduled_timestamp'] );
		$this->run_scheduled_webhooks( true );
		$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() ) );
		$this->assertNull( ( new Update_Store() )->get( $first['update_key'] ) );
		$this->assertSame( 2, Failure::from_transient( $webhook->get_webhook_url() )->get_count() );
		$requests = $this->get_captured_requests();
		$this->assertSame( $requests[0]['headers']['wpwf-update-id'], $requests[1]['headers']['wpwf-update-id'] );
	}

	/**
	 * New content resets retry ownership without bypassing an endpoint's existing backoff.
	 */
	public function test_new_update_replaces_a_pending_retry(): void {
		$webhook = $this->fixture( 'fail' )->max_retries( 1 );
		$this->queue( $webhook, array( 'version' => 'old' ), 42 );
		$this->run_scheduled_webhooks( true );
		$retry = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() )[0];
		$this->queue( $webhook, array( 'version' => 'new' ), '42' );
		$pending = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() );
		$this->assertCount( 1, $pending );
		$this->assertSame( $retry['action_id'], $pending[0]['action_id'] );
		$this->assertSame( $retry['scheduled_timestamp'], $pending[0]['scheduled_timestamp'] );
		$this->assertNotSame( $retry['revision'], $pending[0]['revision'] );
		$this->assertSame( 0, $pending[0]['attempt'] );
		$this->run_scheduled_webhooks( true );
		$requests = $this->get_captured_requests();
		$this->assertSame( 'new', $requests[1]['body']['version'] );
		$this->assertArrayNotHasKey( 'wpwf-retry-count', $requests[1]['headers'] );
	}

	/**
	 * Invalid replacement content must leave the previously accepted notification intact.
	 */
	public function test_encoding_failure_does_not_destroy_pending_content(): void {
		$webhook = $this->fixture();
		$this->queue( $webhook, array( 'version' => 'valid' ) );
		try {
			$this->queue( $webhook, array( 'invalid' => INF ) );
			$this->fail( 'Expected invalid JSON to be rejected.' );
		} catch ( WP_Exception $error ) {
			$this->assertSame( 'webhook_update_encoding_failed', $error->getMessage() );
		}
		$this->run_scheduled_webhooks();
		$this->assertSame( 'valid', $this->get_captured_request_by_webhook_name( $webhook->get_name() )['body']['version'] );
	}

	/**
	 * A blocked destination must not be contacted or leave an endlessly recoverable snapshot.
	 */
	public function test_blocking_stops_coalesced_retries_and_cleans_state(): void {
		$webhook = $this->fixture( 'fail' )->max_retries( 3 )->max_consecutive_failures( 1 );
		$this->queue( $webhook, array() );
		$first = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() )[0];
		$this->run_scheduled_webhooks( true );
		$this->run_scheduled_webhooks( true );
		$this->assertCount( 1, $this->get_captured_requests() );
		$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() ) );
		$this->assertNull( ( new Update_Store() )->get( $first['update_key'] ) );
	}

	/**
	 * A storage failure after persistence must remain recoverable in another queue instance.
	 */
	public function test_recovery_requeues_rejected_enqueue(): void {
		$webhook = $this->fixture();
		$filter  = static fn(): int => 0;
		add_filter( 'pre_as_schedule_single_action', $filter );
		try {
			$this->queue( $webhook, array( 'version' => 'durable' ) );
			$this->fail( 'Expected a scheduling failure.' );
		} catch ( WP_Exception $error ) {
			$this->assertSame( 'webhook_schedule_failed', $error->getMessage() );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $filter );
		}
		$this->assertCount( 0, $this->get_scheduled_webhook_actions() );
		delete_option( 'wpwf_recovery_cursor' );
		( new Update_Queue( Service_Provider::get_dispatcher() ) )->recover();
		$this->assertCount( 1, $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() ) );
		$this->run_scheduled_webhooks();
		$this->assertSame( 'durable', $this->get_captured_request_by_webhook_name( $webhook->get_name() )['body']['version'] );
	}

	/**
	 * Timed-out workers leave a durable snapshot that recovery can revisit.
	 */
	public function test_recovery_requeues_an_interrupted_worker(): void {
		$webhook = $this->fixture();
		$this->queue( $webhook, array( 'version' => 'survives' ) );
		$first = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() )[0];
		ActionScheduler::store()->mark_failure( (int) $first['action_id'] );
		delete_option( 'wpwf_recovery_cursor' );
		Service_Provider::get_dispatcher()->get_update_queue()->recover();
		$pending = $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() );
		$this->assertCount( 1, $pending );
		$this->assertNotSame( $first['action_id'], $pending[0]['action_id'] );
		$this->run_scheduled_webhooks();
		$this->assertSame( 'survives', $this->get_captured_request_by_webhook_name( $webhook->get_name() )['body']['version'] );
	}

	/**
	 * Event streams and non-update actions must retain every individual payload.
	 */
	public function test_opt_out_and_lifecycle_events_are_not_coalesced(): void {
		$webhook = $this->fixture()->coalesce_updates( false );
		foreach ( array( 'update', 'create', 'delete', 'payment' ) as $action ) {
			foreach ( array( 'first', 'second' ) as $version ) {
				Service_Provider::get_dispatcher()->schedule( $action, 'custom', 42, $webhook->get_webhook_url(), array( 'version' => $version ), $webhook->get_headers() );
			}
		}
		$this->assertCount( 8, $this->get_scheduled_actions_by_webhook_name( $webhook->get_name() ) );
		$this->run_scheduled_webhooks();
		$this->assertCount( 8, $this->get_captured_requests() );
	}

	/**
	 * Change hints do not replace reading the authoritative resource through its REST URL.
	 */
	public function test_receiver_can_fetch_newer_resource_state_than_change_hints(): void {
		$webhook = $this->fixture();
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Original',
				'post_status' => 'publish',
			)
		);
		$this->clear_scheduled_webhooks();
		$this->queue( $webhook, array( 'changes' => array( 'post_title' => 'Hint' ) ), $post_id, 'post' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Authoritative current title',
			)
		);
		$this->run_scheduled_webhooks();
		$body = $this->get_captured_request_by_webhook_name( $webhook->get_name() )['body'];
		$this->assertSame( array( 'post_title' => 'Hint' ), $body['changes'] );
		$this->assertStringContainsString( '/wp/v2/posts/' . $post_id, $body['rest_url'] );
		$response = rest_do_request( '/wp/v2/posts/' . $post_id );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Authoritative current title', $response->get_data()['title']['rendered'] );
	}

	/**
	 * Six-argument jobs created before this change must still execute.
	 */
	public function test_legacy_queued_updates_remain_executable(): void {
		$webhook = $this->fixture();
		as_schedule_single_action(
			time() + 5,
			'wpwf_send_webhook',
			array(
				'url'     => $webhook->get_webhook_url(),
				'action'  => 'update',
				'entity'  => 'custom',
				'id'      => 42,
				'payload' => array( 'legacy' => true ),
				'headers' => $webhook->get_headers(),
			),
			'wpwf-custom'
		);
		$this->run_scheduled_webhooks();
		$this->assertTrue( $this->get_captured_request_by_webhook_name( $webhook->get_name() )['body']['legacy'] );
	}

	/**
	 * Register an isolated endpoint without incidental entity hooks.
	 *
	 * @param string $mode Receiver response mode.
	 * @return Webhook
	 */
	private function fixture( string $mode = 'success' ): Webhook {
		$name    = 'coalesced_' . wp_generate_uuid4();
		$webhook = new class( $name ) extends Webhook {
			/** No entity hooks are needed for explicit dispatcher scenarios. */
			public function init(): void {}
		};
		$webhook->webhook_url( $this->get_receiver_webhook_url( $name, $mode ) )->max_retries( 0 )->max_consecutive_failures( 99 );
		Service_Provider::get_registry()->register( $webhook );
		return $webhook;
	}

	/**
	 * Exercise the public dispatch boundary, including URL and payload filters.
	 *
	 * @param Webhook             $webhook Subscription.
	 * @param array<string,mixed> $payload Change hints.
	 * @param int|string          $id Entity ID.
	 * @param string              $entity Entity type.
	 */
	private function queue( Webhook $webhook, array $payload, int|string $id = 42, string $entity = 'custom' ): void {
		Service_Provider::get_dispatcher()->schedule( 'update', $entity, $id, $webhook->get_webhook_url(), $payload, $webhook->get_headers() );
	}
}
