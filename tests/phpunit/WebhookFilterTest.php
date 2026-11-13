<?php
/**
 * Covers filter-driven webhook behavior.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

use juvo\WP_Webhook_Framework\Delivery_Mode;

/**
 * Verifies runtime filters can reroute, modify, or suppress deliveries.
 */
final class WebhookFilterTest extends WPWF_Webhook_Test_Case {

	/**
	 * Ensures payload filters can prevent scheduling entirely.
	 */
	public function test_payload_filter_can_prevent_custom_webhook_emission(): void {
		$payload_filter = static function ( array $payload, string $entity, string $id ) {
			if ( 'custom' !== $entity || 'job-filter-prevent' !== $id ) {
				return $payload;
			}

			return false;
		};

		add_filter( 'wpwf_payload', $payload_filter, 10, 3 );

		try {
			do_action( 'wpwf_test_custom_event', 'job-filter-prevent', array( 'status' => 'queued' ) );

			$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( 'custom_primary' ) );
			$this->assertCount( 0, $this->get_captured_requests() );
		} finally {
			remove_filter( 'wpwf_payload', $payload_filter, 10 );
		}
	}

	/**
	 * Ensures invalid payload filter output emits a warning and skips delivery.
	 */
	public function test_invalid_payload_filter_value_emits_warning(): void {
		$payload_filter = static function ( array $payload, string $entity, string $id ) {
			if ( 'custom' !== $entity || 'job-filter-invalid' !== $id ) {
				return $payload;
			}

			return 'invalid';
		};

		add_filter( 'wpwf_payload', $payload_filter, 10, 3 );

		$warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Tests assert the documented warning path.
		set_error_handler(
			static function ( int $error_level, string $message ) use ( &$warnings ): bool {
				if ( E_USER_WARNING !== $error_level || ! str_contains( $message, 'Failed to emit webhook' ) ) {
					return false;
				}

				$warnings[] = $message;
				return true;
			}
		);

		try {
			do_action( 'wpwf_test_custom_event', 'job-filter-invalid', array( 'status' => 'queued' ) );

			$this->assertCount( 1, $warnings );
			$this->assertStringContainsString( 'webhook_payload_invalid', $warnings[0] );
			$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( 'custom_primary' ) );
			$this->assertCount( 0, $this->get_captured_requests() );
		} finally {
			restore_error_handler();
			remove_filter( 'wpwf_payload', $payload_filter, 10 );
		}
	}

	/**
	 * Ensures delivery mode can be forced to immediate at runtime.
	 */
	public function test_delivery_mode_filter_can_force_immediate_delivery(): void {
		$delivery_mode_filter = static function ( string $delivery_mode, string $webhook_name ): string {
			if ( 'custom_primary' !== $webhook_name ) {
				return $delivery_mode;
			}

			return Delivery_Mode::IMMEDIATE->value;
		};

		add_filter( 'wpwf_delivery_mode', $delivery_mode_filter, 10, 2 );

		try {
			do_action( 'wpwf_test_custom_event', 'job-filter-immediate', array( 'status' => 'queued' ) );

			$this->assertCount( 1, $this->get_captured_requests() );
			$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( 'custom_primary' ) );
		} finally {
			remove_filter( 'wpwf_delivery_mode', $delivery_mode_filter, 10 );
		}
	}

	/**
	 * Ensures URL, body, and header filters are applied to outgoing requests.
	 */
	public function test_request_filters_modify_routed_delivery(): void {
		$url_filter = function ( string $url, string $entity, string $id ): string {
			if ( 'custom' !== $entity || 'job-filter-routed' !== $id ) {
				return $url;
			}

			return $this->get_receiver_webhook_url( 'filtered-custom-route' );
		};

		$body_filter = static function ( array $body, string $action, string $entity, string $id ): array {
			if ( 'custom' !== $entity || 'job-filter-routed' !== $id ) {
				return $body;
			}

			$body['filtered'] = 'yes';
			return $body;
		};

		$headers_filter = static function ( array $headers, string $entity, string $id, $webhook ): array {
			if ( 'custom_primary' !== $webhook->get_name() || 'job-filter-routed' !== $id ) {
				return $headers;
			}

			$headers['X-Test-Header'] = 'filter-applied';
			return $headers;
		};

		add_filter( 'wpwf_url', $url_filter, 10, 3 );
		add_filter( 'wpwf_request_body', $body_filter, 10, 6 );
		add_filter( 'wpwf_headers', $headers_filter, 10, 4 );

		try {
			do_action( 'wpwf_test_custom_event', 'job-filter-routed', array( 'status' => 'queued' ) );

			$this->assertCount( 1, $this->get_scheduled_actions_by_webhook_name( 'custom_primary' ) );

			$this->run_scheduled_webhooks();

			$request = $this->get_captured_request_by_webhook_name( 'custom_primary' );
			$this->assertSame( $this->get_receiver_webhook_url( 'filtered-custom-route' ), $request['url'] );
			$this->assertSame( 'yes', $request['body']['filtered'] );
			$this->assertSame( 'filter-applied', $request['headers']['x-test-header'] );
		} finally {
			remove_filter( 'wpwf_url', $url_filter, 10 );
			remove_filter( 'wpwf_request_body', $body_filter, 10 );
			remove_filter( 'wpwf_headers', $headers_filter, 10 );
		}
	}

	/**
	 * Ensures meta exclusion filters can suppress otherwise valid meta changes.
	 */
	public function test_excluded_meta_filter_can_skip_public_meta_updates(): void {
		$excluded_meta_filter = static function ( bool $excluded, string $meta_key, string $meta_type, int $object_id ): bool {
			if ( 'post' !== $meta_type || 'skip_me' !== $meta_key || 1 > $object_id ) {
				return $excluded;
			}

			return true;
		};

		add_filter( 'wpwf_excluded_meta', $excluded_meta_filter, 10, 4 );

		try {
			$post_id = self::factory()->post->create(
				array(
					'post_title' => 'Excluded meta post',
				)
			);

			add_post_meta( $post_id, 'skip_me', 'before', true );
			$this->clear_scheduled_webhooks();
			$this->reset_captured_requests();

			update_post_meta( $post_id, 'skip_me', 'after' );

			$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( 'meta' ) );
			$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( 'post' ) );
			$this->assertCount( 0, $this->get_captured_requests() );
		} finally {
			remove_filter( 'wpwf_excluded_meta', $excluded_meta_filter, 10 );
		}
	}
}
