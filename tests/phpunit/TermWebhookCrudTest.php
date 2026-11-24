<?php
/**
 * Covers term lifecycle webhook behaviour.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

/**
 * Verifies term webhooks dispatch stable CRUD payloads.
 */
final class TermWebhookCrudTest extends WPWF_Webhook_Test_Case {

	/**
	 * Ensures term create, update, and delete events dispatch stable payloads.
	 */
	public function test_term_create_update_and_delete_webhooks(): void {
		$term = \wp_insert_term( 'Created category', 'category' );

		$this->assertIsArray( $term );
		$this->assertArrayHasKey( 'term_id', $term );

		$term_id = (int) $term['term_id'];
		$actions = $this->get_scheduled_actions_by_webhook_name( 'term' );

		$this->assertCount( 1, $actions );
		$this->assertSame( 'create', $actions[0]['action'] );
		$this->assertSame( 'term', $actions[0]['entity'] );

		$this->run_scheduled_webhooks();

		$request = $this->get_captured_request_by_webhook_name( 'term' );

		$this->assertSame( $this->get_receiver_webhook_url( 'primary-term' ), $request['url'] );
		$this->assertSame( 'create', $request['body']['action'] );
		$this->assertSame( 'category', $request['body']['taxonomy'] );
		$this->assertSame( 'created-category', $request['body']['slug'] );
		$this->assertStringContainsString( '/wp/v2/categories/' . $term_id, $request['body']['rest_url'] );

		$this->reset_captured_requests();

		\wp_update_term(
			$term_id,
			'category',
			array(
				'name' => 'Updated category',
				'slug' => 'published-slug',
			)
		);

		$actions = $this->get_scheduled_actions_by_webhook_name( 'term' );
		$this->assertCount( 1, $actions );
		$this->assertSame( 'update', $actions[0]['action'] );

		$this->run_scheduled_webhooks();

		$request = $this->get_captured_request_by_webhook_name( 'term' );
		$this->assertSame( 'update', $request['body']['action'] );
		$this->assertSame( 'category', $request['body']['taxonomy'] );
		$this->assertSame( 'published-slug', $request['body']['slug'] );

		$this->reset_captured_requests();

		\wp_delete_term( $term_id, 'category' );

		$actions = $this->get_scheduled_actions_by_webhook_name( 'term' );
		$this->assertCount( 1, $actions );
		$this->assertSame( 'delete', $actions[0]['action'] );

		$this->run_scheduled_webhooks();

		$request = $this->get_captured_request_by_webhook_name( 'term' );
		$this->assertSame( 'delete', $request['body']['action'] );
		$this->assertSame( 'term', $request['body']['entity'] );
		$this->assertSame( $term_id, $request['body']['id'] );
		$this->assertSame( 'category', $request['body']['taxonomy'] );
		$this->assertSame( 'published-slug', $request['body']['slug'] );
	}
}
