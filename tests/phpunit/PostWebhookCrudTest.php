<?php
/**
 * Covers post lifecycle webhook behaviour.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

/**
 * Verifies post webhooks dispatch stable CRUD payloads for multiple consumers.
 */
final class PostWebhookCrudTest extends WPWF_Webhook_Test_Case {

	/**
	 * Ensures post create, update, and delete events dispatch through both fixture consumers.
	 */
	public function test_post_create_update_and_delete_webhooks(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title' => 'Initial title',
				'post_name'  => 'initial-title',
				'post_type'  => 'post',
			)
		);

		$primary_actions   = $this->get_scheduled_actions_by_webhook_name( 'post' );
		$secondary_actions = $this->get_scheduled_actions_by_webhook_name( 'post_secondary' );

		$this->assertCount( 1, $primary_actions );
		$this->assertCount( 1, $secondary_actions );
		$this->assertSame( 'create', $primary_actions[0]['action'] );
		$this->assertSame( 'post', $primary_actions[0]['entity'] );
		$this->assertSame( $post_id, $primary_actions[0]['id'] );

		$this->run_scheduled_webhooks();

		$primary_request   = $this->get_captured_request_by_webhook_name( 'post' );
		$secondary_request = $this->get_captured_request_by_webhook_name( 'post_secondary' );

		$this->assertSame( $this->get_receiver_webhook_url( 'primary-post' ), $primary_request['url'] );
		$this->assertSame( 'create', $primary_request['body']['action'] );
		$this->assertSame( 'post', $primary_request['body']['entity'] );
		$this->assertSame( $post_id, $primary_request['body']['id'] );
		$this->assertSame( 'post', $primary_request['body']['post_type'] );
		$this->assertSame( 'initial-title', $primary_request['body']['slug'] );
		$this->assertStringContainsString( '/wp/v2/posts/' . $post_id, $primary_request['body']['rest_url'] );

		$this->assertSame( $this->get_receiver_webhook_url( 'secondary-post' ), $secondary_request['url'] );
		$this->assertSame( 'create', $secondary_request['body']['action'] );
		$this->assertSame( $post_id, $secondary_request['body']['id'] );

		$this->reset_captured_requests();

		\wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Updated title',
				'post_name'  => 'published-slug',
			)
		);

		$primary_actions   = $this->get_scheduled_actions_by_webhook_name( 'post' );
		$secondary_actions = $this->get_scheduled_actions_by_webhook_name( 'post_secondary' );

		$this->assertCount( 1, $primary_actions );
		$this->assertCount( 1, $secondary_actions );
		$this->assertSame( 'update', $primary_actions[0]['action'] );

		$this->run_scheduled_webhooks();

		$primary_request   = $this->get_captured_request_by_webhook_name( 'post' );
		$secondary_request = $this->get_captured_request_by_webhook_name( 'post_secondary' );

		$this->assertSame( 'update', $primary_request['body']['action'] );
		$this->assertSame( 'post', $primary_request['body']['post_type'] );
		$this->assertSame( 'published-slug', $primary_request['body']['slug'] );
		$this->assertSame( 'update', $secondary_request['body']['action'] );

		$this->reset_captured_requests();

		\wp_delete_post( $post_id, true );

		$primary_actions   = $this->get_scheduled_actions_by_webhook_name( 'post' );
		$secondary_actions = $this->get_scheduled_actions_by_webhook_name( 'post_secondary' );

		$this->assertCount( 1, $primary_actions );
		$this->assertCount( 1, $secondary_actions );
		$this->assertSame( 'delete', $primary_actions[0]['action'] );

		$this->run_scheduled_webhooks();

		$primary_request   = $this->get_captured_request_by_webhook_name( 'post' );
		$secondary_request = $this->get_captured_request_by_webhook_name( 'post_secondary' );

		$this->assertSame( 'delete', $primary_request['body']['action'] );
		$this->assertSame( 'post', $primary_request['body']['entity'] );
		$this->assertSame( $post_id, $primary_request['body']['id'] );
		$this->assertSame( 'post', $primary_request['body']['post_type'] );
		$this->assertSame( 'published-slug', $primary_request['body']['slug'] );
		$this->assertSame( 'delete', $secondary_request['body']['action'] );
	}

	/**
	 * Receivers know the public slug even when WordPress renamed the trashed post.
	 *
	 * @dataProvider trashed_post_slugs
	 * @param string $status Original post status.
	 * @param string $slug   Original public slug.
	 */
	public function test_delete_from_trash_sends_original_slug( string $status, string $slug ): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => $status,
				'post_name'   => $slug,
			)
		);

		\wp_trash_post( $post_id );
		$this->run_scheduled_webhooks();
		$this->reset_captured_requests();

		\wp_delete_post( $post_id, true );
		$this->run_scheduled_webhooks();

		$request = $this->get_captured_request_by_webhook_name( 'post' );
		$this->assertSame( 'delete', $request['body']['action'] );
		$this->assertSame( 'page', $request['body']['post_type'] );
		$this->assertSame( $slug, $request['body']['slug'] );
	}

	/**
	 * Trashing adds a suffix even when a draft has no public slug.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function trashed_post_slugs(): array {
		return array(
			'published page' => array( 'publish', 'about-us' ),
			'slugless draft' => array( 'draft', '' ),
		);
	}
}
