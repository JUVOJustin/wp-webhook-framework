<?php
/**
 * Covers multiple plugins consuming the framework at once.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

use juvo\WP_Webhook_Framework\Service_Provider;

/**
 * Verifies separate plugins can share the framework without registry conflicts.
 */
final class MultiPluginWebhookTest extends WPWF_Webhook_Test_Case {

	/**
	 * Ensures both fixture plugins stay active and dispatch their own webhook copies.
	 */
	public function test_two_plugins_can_use_the_library_without_errors(): void {
		$registry = Service_Provider::get_registry();

		$this->assertTrue( $registry->has( 'post' ) );
		$this->assertTrue( $registry->has( 'post_secondary' ) );

		$post_id = self::factory()->post->create(
			array(
				'post_title' => 'Shared event',
			)
		);

		$post_actions = $this->get_scheduled_webhook_actions();

		$this->assertCount( 2, $post_actions );
		$this->assertCount( 1, $this->get_scheduled_actions_by_webhook_name( 'post' ) );
		$this->assertCount( 1, $this->get_scheduled_actions_by_webhook_name( 'post_secondary' ) );

		$this->run_scheduled_webhooks();

		$requests      = $this->get_captured_requests();
		$webhook_names = array_column( $requests, 'webhook_name' );
		sort( $webhook_names );

		$this->assertSame( array( 'post', 'post_secondary' ), $webhook_names );
		$this->assertSame( $post_id, $this->get_captured_request_by_webhook_name( 'post' )['body']['id'] );
		$this->assertSame( $post_id, $this->get_captured_request_by_webhook_name( 'post_secondary' )['body']['id'] );
	}
}
