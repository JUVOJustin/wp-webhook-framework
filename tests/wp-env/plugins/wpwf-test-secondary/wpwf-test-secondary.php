<?php
/**
 * Plugin Name: WPWF Test Secondary
 * Description: Registers a secondary consumer webhook for multi-plugin validation.
 * Version: 1.0.0
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework\Tests\Fixtures\Secondary;

use juvo\WP_Webhook_Framework\Service_Provider;
use juvo\WP_Webhook_Framework\Webhook_Registry;
use juvo\WP_Webhook_Framework\Webhooks\Post_Webhook;

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
 * Registers a second post webhook from another plugin namespace.
 *
 * @param Webhook_Registry $registry The framework registry.
 */
function register_secondary_webhooks( Webhook_Registry $registry ): void {
	$post_webhook = new Post_Webhook( 'post_secondary' );
	$post_webhook->webhook_url( receiver_webhook_url( 'secondary-post' ) );

	$registry->register( $post_webhook );
}

\add_action( 'wpwf_register_webhooks', __NAMESPACE__ . '\\register_secondary_webhooks' );
