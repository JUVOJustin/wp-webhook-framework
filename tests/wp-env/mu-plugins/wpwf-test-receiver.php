<?php
/**
 * Plugin Name: WPWF Test Receiver (MU)
 * Description: Receives and stores webhook requests for wp-env integration tests.
 * Version: 1.0.0
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework\Tests\Fixtures\Receiver;

use WP_REST_Request;
use WP_REST_Response;

// Tests advance the queue explicitly; background runners would race assertions.
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

/**
 * Option key used to persist captured webhook requests.
 */
const RECEIVER_LOG_OPTION_KEY = 'wpwf_test_receiver_logs';

/**
 * Resolve the base URL used by fixture webhook senders.
 *
 * @return string
 */
function receiver_base_url(): string {
	$base_url = getenv( 'WPWF_TEST_RECEIVER_BASE_URL' );
	if ( ! is_string( $base_url ) || '' === $base_url ) {
		$base_url = 'http://tests-wordpress';
	}

	return untrailingslashit( $base_url );
}

/**
 * Register receiver management and delivery routes.
 */
function register_routes(): void {
	register_rest_route(
		'wpwf-test/v1',
		'/receive/(?P<mode>success|fail)/(?P<target>[a-z0-9_-]+)',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\receive_webhook',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'wpwf-test/v1',
		'/logs',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\list_logs',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'wpwf-test/v1',
		'/logs/reset',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\reset_logs',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Capture a delivered webhook payload and return a configured status response.
 *
 * @param WP_REST_Request $request The incoming REST request.
 * @return WP_REST_Response
 */
function receive_webhook( WP_REST_Request $request ): WP_REST_Response {
	$mode   = (string) $request->get_param( 'mode' );
	$target = (string) $request->get_param( 'target' );

	$raw_body = $request->get_body();
	$body     = json_decode( $raw_body, true );
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$headers      = normalize_headers( $request->get_headers() );
	$webhook_name = (string) ( $headers['wpwf-webhook-name'] ?? '' );

	$logs   = get_logs();
	$logs[] = array(
		'url'          => receiver_base_url() . '/index.php?rest_route=' . $request->get_route(),
		'mode'         => $mode,
		'target'       => $target,
		'webhook_name' => $webhook_name,
		'headers'      => $headers,
		'body'         => $body,
		'raw_body'     => $raw_body,
		'timestamp'    => time(),
	);

	save_logs( $logs );

	$status_code = 'fail' === $mode ? 500 : 200;

	return new WP_REST_Response(
		array(
			'mode'     => $mode,
			'target'   => $target,
			'received' => true,
		),
		$status_code
	);
}

/**
 * Return all captured webhook requests.
 *
 * @return WP_REST_Response
 */
function list_logs(): WP_REST_Response {
	return new WP_REST_Response(
		array(
			'logs' => get_logs(),
		)
	);
}

/**
 * Reset captured webhook requests.
 *
 * @return WP_REST_Response
 */
function reset_logs(): WP_REST_Response {
	save_logs( array() );

	return new WP_REST_Response(
		array(
			'logs' => array(),
		)
	);
}

/**
 * Retrieve persisted webhook logs.
 *
 * @return array<int,array<string,mixed>>
 */
function get_logs(): array {
	$logs = get_option( RECEIVER_LOG_OPTION_KEY, array() );

	if ( ! is_array( $logs ) ) {
		return array();
	}

	return array_values( $logs );
}

/**
 * Persist webhook logs for later assertions.
 *
 * @param array<int,array<string,mixed>> $logs The logs to store.
 */
function save_logs( array $logs ): void {
	update_option( RECEIVER_LOG_OPTION_KEY, $logs, false );
}

/**
 * Normalize WordPress REST header lists into scalar string values.
 *
 * @param array<string,mixed> $headers Raw headers from WP_REST_Request.
 * @return array<string,string>
 */
function normalize_headers( array $headers ): array {
	$normalized = array();

	foreach ( $headers as $key => $value ) {
		$normalized_key = strtolower( str_replace( '_', '-', $key ) );

		if ( is_array( $value ) ) {
			$first_value                   = array_shift( $value );
			$normalized[ $normalized_key ] = is_scalar( $first_value ) ? (string) $first_value : '';
			continue;
		}

		$normalized[ $normalized_key ] = is_scalar( $value ) ? (string) $value : '';
	}

	return $normalized;
}

add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
