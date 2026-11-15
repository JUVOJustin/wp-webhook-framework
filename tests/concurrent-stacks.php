<?php
/**
 * Real-process concurrency regression outside PHPUnit's rollback transaction.
 *
 * Run through pnpm run test:concurrency in the disposable wp-env installation.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

use juvo\WP_Webhook_Framework\Service_Provider;
use juvo\WP_Webhook_Framework\Update_Stack;
use juvo\WP_Webhook_Framework\Stack_Store;
use juvo\WP_Webhook_Framework\Webhook;

// Preinitialized WordPress hooks select the native store before any active plugin boots.
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress converts preinitialized hook arrays during bootstrap; add_filter() does not exist yet.
$GLOBALS['wp_filter']['action_scheduler_store_class'][ PHP_INT_MAX ][] = array(
	'function'      => static fn(): string => 'ActionScheduler_DBStore',
	'accepted_args' => 1,
);
require_once '/var/www/html/wp-load.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
Service_Provider::register();
wpwf_require( ActionScheduler::store() instanceof ActionScheduler_DBStore, 'Native database store was not selected.' );

$clock = isset( $argv[4] ) ? (int) $argv[4] : time();
add_filter(
	'wpwf_stack_time',
	static function () use ( &$clock ): int {
		return $clock;
	}
);

$run_id  = $argv[1] ?? wp_generate_uuid4();
$name    = 'concurrent_' . $run_id;
$url     = 'http://tests-wordpress/index.php?rest_route=/wpwf-test/v1/receive/success/' . $name;
$webhook = new class( $name ) extends Webhook {

	/** Explicit scheduling keeps unrelated entity hooks out of the probe. */
	public function init(): void {}
};
$webhook->webhook_url( $url )->max_retries( 1 );
Service_Provider::get_registry()->register( $webhook );
$dispatcher = Service_Provider::get_dispatcher();

if ( 'crash' === ( $argv[2] ?? '' ) ) {
	// Terminate after the receiver accepted HTTP but before the stack can acknowledge it.
	add_action(
		'http_api_debug',
		static function ( $response, string $context, string $transport, array $args, string $request_url ) use ( $url ): void {
			if ( $url === $request_url ) {
				exit( 0 );
			}
		},
		10,
		5
	);
	( new ActionScheduler_QueueRunner( ActionScheduler::store() ) )->process_action( (int) $argv[3], 'wpwf-crash' );
	exit( 1 );
}

if ( isset( $argv[2], $argv[3] ) ) {
	// A shared start barrier makes all eight PHP workers contend for one identity.
	$delay = (float) $argv[2] - microtime( true );
	if ( 0 < $delay ) {
		usleep( (int) ( $delay * 1000000 ) );
	}
	if ( 'recover' === $argv[3] ) {
		for ( $pass = 0; $pass < 10; ++$pass ) {
			$dispatcher->get_update_stack()->recover();
		}
		exit( 0 );
	}
	$dispatcher->schedule(
		'update',
		'custom',
		$run_id,
		$url,
		array(
			'version' => $argv[3],
			'large'   => str_repeat( 'x', 10000 ),
		),
		$webhook->get_headers()
	);
	exit( 0 );
}

/**
 * Fail the CLI command without relying on PHP's optional assertion configuration.
 *
 * @param bool   $condition Required invariant.
 * @param string $message Failure context.
 * @throws RuntimeException When a concurrency invariant fails.
 */
function wpwf_require( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertions receive only literal test messages.
		throw new RuntimeException( $message );
	}
}

$workers      = array();
$key          = null;
$owned_keys   = array();
$store        = new Stack_Store();
$start        = (string) ( microtime( true ) + 3 );
$worker_count = max( 1, min( 16, (int) ( getenv( 'WPWF_TEST_WORKERS' ) ?: 8 ) ) );
try {
	for ( $worker = 0; $worker <= $worker_count; ++$worker ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Separate PHP processes are the behavior under test.
		$process = proc_open(
			array( PHP_BINARY, __FILE__, $run_id, $start, $worker_count === $worker ? 'recover' : (string) $worker, (string) $clock ),
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => STDOUT,
				2 => STDERR,
			),
			$pipes
		);
		wpwf_require( is_resource( $process ), 'Could not launch concurrent worker.' );
		$workers[] = $process;
	}
	foreach ( $workers as $process ) {
		$deadline = microtime( true ) + 30;
		do {
			$worker_status = proc_get_status( $process );
			if ( ! $worker_status['running'] ) {
				break;
			}
			wpwf_require( microtime( true ) < $deadline, 'Concurrent worker timed out.' );
			usleep( 25000 );
		} while ( true );
		$exit_code = $worker_status['exitcode'];
		proc_close( $process );
		wpwf_require( 0 === $exit_code, 'A concurrent enqueue failed.' );
	}
	$workers = array();

	// A final event makes acceptance order observable without assuming which concurrent writer wins.
	$dispatcher->schedule( 'update', 'custom', $run_id, $url, array( 'version' => 'latest' ), $webhook->get_headers() );
	$cursor = 0;
	do {
		$keys = $store->pending_keys( $cursor );
		foreach ( $keys as $candidate ) {
			$record = $store->get( $candidate );
			if ( ( $record['headers']['wpwf-webhook-name'] ?? '' ) === $name ) {
				wpwf_require( null === $key, 'Concurrent writers created multiple records.' );
				$key = $candidate;
			}
		}
		$cursor = empty( $keys ) ? 0 : max( array_keys( $keys ) );
	} while ( 0 !== $cursor );
	wpwf_require( null !== $key, 'Missing durable update record.' );
	$owned_keys[] = $key;
	$ids          = as_get_scheduled_actions(
		array(
			'hook'     => Update_Stack::HOOK,
			'args'     => array( 'key' => $key ),
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 100,
		),
		'ids'
	);
	wpwf_require( 1 === count( $ids ), 'Concurrent writers created duplicate scheduler actions.' );
	$events   = $store->get( $key )['events'];
	$versions = array_column( array_column( $events, 'payload' ), 'version' );
	wpwf_require( count( $events ) === $worker_count + 1 && count( array_unique( $versions ) ) === $worker_count + 1, 'An accepted concurrent event was lost or duplicated.' );
	wpwf_require( 'latest' === end( $versions ), 'Final event order was lost.' );
	$original_id = (int) $ids[0];
	$clock      += 60;
	( new ActionScheduler_QueueRunner( ActionScheduler::store() ) )->process_action( (int) $ids[0], 'wpwf-concurrency' );
	wpwf_require( ActionScheduler_Store::STATUS_COMPLETE === ActionScheduler::store()->get_status( (int) $ids[0] ), 'Delivery did not complete.' );
	wpwf_require( null === $store->get( $key ), 'Acknowledged state was not removed.' );
	$response = wp_remote_post( 'http://tests-wordpress/index.php?rest_route=/wpwf-test/v1/logs' );
	$logs     = json_decode( wp_remote_retrieve_body( $response ), true )['logs'] ?? array();
	$received = array_values( array_filter( $logs, static fn( array $log ): bool => $name === $log['webhook_name'] ) );
	wpwf_require( 1 === count( $received ) && 'latest' === $received[0]['body']['payload']['version'] && count( $received[0]['body']['aggregated_events'] ) === $worker_count + 1 && $original_id === $received[0]['body']['delivery_id'], 'Receiver did not get one complete aggregated notification with the original action ID.' );
	echo "PASS: concurrent writers plus recovery preserved every event, one action and original delivery ID.\n";

	$dispatcher->schedule( 'update', 'custom', $run_id, $url, array( 'version' => 'crash' ), $webhook->get_headers() );
	$keys = $store->pending_keys();
	foreach ( $keys as $candidate ) {
		if ( ( $store->get( $candidate )['headers']['wpwf-webhook-name'] ?? '' ) === $name ) {
			$key = $candidate;
		}
	}
	$owned_keys[] = $key;
	$original_id  = $store->get( $key )['delivery_id'];
	$clock       += 60;
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- A real process exit after HTTP exercises recovery without finally callbacks.
	$process = proc_open(
		array( PHP_BINARY, __FILE__, $run_id, 'crash', (string) $original_id, (string) $clock ),
		array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => STDOUT,
			2 => STDERR,
		),
		$pipes
	);
	wpwf_require( is_resource( $process ), 'Could not launch crash worker.' );
	$workers[] = $process;
	$deadline  = microtime( true ) + 30;
	do {
		$crash_status = proc_get_status( $process );
		if ( ! $crash_status['running'] ) {
			break;
		}
		wpwf_require( microtime( true ) < $deadline, 'Crash worker timed out.' );
		usleep( 25000 );
	} while ( true );
	proc_close( $process );
	$workers = array();
	wpwf_require( 0 === $crash_status['exitcode'], 'Crash worker did not reach its exit point.' );
	wpwf_require( ActionScheduler_Store::STATUS_RUNNING === ActionScheduler::store()->get_status( $original_id ), 'Hard exit did not leave an in-progress action.' );
	wpwf_require( 1 === $store->get( $key )['attempt'] && null !== $store->get( $key )['body'], 'Attempt was not sealed before HTTP.' );
	// Action Scheduler's timeout cleanup marks the abandoned attempt failed; recovery takes over.
	ActionScheduler::store()->mark_failure( $original_id );
	$clock += $webhook->calculate_retry_delay( 1 );
	delete_option( 'wpwf_recovery_cursor' );
	$dispatcher->get_update_stack()->recover();
	$ids = as_get_scheduled_actions(
		array(
			'hook'   => Update_Stack::HOOK,
			'args'   => array( 'key' => $key ),
			'status' => ActionScheduler_Store::STATUS_PENDING,
		),
		'ids'
	);
	wpwf_require( 1 === count( $ids ) && $original_id !== (int) $ids[0], 'Recovery did not create exactly one retry.' );
	( new ActionScheduler_QueueRunner( ActionScheduler::store() ) )->process_action( (int) $ids[0], 'wpwf-recovery' );
	$response = wp_remote_post( 'http://tests-wordpress/index.php?rest_route=/wpwf-test/v1/logs' );
	$logs     = json_decode( wp_remote_retrieve_body( $response ), true )['logs'] ?? array();
	$received = array_values( array_filter( $logs, static fn( array $log ): bool => $name === $log['webhook_name'] && 'crash' === ( $log['body']['payload']['version'] ?? '' ) ) );
	wpwf_require( 2 === count( $received ), 'Expected the acknowledged HTTP request and its recovery redelivery.' );
	wpwf_require( $received[0]['raw_body'] === $received[1]['raw_body'] && $original_id === $received[1]['body']['delivery_id'], 'Recovery changed the body or original ID.' );
	wpwf_require( null === $store->get( $key ), 'Recovered delivery state was not removed.' );
	echo "PASS: hard exit after HTTP recovered with identical JSON and original delivery ID.\n";
} finally {
	foreach ( $workers as $process ) {
		if ( is_resource( $process ) ) {
			proc_terminate( $process, 9 );
			proc_close( $process );
		}
	}
	foreach ( $owned_keys as $key ) {
		foreach ( as_get_scheduled_actions(
			array(
				'hook'     => Update_Stack::HOOK,
				'args'     => array( 'key' => $key ),
				'per_page' => 100,
			),
			'ids'
		) as $action_id ) {
			ActionScheduler::store()->delete_action( (int) $action_id );
		}
		$store->delete( $key );
	}
}
