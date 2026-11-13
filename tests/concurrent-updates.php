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
use juvo\WP_Webhook_Framework\Update_Queue;
use juvo\WP_Webhook_Framework\Update_Store;
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

$run_id  = $argv[1] ?? wp_generate_uuid4();
$name    = 'concurrent_' . $run_id;
$url     = 'http://tests-wordpress/index.php?rest_route=/wpwf-test/v1/receive/success/' . $name;
$webhook = new class( $name ) extends Webhook {

	/** Explicit scheduling keeps unrelated entity hooks out of the probe. */
	public function init(): void {}
};
$webhook->webhook_url( $url )->max_retries( 0 );
Service_Provider::get_registry()->register( $webhook );
$dispatcher = Service_Provider::get_dispatcher();

if ( isset( $argv[2], $argv[3] ) ) {
	// A shared start barrier makes all eight PHP workers contend for one identity.
	$delay = (float) $argv[2] - microtime( true );
	if ( 0 < $delay ) {
		usleep( (int) ( $delay * 1000000 ) );
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

$workers = array();
$key     = null;
$store   = new Update_Store();
$start   = (string) ( microtime( true ) + 5 );
try {
	for ( $worker = 0; $worker < 8; ++$worker ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Separate PHP processes are the behavior under test.
		$process = proc_open(
			array( PHP_BINARY, __FILE__, $run_id, $start, (string) $worker ),
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

	// The final accepted update gives an unambiguous expected winner after the race.
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
	$ids = as_get_scheduled_actions(
		array(
			'hook'     => Update_Queue::HOOK,
			'args'     => array( 'key' => $key ),
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 100,
		),
		'ids'
	);
	wpwf_require( 1 === count( $ids ), 'Concurrent writers created duplicate scheduler actions.' );
	wpwf_require( 'latest' === $store->get( $key )['payload']['version'], 'Latest payload was lost.' );
	( new ActionScheduler_QueueRunner( ActionScheduler::store() ) )->process_action( (int) $ids[0], 'wpwf-concurrency' );
	wpwf_require( ActionScheduler_Store::STATUS_COMPLETE === ActionScheduler::store()->get_status( (int) $ids[0] ), 'Delivery did not complete.' );
	wpwf_require( null === $store->get( $key ), 'Acknowledged state was not removed.' );
	$response = wp_remote_post( 'http://tests-wordpress/index.php?rest_route=/wpwf-test/v1/logs' );
	$logs     = json_decode( wp_remote_retrieve_body( $response ), true )['logs'] ?? array();
	$received = array_values( array_filter( $logs, static fn( array $log ): bool => $name === $log['webhook_name'] ) );
	wpwf_require( 1 === count( $received ) && 'latest' === $received[0]['body']['version'], 'Receiver did not get exactly one latest-state notification.' );
	echo "PASS: 8 concurrent writers, no enqueue errors, 1 action, latest payload delivered, state cleaned up.\n";
} finally {
	foreach ( $workers as $process ) {
		if ( is_resource( $process ) ) {
			proc_terminate( $process, 9 );
			proc_close( $process );
		}
	}
	if ( null !== $key ) {
		foreach ( as_get_scheduled_actions(
			array(
				'hook'     => Update_Queue::HOOK,
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
