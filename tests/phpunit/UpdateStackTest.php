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
		as_unschedule_all_actions( Update_Stack::RECOVERY_HOOK, array(), 'wpwf' );
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
		$reject = static fn( $pre, int $timestamp, string $hook ) => Update_Stack::HOOK === $hook ? 0 : $pre;
		add_filter( 'pre_as_schedule_single_action', $reject, 10, 3 );
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

	/** A full window keeps its history and overflow starts another without losing the newest event. */
	public function test_capacity_opens_another_window_without_losing_events(): void {
		for ( $index = 0; $index < Update_Stack::MAX_EVENTS; ++$index ) {
			$this->emit_update( array( 'sequence' => $index ) );
		}
		$original   = $this->pending()[0];
		$this->now += 20;
		$this->emit_update( array( 'sequence' => 100 ) );
		$ids = $this->pending();
		$this->assertCount( 2, $ids );
		$this->assertSame( $original, $ids[0] );
		$this->now += 40;
		$this->run_action( $ids[0] );
		$this->now += 20;
		$this->run_action( $ids[1] );
		$requests = $this->get_captured_requests();
		$this->assertCount( 100, $requests[0]['body']['aggregated_events'] );
		$this->assertSame( 99, $requests[0]['body']['payload']['sequence'] );
		$this->assertCount( 1, $requests[1]['body']['aggregated_events'] );
		$this->assertSame( 100, $requests[1]['body']['payload']['sequence'] );
		$this->assertNotSame( $requests[0]['body']['delivery_id'], $requests[1]['body']['delivery_id'] );
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
	/** The byte limit splits a window even when its event count is small. */
	public function test_byte_capacity_splits_without_rejecting_valid_events(): void {
		$this->emit_update( array( 'large' => str_repeat( 'a', 140000 ) ) );
		$this->emit_update( array( 'large' => str_repeat( 'b', 140000 ) ) );
		$this->assertCount( 2, $this->pending() );
		$this->now += 60;
		foreach ( $this->pending() as $id ) {
			$this->run_action( $id );
		}
		$requests = $this->get_captured_requests();
		$this->assertSame( str_repeat( 'a', 140000 ), $requests[0]['body']['payload']['large'] );
		$this->assertSame( str_repeat( 'b', 140000 ), $requests[1]['body']['payload']['large'] );
	}

	/** Real meta entity-mode saves can exceed the per-window limit without losing notifications. */
	public function test_meta_entity_save_with_more_than_100_fields(): void {
		$post_id = self::factory()->post->create();
		$meta    = Service_Provider::get_registry()->get( 'meta' );
		$post    = Service_Provider::get_registry()->get( 'post' );
		for ( $index = 0; $index < 105; ++$index ) {
			add_post_meta( $post_id, 'stack_field_' . $index, 'before', true );
		}
		$this->clear_scheduled_webhooks();
		$post->stack_updates( true );
		$meta->emission_mode( juvo\WP_Webhook_Framework\Webhooks\Meta_Emission_Mode::ENTITY );
		try {
			for ( $index = 0; $index < 105; ++$index ) {
				update_post_meta( $post_id, 'stack_field_' . $index, 'after' );
			}
			$this->assertCount( 2, $this->pending() );
			$this->now += 60;
			foreach ( $this->pending() as $id ) {
				$this->run_action( $id );
			}
			$requests = $this->get_captured_requests();
			$this->assertCount( 100, $requests[0]['body']['aggregated_events'] );
			$this->assertCount( 5, $requests[1]['body']['aggregated_events'] );
			$this->assertSame( 'after', get_post_meta( $post_id, 'stack_field_104', true ) );
		} finally {
			$post->stack_updates( false );
			$meta->emission_mode( juvo\WP_Webhook_Framework\Webhooks\Meta_Emission_Mode::BOTH );
		}
	}

	/** Windows must reuse one group because Action Scheduler does not clean up group rows. */
	public function test_scheduler_group_count_does_not_grow_with_windows(): void {
		global $wpdb;
		$this->emit_update( array() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Inspect persistent scheduler group growth, the regression under test.
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->actionscheduler_groups}" );
		for ( $index = 0; $index < 10; ++$index ) {
			$this->now += 60;
			$this->emit_update( array() );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Group rows remain even after action cleanup.
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->actionscheduler_groups}" );
		$this->assertSame( $before, $after );
		$this->assertCount( 11, $this->pending() );
	}

	/** Ordinary nested event data stays accessible to PHP filters as associative arrays. */
	public function test_body_filter_can_read_nested_payload_arrays(): void {
		$this->emit_update( array( 'billing' => array( 'email' => 'test@example.com' ) ) );
		add_filter(
			'wpwf_request_body',
			static function ( array $body ): array {
				$body['payload']['email_copy'] = $body['payload']['billing']['email'];
				return $body;
			}
		);
		$this->now += 60;
		$this->run_action( $this->pending()[0] );
		$this->assertSame( 'test@example.com', $this->get_captured_requests()[0]['body']['payload']['email_copy'] );
	}

	/** Exceptions in notification listeners cannot cancel a retryable HTTP failure. */
	public function test_throwing_failure_listener_keeps_retry_and_frozen_body(): void {
		$this->webhook->webhook_url( $this->get_receiver_webhook_url( 'listener', 'fail' ) )->max_retries( 1 );
		add_action(
			'wpwf_webhook_failed',
			static function (): void {
				throw new RuntimeException( 'listener-error' );
			}
		);
		$this->emit_update( array() );
		$this->now += 60;
		$original   = $this->pending()[0];
		$this->run_action( $original );
		$this->assertCount( 1, $this->pending() );
		$this->now += $this->webhook->calculate_retry_delay( 1 );
		$this->run_action( $this->pending()[0] );
		$requests = $this->get_captured_requests();
		$this->assertCount( 2, $requests );
		$this->assertSame( $requests[0]['raw_body'], $requests[1]['raw_body'] );
		$this->assertSame( array(), ( new Stack_Store() )->pending_keys() );
	}

	/** Recovery starts with accepted work and retires instead of logging idle actions forever. */
	public function test_recovery_starts_on_demand_and_retires_when_empty(): void {
		$this->assertFalse( as_has_scheduled_action( Update_Stack::RECOVERY_HOOK, array(), 'wpwf' ) );
		$this->emit_update( array() );
		$this->assertTrue( as_has_scheduled_action( Update_Stack::RECOVERY_HOOK, array(), 'wpwf' ) );
		$this->now += 60;
		$this->run_action( $this->pending()[0] );
		$ids = as_get_scheduled_actions(
			array(
				'hook'   => Update_Stack::RECOVERY_HOOK,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $ids );
		$this->assertFalse( ActionScheduler::store()->fetch_action( $ids[0] )->get_schedule()->is_recurring() );
		$this->run_action( (int) $ids[0] );
		$this->assertFalse( as_has_scheduled_action( Update_Stack::RECOVERY_HOOK, array(), 'wpwf' ) );
		$this->emit_update( array() );
		$this->assertTrue( as_has_scheduled_action( Update_Stack::RECOVERY_HOOK, array(), 'wpwf' ) );
	}

	/** The runner must complete its batch even when a completion callback cannot acquire its lock. */
	public function test_after_action_lock_failure_defers_without_aborting_runner(): void {
		$this->emit_update( array() );
		$id        = $this->pending()[0];
		$deny_lock = static fn( string $query ): string => str_contains( $query, 'SELECT GET_LOCK(' ) ? 'SELECT 0' : $query;
		$install   = static function () use ( $deny_lock ): void {
			add_filter( 'query', $deny_lock );
		};
		add_action( 'action_scheduler_after_execute', $install );
		try {
			$this->run_action( $id );
			$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( $id ) );
		} finally {
			remove_filter( 'query', $deny_lock );
			remove_action( 'action_scheduler_after_execute', $install );
		}
		$this->assertCount( 1, ( new Stack_Store() )->pending_keys() );
		Service_Provider::get_dispatcher()->get_update_stack()->recover();
		$this->assertCount( 1, $this->pending() );
	}

	/** Unknown database routers retain legacy delivery rather than risk stale-read event loss. */
	public function test_database_dropin_falls_back_to_legacy_delivery(): void {
		global $wpdb;
		$this->emit_update( array( 'value' => 'retained' ) );
		$delivery_id = $this->pending()[0];
		ActionScheduler::store()->cancel_action( $delivery_id );
		$recovery = as_get_scheduled_actions(
			array(
				'hook'   => Update_Stack::RECOVERY_HOOK,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		ActionScheduler::store()->mark_failure( $recovery[0] );
		$original = $wpdb;
		$original->flush();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate a database drop-in while keeping the test transaction's actual connection.
		$wpdb = new class($original) extends wpdb {
			/**
			 * Share the original connection and WordPress table names, without reconnecting.
			 *
			 * @param wpdb $source Original database handle.
			 */
			public function __construct( wpdb $source ) {
				foreach ( get_object_vars( $source ) as $name => $value ) {
					$this->$name = $value;
				}
			}
		};
		try {
			do_action( 'action_scheduler_failed_action', (int) $recovery[0], 300 );
			for ( $cycle = 0; $cycle < 3; ++$cycle ) {
				$wake = as_get_scheduled_actions(
					array(
						'hook'   => Update_Stack::RECOVERY_HOOK,
						'status' => ActionScheduler_Store::STATUS_PENDING,
					),
					'ids'
				);
				$this->assertCount( 1, $wake );
				$this->run_action( (int) $wake[0] );
				$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( (int) $wake[0] ) );
			}
			$this->assertFalse( Stack_Store::is_supported() );
			$this->emit_update( array( 'value' => 'legacy' ) );
			$this->assertSame( array(), $this->pending() );
			$this->assertCount( 1, $this->get_scheduled_webhook_actions() );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the database object after the scoped router simulation.
			$wpdb = $original;
		}
		$recovery = as_get_scheduled_actions(
			array(
				'hook'   => Update_Stack::RECOVERY_HOOK,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $recovery );
		$this->run_action( (int) $recovery[0] );
		$this->assertCount( 1, $this->pending() );
		$this->now += 60;
		$this->run_action( $this->pending()[0] );
		$request = $this->get_captured_requests()[0];
		$this->assertSame( 'retained', $request['body']['payload']['value'] );
		$this->assertSame( $delivery_id, $request['body']['delivery_id'] );
	}

	/** A rejected retry in a failure callback cannot escape the runner or erase durable state. */
	public function test_after_action_schedule_rejection_is_recoverable(): void {
		$this->webhook->webhook_url( $this->get_receiver_webhook_url( 'rejected-retry', 'fail' ) )->max_retries( 1 );
		$this->emit_update( array() );
		$original   = $this->pending()[0];
		$reject     = static fn( $pre, int $timestamp, string $hook ) => Update_Stack::HOOK === $hook ? 0 : $pre;
		add_filter( 'pre_as_schedule_single_action', $reject, 10, 3 );
		$this->now += 60;
		try {
			$this->run_action( $original );
			$this->assertSame( ActionScheduler_Store::STATUS_FAILED, ActionScheduler::store()->get_status( $original ) );
			$this->assertSame( array(), $this->pending() );
			$this->assertCount( 1, ( new Stack_Store() )->pending_keys() );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $reject );
		}
		Service_Provider::get_dispatcher()->get_update_stack()->recover();
		$this->assertCount( 1, $this->pending() );
		$this->now += $this->webhook->calculate_retry_delay( 1 );
		$this->run_action( $this->pending()[0] );
		$this->assertCount( 2, $this->get_captured_requests() );
	}
	/** A recovery worker killed before its callback is restarted by the scheduler timeout hook. */
	public function test_abandoned_recovery_action_is_restarted(): void {
		$this->emit_update( array() );
		ActionScheduler::store()->cancel_action( $this->pending()[0] );
		$ids = as_get_scheduled_actions(
			array(
				'hook'   => Update_Stack::RECOVERY_HOOK,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		ActionScheduler::store()->log_execution( $ids[0] );
		ActionScheduler::store()->mark_failure( $ids[0] );
		do_action( 'action_scheduler_failed_action', (int) $ids[0], 300 );
		$recovery = as_get_scheduled_actions(
			array(
				'hook'   => Update_Stack::RECOVERY_HOOK,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $recovery );
		$this->assertNotSame( $ids[0], $recovery[0] );
		$this->run_action( (int) $recovery[0] );
		$this->assertCount( 1, $this->pending() );
	}
	/**
	 * Scheduler and recovery storage hooks must not overwrite an accepted nested event.
	 *
	 * @dataProvider storage_hook_cases
	 * @param string   $hook Scheduler hook whose creation triggers the nested event.
	 * @param string[] $expected Acceptance order.
	 * @phpstan-param list<string> $expected
	 */
	public function test_event_emitted_during_scheduler_storage_is_not_overwritten( string $hook, array $expected ): void {
		$emitted  = false;
		$listener = function ( int $action_id ) use ( &$emitted, $hook ): void {
			if ( ! $emitted && ActionScheduler::store()->fetch_action( $action_id )->get_hook() === $hook ) {
				$emitted = true;
				$this->emit_update( array( 'value' => 'nested' ) );
			}
		};
		add_action( 'action_scheduler_stored_action', $listener );
		try {
			$this->emit_update( array( 'value' => 'first' ) );
		} finally {
			remove_action( 'action_scheduler_stored_action', $listener );
		}
		$this->assertCount( 1, $this->pending() );
		$this->now += 60;
		$this->run_action( $this->pending()[0] );
		$body = $this->get_captured_requests()[0]['body'];
		$this->assertSame( $expected, array_column( array_column( $body['aggregated_events'], 'payload' ), 'value' ) );
		$this->assertSame( end( $expected ), $body['payload']['value'] );
	}
	/**
	 * Recovery is armed before the outer event is accepted; its nested event therefore comes first.
	 *
	 * @return list<array{string,list<string>}>
	 */
	public static function storage_hook_cases(): array {
		return array(
			array( Update_Stack::HOOK, array( 'first', 'nested' ) ),
			array( Update_Stack::RECOVERY_HOOK, array( 'nested', 'first' ) ),
		);
	}

	/** Legacy MariaDB compatibility prefixes must not be mistaken for the actual server version. */
	public function test_database_version_compatibility_strings(): void {
		$method = new ReflectionMethod( Stack_Store::class, 'supports_server' );
		$method->setAccessible( true );
		foreach ( array( '5.5.5-10.1.48-MariaDB', '10.1.48-MariaDB', '12.3.3-MariaDB', '5.7.5', '8.0.42-MySQL' ) as $version ) {
			$this->assertTrue( $method->invoke( null, $version ), $version );
		}
		foreach ( array( '5.5.5-10.0.38-MariaDB', '5.7.4', '5.5.5', 'unknown' ) as $version ) {
			$this->assertFalse( $method->invoke( null, $version ), $version );
		}
	}
}
