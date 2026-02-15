<?php
/**
 * Failure notification handler.
 *
 * @package juvo\WP_Webhook_Framework
 */

namespace juvo\WP_Webhook_Framework\Notifications;

use juvo\WP_Webhook_Framework\Webhook;

/**
 * Sends email notifications when webhooks fail after reaching threshold.
 */
class Blocked extends Notification {

	/**
	 * The webhook URL that was blocked.
	 *
	 * @var string
	 */
	private string $url = '';

	/**
	 * The webhook response.
	 *
	 * @var \WP_Error|array<string,mixed>|null
	 */
	private \WP_Error|array|null $response = null;

	/**
	 * Maximum consecutive failures before blocking.
	 *
	 * @var int
	 */
	private int $max_failures = 0;

	/**
	 * Extracted error message from the response.
	 *
	 * @var string
	 */
	private string $error_message = '';

	/**
	 * The webhook instance that was blocked.
	 *
	 * @var Webhook|null
	 */
	private ?Webhook $webhook = null;

	/**
	 * Get the unique identifier for this notification handler.
	 *
	 * @return string
	 */
	public function get_identifier(): string {
		return 'blocked';
	}

	/**
	 * Register hooks for failure notifications.
	 */
	public function init(): void {
		add_action( 'wpwf_webhook_blocked', array( $this, 'on_webhook_blocked' ), 10, 4 );
	}

	/**
	 * Handle webhook blocked notification.
	 *
	 * Triggered when a webhook URL is blocked due to consecutive failures.
	 *
	 * @param string                        $url          The webhook URL.
	 * @param \WP_Error|array<string,mixed> $response     The response from wp_remote_post.
	 * @param int                           $max_failures Maximum failures threshold.
	 * @param Webhook                       $webhook      The webhook instance.
	 */
	public function on_webhook_blocked( string $url, \WP_Error|array $response, int $max_failures, Webhook $webhook ): void {
		$this->url          = $url;
		$this->response     = $response;
		$this->max_failures = $max_failures;
		$this->webhook      = $webhook;

		$this->error_message = $this->extract_error_message( $this->response );

		$admin_email = get_option( 'admin_email' );
		if ( ! $admin_email ) {
			return;
		}

		$subject = $this->get_subject();
		$message = $this->get_message();
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		// Apply custom filter to allow modification of email data
		$email_data = apply_filters(
			'wpwf_blocked_notification_email',
			array(
				'recipient'     => $admin_email,
				'subject'       => $subject,
				'message'       => $message,
				'headers'       => $headers,
				'url'           => $this->url,
				'error_message' => $this->error_message,
				'response'      => $this->response,
			),
			$this->url,
			$this->response
		);

		// Skip sending if filter returns false
		if ( false === $email_data ) {
			return;
		}

		// Send the email with potentially modified data
		wp_mail(
			$email_data['recipient'],
			$email_data['subject'],
			$email_data['message'],
			$email_data['headers']
		);
	}

	/**
	 * Get the subject line for blocked webhook notifications.
	 *
	 * @return string The email subject.
	 */
	public function get_subject(): string {
		return sprintf(
			/* translators: %s: Site name */
			__( 'Webhook URL Blocked - %s', 'wp-webhook-framework' ),
			get_bloginfo( 'name' )
		);
	}

	/**
	 * Get the message body for blocked webhook notifications.
	 *
	 * @return string The formatted email message.
	 */
	public function get_message(): string {
		$webhook_info = sprintf(
			"\nWebhook: %s",
			$this->webhook->get_name()
		);

		return sprintf(
			/* translators: 1: URL, 2: Max failures threshold, 3: Error message, 4: Time */
			__(
				'A webhook URL has been blocked due to consecutive failures.

URL: %1$s%5$s
Consecutive Failures: %2$d
Last Error: %3$s
Time: %4$s

This URL will be automatically unblocked after 1 hour. No webhooks will be delivered to this URL until then.',
				'wp-webhook-framework'
			),
			$this->url,
			$this->max_failures,
			$this->error_message,
			current_time( 'mysql' ),
			$webhook_info
		);
	}

	/**
	 * Extract error message from webhook response.
	 *
	 * @param \WP_Error|array<string,mixed> $response The webhook response.
	 * @return string The extracted error message.
	 */
	private function extract_error_message( \WP_Error|array $response ): string {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'HTTP Status Code: %d', 'wp-webhook-framework' ),
			$status_code
		);
	}
}
