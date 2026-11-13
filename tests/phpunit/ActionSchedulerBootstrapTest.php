<?php
/**
 * Covers the automatic Action Scheduler bootstrap.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

/**
 * Verifies Action Scheduler is available without any consumer-side require.
 *
 * The fixture plugins only include `vendor/autoload.php` and call
 * `Service_Provider::register()`, so these assertions fail if the framework
 * stops loading the bundled Action Scheduler copy itself.
 */
final class ActionSchedulerBootstrapTest extends WP_UnitTestCase {

	/**
	 * Action Scheduler must be fully initialised, not merely present on disk.
	 */
	public function test_action_scheduler_is_initialised(): void {
		$this->assertTrue(
			class_exists( 'ActionScheduler_Versions', false ),
			'Action Scheduler was never loaded by Service_Provider::register().'
		);
		$this->assertTrue(
			class_exists( 'ActionScheduler', false ),
			'Action Scheduler loaded but did not initialise before plugins_loaded.'
		);
	}

	/**
	 * The scheduling API the Dispatcher calls unguarded must be defined.
	 */
	public function test_scheduling_functions_are_available(): void {
		$this->assertTrue( ActionScheduler::is_initialized() );
		$this->assertTrue( function_exists( 'as_schedule_single_action' ) );
		$this->assertTrue( function_exists( 'as_get_scheduled_actions' ) );
	}
}
