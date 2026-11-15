<?php
/**
 * Exercises durable update windows through the real scheduler and HTTP receiver.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

use juvo\WP_Webhook_Framework\Service_Provider;
use juvo\WP_Webhook_Framework\Stack_Store;
use juvo\WP_Webhook_Framework\Update_Stack;
use juvo\WP_Webhook_Framework\Webhook;

/** Pins payload, identity, cutoff and recovery contracts independently of queue timing. */
final class UpdateStackTest extends WPWF_Webhook_Test_Case {

	/**
	 * Controlled acceptance and dispatch clock.
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * Restore the application's migration store after each scenario.
	 *
	 * @var ActionScheduler_Store
	 */
	private ActionScheduler_Store $original_store;

	/**
	 * Default-on configuration registered after legacy fixtures opt out.
	 *
	 * @var Webhook
	 */
	private Webhook $webhook;

	/** Select the production database store and a fresh, default-on webhook. */
	public function set_up(): void {
		parent::set_up();
		$this->original_store = ActionScheduler::store();
		$this->select_store( new ActionScheduler_DBStore() );
		$this->clean_stacks();
		$this->now = time();
		add_filter( 'wpwf_stack_time', array( $this, 'clock' ) );
		$this->webhook = new class('stack_' . $this->getName()) extends Webhook {
			/** The tests emit explicitly instead of attaching WordPress CRUD hooks. */
			public function init(): void {}
		};
		$this->webhook->webhook_url( $this->get_receiver_webhook_url( 'stack' ) );
		Service_Provider::get_registry()->register( $this->webhook );
	}

	/** Remove durable records as well as actions; restore the global scheduler selection. */
	public function tear_down(): void {
		try {
			$this->clean_stacks();
			Service_Provider::get_registry()->unregister( $this->webhook->get_name() );
			parent::tear_down();
		} finally {
			$this->select_store( $this->original_store );
		}
	}

	/**
	 * Return a controllable UTC epoch without sleeping.
	 *
	 * @return int
	 */
	public function clock(): int {
		return $this->now;
	}

	/**
	 * Use the store both factory and runner will consult.
	 *
	 * @param ActionScheduler_Store $store Selected test store.
	 */
	private function select_store( ActionScheduler_Store $store ): void {
		$property = new ReflectionProperty( ActionScheduler_Store::class, 'store' );
		$property->setAccessible( true );
		$property->setValue( null, $store );
	}

	/** Clean only records and actions owned by this feature. */
	private function clean_stacks(): void {
		$store = new Stack_Store();
		do {
			$keys = $store->pending_keys();
			foreach ( $keys as $key ) {
				$store->delete( $key );
			}
		} while ( ! empty( $keys ) );
		delete_option( 'wpwf_recovery_cursor' );
		do {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => Update_Stack::HOOK,
					'per_page' => 100,
				),
				'ids'
			);
			foreach ( $ids as $id ) {
				ActionScheduler::store()->delete_action( $id );
			}
		} while ( ! empty( $ids ) );
	}

	/**
	 * Submit a complete custom event through the public dispatcher.
	 *
	 * @param array<string,mixed> $payload Event-specific data.
	 * @param int|string          $id Entity identity.
	 * @param string              $entity Entity type.
	 * @param array<string,mixed> $extra_headers Additional identity headers.
	 */
	private function emit_update( array $payload, int|string $id = 'entity-1', string $entity = 'custom', array $extra_headers = array() ): void {
		Service_Provider::get_dispatcher()->schedule( 'update', $entity, $id, $this->webhook->get_webhook_url(), $payload, array_merge( array( 'wpwf-webhook-name' => $this->webhook->get_name() ), $extra_headers ) );
	}

	/**
	 * Get pending stack actions in creation order.
	 *
	 * @return list<int>
	 */
	private function pending(): array {
		return array_map(
			'intval',
			as_get_scheduled_actions(
				array(
					'hook'     => Update_Stack::HOOK,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'orderby'  => 'action_id',
					'order'    => 'ASC',
					'per_page' => 200,
				),
				'ids'
			)
		);
	}

	/**
	 * Execute through the real runner so completion/failure callbacks enqueue retries.
	 *
	 * @param int $id Scheduler action ID.
	 */
	private function run_action( int $id ): void {
		( new ActionScheduler_QueueRunner( ActionScheduler::store() ) )->process_action( $id, 'wpwf-stack-test' );
	}

	/** New updates retain the initial action and deadline while preserving every payload. */
	public function test_fixed_window_keeps_original_action_and_all_events(): void {
		$this->assertTrue( $this->webhook->get_stack_updates() );
		$opened = $this->now;
		$this->emit_update( array( 'value' => 'first' ), 42 );
		$ids        = $this->pending();
		$this->now += 20;
		$this->emit_update( array( 'value' => 'second' ), '42' );
		$this->now += 39;
		$this->emit_update( array( 'value' => 'latest' ), 42 );
		$this->assertSame( $ids, $this->pending() );
		$this->assertSame( $opened + 60, ActionScheduler::store()->fetch_action( $ids[0] )->get_schedule()->get_date()->getTimestamp() );
		++$this->now;
		$this->run_action( $ids[0] );
		$requests = $this->get_captured_requests();
		$this->assertCount( 1, $requests );
		$body = $requests[0]['body'];
		$this->assertSame( $ids[0], $body['delivery_id'] );
		$this->assertSame( 1, $body['schema_version'] );
		$this->assertSame( 'latest', $body['payload']['value'] );
		$this->assertSame( array( 'first', 'second', 'latest' ), array_column( array_column( $body['aggregated_events'], 'payload' ), 'value' ) );
		$this->assertSame( $body['payload'], $body['aggregated_events'][2]['payload'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $opened + 59 ), $body['timestamp'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $opened ), $body['window']['opened_at'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $opened + 60 ), $body['window']['scheduled_for'] );
		$this->assertSame( array(), ( new Stack_Store() )->pending_keys() );
		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( $ids[0] ) );
	}

	/** The cutoff is inclusive even if the original job has not run yet. */
	public function test_boundary_opens_new_window_without_waiting_for_worker(): void {
		$this->emit_update( array( 'value' => 'first' ) );
		$this->now += 60;
		$this->emit_update( array( 'value' => 'next' ) );
		$ids = $this->pending();
		$this->assertCount( 2, $ids );
		$this->run_action( $ids[0] );
		$this->assertCount( 1, ( new Stack_Store() )->pending_keys() );
		$this->now += 60;
		$this->run_action( $ids[1] );
		$requests = $this->get_captured_requests();
		$this->assertSame( array( 'first', 'next' ), array_column( array_column( array_column( $requests, 'body' ), 'payload' ), 'value' ) );
		$this->assertNotSame( $requests[0]['body']['delivery_id'], $requests[1]['body']['delivery_id'] );
	}

	/** Forced early execution cannot shorten the window or replace its public ID. */
	public function test_early_runner_defers_delivery_but_keeps_original_id(): void {
		$this->emit_update( array( 'value' => 'first' ) );
		$original = $this->pending()[0];
		$this->run_action( $original );
		$this->assertSame( array(), $this->get_captured_requests() );
		$retry = $this->pending()[0];
		$this->assertNotSame( $original, $retry );
		$this->now += 60;
		$this->run_action( $retry );
		$this->assertSame( $original, $this->get_captured_requests()[0]['body']['delivery_id'] );
	}

	/** Failed deliveries freeze JSON, preserve ID and use the configured finite retry budget. */
	public function test_retry_reuses_exact_body_and_id(): void {
		$this->webhook->webhook_url( $this->get_receiver_webhook_url( 'stack', 'fail' ) )->max_retries( 1 );
		$calls = 0;
		add_filter(
			'wpwf_request_body',
			static function ( array $body ) use ( &$calls ): array {
				$body['filter_call'] = ++$calls;
				return $body;
			}
		);
		$this->emit_update( array( 'value' => 'frozen' ) );
		$original   = $this->pending()[0];
		$this->now += 60;
		$this->run_action( $original );
		$this->assertSame( ActionScheduler_Store::STATUS_FAILED, ActionScheduler::store()->get_status( $original ) );
		$retry = $this->pending()[0];
		$this->assertNotSame( $original, $retry );
		$this->now += $this->webhook->calculate_retry_delay( 1 );
		$this->run_action( $retry );
		$requests = $this->get_captured_requests();
		$this->assertCount( 2, $requests );
		$this->assertSame( $requests[0]['raw_body'], $requests[1]['raw_body'] );
		$this->assertSame( $original, $requests[1]['body']['delivery_id'] );
		$this->assertSame( '1', $requests[1]['headers']['wpwf-retry-count'] );
		$this->assertSame( 1, $calls );
		$this->assertSame( array(), $this->pending() );
		$this->assertSame( array(), ( new Stack_Store() )->pending_keys() );
	}

	/** A producer firing during HTTP must survive acknowledgement of the older window. */
	public function test_update_during_delivery_survives_acknowledgement(): void {
		$this->emit_update( array( 'value' => 'first' ) );
		$first      = $this->pending()[0];
		$this->now += 60;
		$on_http    = function ( $response, string $context, string $transport, array $args, string $url ): void {
			if ( $url === $this->webhook->get_webhook_url() ) {
				$this->emit_update( array( 'value' => 'during-http' ) );
			}
		};
		add_action( 'http_api_debug', $on_http, 10, 5 );
		$this->run_action( $first );
		remove_action( 'http_api_debug', $on_http, 10 );
		$this->assertCount( 1, $this->pending() );
		$this->now += 60;
		$this->run_action( $this->pending()[0] );
		$this->assertSame( 'during-http', $this->get_captured_requests()[1]['body']['payload']['value'] );
	}

	/** Opting out retains the existing first-pending payload and flat request body. */
	public function test_opt_out_preserves_legacy_delivery(): void {
		$this->webhook->stack_updates( false );
		$this->emit_update( array( 'value' => 'first' ) );
		$this->emit_update( array( 'value' => 'second' ) );
		$this->assertSame( array(), $this->pending() );
		$this->assertCount( 1, $this->get_scheduled_webhook_actions() );
		$this->run_scheduled_webhooks();
		$this->assertSame(
			array(
				'value'  => 'first',
				'action' => 'update',
				'entity' => 'custom',
				'id'     => 'entity-1',
			),
			$this->get_captured_requests()[0]['body']
		);
	}

	/** Immediate updates and their retries bypass aggregation even when it is enabled. */
	public function test_immediate_updates_and_retries_remain_flat(): void {
		$this->webhook->max_retries( 1 );
		try {
			Service_Provider::get_dispatcher()->dispatch_immediately( 'update', 'custom', 'immediate', $this->get_receiver_webhook_url( 'immediate', 'fail' ), array( 'value' => 'now' ), array( 'wpwf-webhook-name' => $this->webhook->get_name() ) );
			$this->fail( 'Expected the receiver failure.' );
		} catch ( WP_Exception $error ) {
			$this->assertSame( 'webhook_delivery_failed', $error->getMessage() );
		}
		$this->assertSame( array(), $this->pending() );
		$this->assertCount( 1, $this->get_scheduled_webhook_actions() );
		$this->run_scheduled_webhooks( true );
		foreach ( $this->get_captured_requests() as $request ) {
			$this->assertSame( 'now', $request['body']['value'] );
			$this->assertArrayNotHasKey( 'aggregated_events', $request['body'] );
		}
	}

	/** Non-update actions never enter a stack, including custom action names. */
	public function test_other_actions_remain_on_legacy_queue(): void {
		foreach ( array( 'create', 'delete', 'custom' ) as $action ) {
			Service_Provider::get_dispatcher()->schedule( $action, 'custom', 42, $this->webhook->get_webhook_url(), array(), array( 'wpwf-webhook-name' => $this->webhook->get_name() ) );
		}
		$this->assertSame( array(), $this->pending() );
		$this->assertCount( 3, $this->get_scheduled_webhook_actions() );
	}

	/** Different meta keys are retained, but post/user namespaces and request headers stay separate. */
	public function test_meta_keys_are_preserved_and_destinations_are_isolated(): void {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Notification payloads, not database query arguments.
		$this->emit_update(
			array(
				'meta_type' => 'post',
				'meta_key'  => 'a',
			),
			42,
			'meta'
		);
		$this->emit_update(
			array(
				'meta_type' => 'post',
				'meta_key'  => 'b',
			),
			42,
			'meta'
		);
		$this->emit_update(
			array(
				'meta_type' => 'user',
				'meta_key'  => 'c',
			),
			42,
			'meta'
		);
		$this->emit_update(
			array(
				'meta_type' => 'post',
				'meta_key'  => 'd',
			),
			42,
			'meta',
			array( 'Authorization' => 'another-test-recipient' )
		);
		$this->webhook->webhook_url( $this->get_receiver_webhook_url( 'other' ) );
		$this->emit_update(
			array(
				'meta_type' => 'post',
				'meta_key'  => 'e',
			),
			42,
			'meta'
		);
		$this->assertCount( 4, $this->pending() );
		$this->now += 60;
		foreach ( $this->pending() as $id ) {
			$this->run_action( $id );
		}
		$requests = $this->get_captured_requests();
		$this->assertCount( 4, $requests );
		$this->assertSame( array( 'a', 'b' ), array_column( array_column( $requests[0]['body']['aggregated_events'], 'payload' ), 'meta_key' ) );
	}

	// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key

	/** Accepted records recover when scheduling fails after persistence. */
	public function test_recovery_after_scheduler_rejection_and_interrupted_id_write(): void {
		$reject = static fn(): int => 0;
		add_filter( 'pre_as_schedule_single_action', $reject );
		try {
			$this->emit_update( array( 'value' => 'recoverable' ) );
			$this->fail( 'Expected scheduling to fail visibly.' );
		} catch ( WP_Exception $error ) {
			$this->assertSame( 'webhook_stack_schedule_failed', $error->getMessage() );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $reject );
		}
		$this->assertSame( array(), $this->pending() );
		$stack = Service_Provider::get_dispatcher()->get_update_stack();
		$stack->recover();
		$ids = $this->pending();
		$this->assertCount( 1, $ids );
		$store                 = new Stack_Store();
		$key                   = array_values( $store->pending_keys() )[0];
		$record                = $store->get( $key );
		$record['delivery_id'] = 0;
		$store->put( $key, $record );
		delete_option( 'wpwf_recovery_cursor' );
		$stack->recover();
		$this->assertSame( $ids, $this->pending() );
		$this->assertSame( $ids[0], $store->get( $key )['delivery_id'] );
		$this->now += 60;
		$this->run_action( $ids[0] );
		$this->assertSame( 'recoverable', $this->get_captured_requests()[0]['body']['payload']['value'] );
	}

	/** Overflow is explicit and cannot overwrite the already accepted event history. */
	public function test_capacity_rejects_new_event_without_losing_accepted_events(): void {
		for ( $index = 0; $index < Update_Stack::MAX_EVENTS; ++$index ) {
			$this->emit_update( array( 'sequence' => $index ) );
		}
		try {
			$this->emit_update( array( 'sequence' => 'overflow' ) );
			$this->fail( 'Expected the stack limit to reject this event.' );
		} catch ( WP_Exception $error ) {
			$this->assertSame( 'webhook_stack_capacity_exceeded', $error->getMessage() );
		}
		$this->now += 60;
		$this->run_action( $this->pending()[0] );
		$body = $this->get_captured_requests()[0]['body'];
		$this->assertCount( 100, $body['aggregated_events'] );
		$this->assertSame( 99, $body['payload']['sequence'] );
	}

	/** An oversized first event leaves no orphan record or action. */
	public function test_oversized_event_is_rejected_before_acceptance(): void {
		try {
			$this->emit_update( array( 'large' => str_repeat( 'x', Update_Stack::MAX_BYTES ) ) );
			$this->fail( 'Expected the byte limit to reject this event.' );
		} catch ( WP_Exception $error ) {
			$this->assertSame( 'webhook_stack_capacity_exceeded', $error->getMessage() );
		}
		$this->assertSame( array(), ( new Stack_Store() )->pending_keys() );
		$this->assertSame( array(), $this->pending() );
	}
	/** Persist arbitrary custom JSON shapes without turning empty objects into lists. */
	public function test_custom_payload_shapes_and_metadata_names_survive_storage(): void {
		$payload = array(
			'deleted'           => null,
			'unicode'           => 'Grüße 世界',
			'list'              => array( 1, 2 ),
			'object'            => (object) array(),
			'numeric_object'    => (object) array( '0' => 'zero' ),
			'timestamp'         => 'custom',
			'aggregated_events' => array( 'custom' ),
		);
		$this->emit_update( $payload );
		$this->now += 60;
		$this->run_action( $this->pending()[0] );
		$body = json_decode( $this->get_captured_requests()[0]['raw_body'] );
		$this->assertInstanceOf( stdClass::class, $body->payload->object );
		$this->assertInstanceOf( stdClass::class, $body->payload->numeric_object );
		$this->assertSame( 'zero', $body->payload->numeric_object->{'0'} );
		$this->assertNull( $body->payload->deleted );
		$this->assertSame( $payload['unicode'], $body->payload->unicode );
		$this->assertSame( 'custom', $body->payload->timestamp );
		$this->assertSame( array( 'custom' ), $body->payload->aggregated_events );
		$this->assertSame( wp_json_encode( $body->payload ), wp_json_encode( $body->aggregated_events[0]->payload ) );
	}

	/** Recovery pages beyond its first 100 records and does not confuse its cursor with a stack. */
	public function test_recovery_visits_backlog_without_duplicate_jobs(): void {
		for ( $index = 0; $index < 105; ++$index ) {
			$this->emit_update( array(), 'backlog-' . $index );
		}
		$original = $this->pending();
		foreach ( $original as $id ) {
			ActionScheduler::store()->cancel_action( $id );
		}
		$stack = Service_Provider::get_dispatcher()->get_update_stack();
		$stack->recover();
		$this->assertCount( 100, $this->pending() );
		$stack->recover();
		$this->assertCount( 105, $this->pending() );
		$stack->recover();
		$stack->recover();
		$this->assertCount( 105, $this->pending() );
	}

	/** Adding to an identity does not load every unrelated pending action. */
	public function test_append_query_count_stays_bounded_with_backlog(): void {
		global $wpdb;
		$this->emit_update( array() );
		$before = $wpdb->num_queries;
		$this->emit_update( array() );
		$baseline = $wpdb->num_queries - $before;
		for ( $index = 0; $index < 105; ++$index ) {
			$this->emit_update( array(), 'unrelated-' . $index );
		}
		$before = $wpdb->num_queries;
		$this->emit_update( array() );
		$this->assertLessThanOrEqual( $baseline + 2, $wpdb->num_queries - $before );
		$this->assertLessThan( 20, $baseline );
	}
	/** Upgrading does not invalidate already scheduled flat-body jobs. */
	public function test_existing_legacy_jobs_still_run_after_enabling_stacking(): void {
		$this->webhook->stack_updates( false );
		$this->emit_update( array( 'value' => 'old-job' ) );
		$this->webhook->stack_updates( true );
		$this->emit_update( array( 'value' => 'new-stack' ) );
		$this->run_scheduled_webhooks();
		$this->assertSame( 'old-job', $this->get_captured_requests()[0]['body']['value'] );
		$this->now += 60;
		$this->run_action( $this->pending()[0] );
		$this->assertSame( 'new-stack', $this->get_captured_requests()[1]['body']['payload']['value'] );
	}

	/** Derived fields are captured on acceptance even if the entity later disappears. */
	public function test_post_enrichment_survives_entity_deletion_before_send(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->emit_update( array(), $post_id, 'post' );
		wp_delete_post( $post_id, true );
		$this->now += 60;
		$this->run_action( $this->pending()[0] );
		$body = $this->get_captured_requests()[0]['body'];
		$this->assertSame( 'post', $body['payload']['post_type'] );
		$this->assertStringContainsString( '/wp/v2/posts/' . $post_id, $body['payload']['rest_url'] );
	}

	/** Blocking after enqueue prevents later sends and cleans up terminal state. */
	public function test_blocked_destination_does_not_send_or_retry(): void {
		$this->webhook->max_retries( 3 );
		$this->emit_update( array() );
		$failure = juvo\WP_Webhook_Framework\Failure::create_fresh();
		$failure->set_blocked( true )->set_blocked_time( time() )->save( $this->webhook->get_webhook_url() );
		$this->now += 60;
		$id         = $this->pending()[0];
		$this->run_action( $id );
		$this->assertSame( ActionScheduler_Store::STATUS_FAILED, ActionScheduler::store()->get_status( $id ) );
		$this->assertSame( array(), $this->get_captured_requests() );
		$this->assertSame( array(), ( new Stack_Store() )->pending_keys() );
		$this->assertSame( array(), $this->pending() );
	}

	/** A malformed final filter cannot send invalid JSON or leave a poison retry loop. */
	public function test_invalid_body_filter_fails_before_http(): void {
		$this->emit_update( array() );
		add_filter( 'wpwf_request_body', static fn() => false );
		$this->now += 60;
		$id         = $this->pending()[0];
		$this->run_action( $id );
		$this->assertSame( ActionScheduler_Store::STATUS_FAILED, ActionScheduler::store()->get_status( $id ) );
		$this->assertSame( array(), $this->get_captured_requests() );
		$this->assertSame( array(), ( new Stack_Store() )->pending_keys() );
	}
	/** A throwing backoff filter must fail terminally rather than create an endless recovery loop. */
	public function test_throwing_retry_filter_does_not_recover_forever(): void {
		$this->emit_update( array() );
		add_filter(
			'wpwf_retry_delay',
			static function (): int {
				throw new RuntimeException( 'broken-backoff-filter' );
			}
		);
		$this->now += 60;
		$id         = $this->pending()[0];
		$this->run_action( $id );
		$this->assertSame( ActionScheduler_Store::STATUS_FAILED, ActionScheduler::store()->get_status( $id ) );
		$this->assertSame( array(), $this->get_captured_requests() );
		$this->assertSame( array(), $this->pending() );
		$this->assertSame( array(), ( new Stack_Store() )->pending_keys() );
	}
}
