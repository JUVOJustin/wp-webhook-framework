<?php
/**
 * Pins the native database-store uniqueness contract separately from migration stores.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

/**
 * Uses the real scheduler database store; HybridStore falls back to non-atomic checks.
 */
final class ActionSchedulerNativeUniquenessTest extends WPWF_Webhook_Test_Case {

	/**
	 * Restore the application's original store after exercising the native store.
	 *
	 * @var ActionScheduler_Store
	 */
	private ActionScheduler_Store $original_store;

	/**
	 * Fresh WordPress test installs can still be using the migration HybridStore.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_store = ActionScheduler::store();
		if ( version_compare( ActionScheduler_Versions::instance()->latest_version(), '4.2.0', '<' ) ) {
			$this->markTestSkipped( 'The complete-args uniqueness contract requires Action Scheduler 4.2 or newer.' );
		}
		$property = new ReflectionProperty( ActionScheduler_Store::class, 'store' );
		$property->setAccessible( true );
		$property->setValue( null, new ActionScheduler_DBStore() );
	}

	/**
	 * Keep the store selection local to these integration tests.
	 */
	public function tear_down(): void {
		try {
			parent::tear_down();
		} finally {
			$property = new ReflectionProperty( ActionScheduler_Store::class, 'store' );
			$property->setAccessible( true );
			$property->setValue( null, $this->original_store );
		}
	}

	/**
	 * Native uniqueness compares complete arguments even beyond the indexed JSON size.
	 */
	public function test_native_unique_actions_match_large_complete_arguments(): void {
		$args      = array(
			'id'      => 'native-large',
			'payload' => str_repeat( 'x', 1000 ),
		);
		$first     = as_schedule_single_action( time() + 3600, 'wpwf_send_webhook', $args, 'wpwf-native-test', true );
		$duplicate = as_schedule_single_action( time() + 7200, 'wpwf_send_webhook', $args, 'wpwf-native-test', true );

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 0, $duplicate );
		$this->assertCount( 1, $this->get_scheduled_webhook_actions() );
	}

	/**
	 * Native uniqueness cannot replace our identity matching when payloads change.
	 */
	public function test_native_uniqueness_keeps_changed_payloads(): void {
		$args            = array(
			'id'      => 'native-changed',
			'payload' => 'first',
		);
		$first           = as_schedule_single_action( time() + 3600, 'wpwf_send_webhook', $args, 'wpwf-native-test', true );
		$args['payload'] = 'second';
		$changed         = as_schedule_single_action( time() + 3600, 'wpwf_send_webhook', $args, 'wpwf-native-test', true );

		$this->assertGreaterThan( 0, $first );
		$this->assertGreaterThan( 0, $changed );
		$this->assertCount( 2, $this->get_scheduled_webhook_actions() );
	}

	/**
	 * Serialization order is significant; the native key does not canonicalize maps.
	 */
	public function test_native_uniqueness_keeps_reordered_arguments(): void {
		$first     = as_schedule_single_action(
			time() + 3600,
			'wpwf_send_webhook',
			array(
				'id'      => 'native-order',
				'payload' => 'same',
			),
			'wpwf-native-test',
			true
		);
		$reordered = as_schedule_single_action(
			time() + 3600,
			'wpwf_send_webhook',
			array(
				'payload' => 'same',
				'id'      => 'native-order',
			),
			'wpwf-native-test',
			true
		);

		$this->assertGreaterThan( 0, $first );
		$this->assertGreaterThan( 0, $reordered );
		$this->assertCount( 2, $this->get_scheduled_webhook_actions() );
	}

	/**
	 * Native uniqueness also suppresses running work, then releases terminal actions.
	 */
	public function test_native_uniqueness_blocks_running_and_releases_completed_actions(): void {
		$args  = array( 'id' => 'native-running' );
		$first = as_schedule_single_action( time() + 3600, 'wpwf_send_webhook', $args, 'wpwf-native-test', true );
		$store = ActionScheduler::store();
		$store->log_execution( $first );
		$this->assertSame( ActionScheduler_Store::STATUS_RUNNING, $store->get_status( $first ) );

		$this->assertSame( 0, as_schedule_single_action( time() + 3600, 'wpwf_send_webhook', $args, 'wpwf-native-test', true ) );
		$store->mark_complete( $first );
		$this->assertGreaterThan( 0, as_schedule_single_action( time() + 3600, 'wpwf_send_webhook', $args, 'wpwf-native-test', true ) );
	}
}
