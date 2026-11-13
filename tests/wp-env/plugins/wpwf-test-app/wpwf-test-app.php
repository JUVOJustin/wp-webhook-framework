<?php
/**
 * Plugin Name: WPWF Test App
 * Description: Registers primary fixture webhooks for wp-env application tests.
 * Version: 1.0.0
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework\Tests\Fixtures\App;

use juvo\WP_Webhook_Framework\Service_Provider;
use juvo\WP_Webhook_Framework\Webhook;
use juvo\WP_Webhook_Framework\Webhook_Registry;
use juvo\WP_Webhook_Framework\Webhooks\Meta_Emission_Mode;
use juvo\WP_Webhook_Framework\Webhooks\Meta_Webhook;
use juvo\WP_Webhook_Framework\Webhooks\Post_Webhook;
use juvo\WP_Webhook_Framework\Webhooks\Term_Webhook;
use juvo\WP_Webhook_Framework\Webhooks\User_Webhook;

$wp_content_dir = dirname( __DIR__, 2 );
$autoload_path  = $wp_content_dir . '/wpwf-framework/vendor/autoload.php';

if ( ! file_exists( $autoload_path ) ) {
	return;
}

require_once $autoload_path;

// Also loads the bundled Action Scheduler; no separate require needed.
Service_Provider::register();

/**
 * Resolve the shared receiver base URL used by integration fixtures.
 *
 * @return string
 */
function receiver_base_url(): string {
	$base_url = getenv( 'WPWF_TEST_RECEIVER_BASE_URL' );
	if ( ! is_string( $base_url ) || '' === $base_url ) {
		$base_url = 'http://tests-wordpress';
	}

	return \untrailingslashit( $base_url );
}

/**
 * Build a receiver endpoint URL for fixture webhook delivery.
 *
 * @param string $target Receiver target identifier.
 * @param string $mode   Receiver mode (`success` or `fail`).
 * @return string
 */
function receiver_webhook_url( string $target, string $mode = 'success' ): string {
	return receiver_base_url() . '/index.php?rest_route=/wpwf-test/v1/receive/' . rawurlencode( $mode ) . '/' . rawurlencode( $target );
}

/**
 * Emits a custom test event so application-level integrations can be validated.
 */
final class Custom_Primary_Webhook extends Webhook {

	/**
	 * Configures the fixture webhook endpoint.
	 */
	public function __construct() {
		parent::__construct( 'custom_primary' );
		$this->webhook_url( receiver_webhook_url( 'primary-custom' ) );
	}

	/**
	 * Hooks the fixture event into the framework.
	 */
	public function init(): void {
		\add_action( 'wpwf_test_custom_event', array( $this, 'on_custom_event' ), 10, 2 );
	}

	/**
	 * Schedules a custom webhook payload for the current fixture event.
	 *
	 * @param string              $reference The fixture event identifier.
	 * @param array<string,mixed> $context   Extra event metadata.
	 */
	public function on_custom_event( string $reference, array $context = array() ): void {
		$this->emit(
			'trigger',
			'custom',
			$reference,
			array(
				'reference' => $reference,
				'context'   => $context,
			)
		);
	}
}

/**
 * Emits a fixture webhook with blocked notifications enabled.
 */
final class Notification_Primary_Webhook extends Webhook {

	/**
	 * Configures the fixture webhook endpoint and notifications.
	 */
	public function __construct() {
		parent::__construct( 'custom_blocked_notification' );
		$this->webhook_url( receiver_webhook_url( 'primary-notification' ) );
		$this->max_consecutive_failures( 1 );
		$this->notifications( array( 'blocked' ) );
	}

	/**
	 * Hooks the fixture event into the framework.
	 */
	public function init(): void {
		\add_action( 'wpwf_test_notification_event', array( $this, 'on_notification_event' ), 10, 1 );
	}

	/**
	 * Schedules a payload that can be forced into a blocked state by tests.
	 *
	 * @param string $reference The fixture event identifier.
	 */
	public function on_notification_event( string $reference ): void {
		$this->emit(
			'trigger',
			'notification',
			$reference,
			array(
				'reference' => $reference,
			)
		);
	}
}

/**
 * Registers the primary built-in and custom fixture webhooks.
 *
 * @param Webhook_Registry $registry The framework registry.
 */
function register_test_webhooks( Webhook_Registry $registry ): void {
	$post_webhook = new Post_Webhook();
	$post_webhook->webhook_url( receiver_webhook_url( 'primary-post' ) );
	$registry->register( $post_webhook );

	$term_webhook = new Term_Webhook();
	$term_webhook->webhook_url( receiver_webhook_url( 'primary-term' ) );
	$registry->register( $term_webhook );

	$user_webhook = new User_Webhook();
	$user_webhook->webhook_url( receiver_webhook_url( 'primary-user' ) );
	$registry->register( $user_webhook );

	$meta_webhook = new Meta_Webhook();
	$meta_webhook->webhook_url( receiver_webhook_url( 'primary-meta' ) );
	$meta_webhook->emission_mode( Meta_Emission_Mode::META );
	$registry->register( $meta_webhook );

	$registry->register( new Custom_Primary_Webhook() );
	$registry->register( new Notification_Primary_Webhook() );
}

\add_action( 'wpwf_register_webhooks', __NAMESPACE__ . '\\register_test_webhooks' );
