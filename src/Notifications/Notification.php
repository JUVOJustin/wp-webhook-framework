<?php
/**
 * Abstract base class for webhook notifications.
 *
 * @package juvo\WP_Webhook_Framework
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework\Notifications;

use juvo\WP_Webhook_Framework\Webhook;

/**
 * Base notification handler class.
 *
 * Provides a consistent interface for notification handlers that respond to webhook events.
 * Notifications are registered per-webhook and only trigger for enabled webhooks.
 */
abstract class Notification {

	/**
	 * Get the unique identifier for this notification handler.
	 *
	 * Used for registration and configuration. Should be lowercase and URL-safe.
	 *
	 * @return string Unique identifier (e.g., 'blocked', 'slack', 'email').
	 */
	abstract public function get_identifier(): string;

	/**
	 * Initialize the notification handler.
	 *
	 * Register WordPress hooks and actions needed for this notification type.
	 * Called by the registry when the notification is registered.
	 */
	abstract public function init(): void;

	/**
	 * Get the subject line for this notification.
	 *
	 * @return string The subject line.
	 */
	abstract public function get_subject(): string;

	/**
	 * Get the message body for this notification.
	 *
	 * @return string The formatted message.
	 */
	abstract public function get_message(): string;

	/**
	 * Handle webhook success notification.
	 *
	 * Triggered when a webhook is successfully delivered.
	 *
	 * @param string                        $url      The webhook URL.
	 * @param array<string,mixed>           $payload  The webhook payload data.
	 * @param array<string,mixed>|\WP_Error $response The response from wp_remote_post.
	 * @param Webhook                       $webhook  The webhook instance.
	 */
	public function on_webhook_success( string $url, array $payload, \WP_Error|array $response, Webhook $webhook ): void {
		// Default: No action. Override in subclass if needed.
	}

	/**
	 * Handle webhook failure notification.
	 *
	 * Triggered when a webhook fails after all retries are exhausted.
	 *
	 * @param string                        $url           The webhook URL.
	 * @param \WP_Error|array<string,mixed> $response      The response from wp_remote_post.
	 * @param int                           $failure_count Current consecutive failure count.
	 * @param int                           $max_failures  Maximum failures before blocking.
	 * @param Webhook                       $webhook       The webhook instance.
	 */
	public function on_webhook_failed( string $url, \WP_Error|array $response, int $failure_count, int $max_failures, Webhook $webhook ): void {
		// Default: No action. Override in subclass if needed.
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
		// Default: No action. Override in subclass if needed.
	}
}
