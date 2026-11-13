<?php
/**
 * Covers blocked notifications and failure window resets.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

use juvo\WP_Webhook_Framework\Failure;
use juvo\WP_Webhook_Framework\Service_Provider;
use juvo\WP_Webhook_Framework\Webhook;

/**
 * Verifies blocked notifications and expired-block recovery behavior.
 */
final class NotificationAndFailureStateTest extends WPWF_Webhook_Test_Case {

	/**
	 * Ensures blocked notifications send exactly one email for enabled webhooks.
	 */
	public function test_blocked_notification_sends_email_for_enabled_webhook(): void {
		$webhook  = $this->get_notification_webhook();
		$url      = $this->get_receiver_webhook_url( 'notification-email', 'fail' );
		$mail_log = array();

		$webhook->webhook_url( $url );
		$webhook->max_retries( 0 );

		update_option( 'admin_email', 'alerts@example.com' );

		$mail_filter = static function ( $mail_result, array $atts ) use ( &$mail_log ) {
			$mail_log[] = $atts;
			return true;
		};

		add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );

		try {
			do_action( 'wpwf_test_notification_event', 'job-notification-email' );
			$this->run_scheduled_webhooks( true );

			$this->assertCount( 1, $this->get_captured_requests() );
			$this->assertCount( 1, $mail_log );
			$this->assertSame( 'alerts@example.com', $mail_log[0]['to'] );
			$this->assertStringContainsString( 'Webhook URL Blocked', $mail_log[0]['subject'] );
			$this->assertStringContainsString( 'custom_blocked_notification', $mail_log[0]['message'] );
			$this->assertStringContainsString( $url, $mail_log[0]['message'] );
		} finally {
			remove_filter( 'pre_wp_mail', $mail_filter, 10 );
			$webhook->webhook_url( $this->get_receiver_webhook_url( 'primary-notification' ) );
		}
	}

	/**
	 * Ensures expired blocks start a fresh failure window before the next attempt.
	 */
	public function test_expired_block_resets_failure_window_before_next_failure(): void {
		$webhook = $this->get_notification_webhook();
		$url     = $this->get_receiver_webhook_url( 'notification-expired', 'fail' );

		$webhook->webhook_url( $url );
		$webhook->max_retries( 0 );
		$webhook->max_consecutive_failures( 2 );

		$swallow_mail = static function () {
			return true;
		};

		add_filter( 'pre_wp_mail', $swallow_mail, 10, 2 );

		try {
			do_action( 'wpwf_test_notification_event', 'job-expired-block-1' );
			$this->run_scheduled_webhooks( true );

			do_action( 'wpwf_test_notification_event', 'job-expired-block-2' );
			$this->run_scheduled_webhooks( true );

			$blocked_state = Failure::from_transient( $url );
			$this->assertSame( 2, $blocked_state->get_count() );
			$this->assertTrue( $blocked_state->is_blocked() );

			$blocked_state->set_blocked_time( time() - HOUR_IN_SECONDS - 5 );
			$blocked_state->save( $url );

			$this->reset_captured_requests();

			do_action( 'wpwf_test_notification_event', 'job-expired-block-3' );
			$this->run_scheduled_webhooks( true );

			$this->assertCount( 1, $this->get_captured_requests() );

			$reset_state = Failure::from_transient( $url );
			$this->assertSame( 1, $reset_state->get_count() );
			$this->assertFalse( $reset_state->is_blocked() );
		} finally {
			remove_filter( 'pre_wp_mail', $swallow_mail, 10 );
			$webhook->webhook_url( $this->get_receiver_webhook_url( 'primary-notification' ) );
			$webhook->max_consecutive_failures( 1 );
		}
	}

	/**
	 * Returns the fixture webhook that enables blocked notifications.
	 *
	 * @return Webhook
	 */
	private function get_notification_webhook(): Webhook {
		$webhook = Service_Provider::get_registry()->get( 'custom_blocked_notification' );

		$this->assertInstanceOf( Webhook::class, $webhook );

		return $webhook;
	}
}
