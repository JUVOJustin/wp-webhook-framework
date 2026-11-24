<?php
/**
 * Covers application-level meta webhook behavior.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

use juvo\WP_Webhook_Framework\Service_Provider;
use juvo\WP_Webhook_Framework\Webhooks\Meta_Emission_Mode;
use juvo\WP_Webhook_Framework\Webhooks\Meta_Webhook;

/**
 * Verifies meta webhook emission modes and delivery payloads.
 */
final class MetaWebhookTest extends WPWF_Webhook_Test_Case {

	/**
	 * Ensures the default BOTH mode emits meta and parent post updates.
	 */
	public function test_post_meta_both_mode_dispatches_meta_and_parent_update(): void {
		$meta_webhook = $this->get_meta_webhook();
		$meta_webhook->emission_mode( Meta_Emission_Mode::BOTH );

		$post_id = self::factory()->post->create(
			array(
				'post_title' => 'Meta post',
				'post_name'  => 'original-post-slug',
			)
		);

		add_post_meta( $post_id, 'favorite_color', 'red', true );
		$this->clear_scheduled_webhooks();
		$this->reset_captured_requests();

		update_post_meta( $post_id, 'favorite_color', 'blue' );
		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_name' => 'renamed-before-delivery',
			)
		);

		$this->assertCount( 1, $this->get_scheduled_actions_by_webhook_name( 'meta' ) );
		$this->assertCount( 1, $this->get_scheduled_actions_by_webhook_name( 'post' ) );

		$this->run_scheduled_webhooks();

		$meta_request = $this->get_captured_request_by_webhook_name( 'meta' );
		$post_request = $this->get_captured_request_by_webhook_name( 'post' );

		$this->assertSame( 'update', $meta_request['body']['action'] );
		$this->assertSame( 'meta', $meta_request['body']['entity'] );
		$this->assertSame( 'post', $meta_request['body']['meta_type'] );
		$this->assertSame( 'favorite_color', $meta_request['body']['meta_key'] );
		$this->assertSame( 'post', $meta_request['body']['post_type'] );
		$this->assertSame( 'original-post-slug', $meta_request['body']['slug'] );
		$this->assertStringContainsString( '/wp/v2/posts/' . $post_id, $meta_request['body']['rest_url'] );

		$this->assertSame( 'update', $post_request['body']['action'] );
		$this->assertSame( 'post', $post_request['body']['entity'] );
		$this->assertSame( $post_id, $post_request['body']['id'] );
		$this->assertSame( 'original-post-slug', $post_request['body']['slug'] );
	}

	/** A deletion spanning all posts must never borrow the current page's identity. */
	public function test_bulk_post_meta_delete_has_no_unrelated_identity(): void {
		$meta_webhook = $this->get_meta_webhook();
		$meta_webhook->emission_mode( Meta_Emission_Mode::BOTH );

		$post_id = self::factory()->post->create();
		add_post_meta( $post_id, 'shared_color', 'red' );
		$this->go_to( get_permalink( self::factory()->post->create() ) );
		$this->assertInstanceOf( WP_Post::class, get_post( 0 ) );
		$this->clear_scheduled_webhooks();

		delete_post_meta_by_key( 'shared_color' );
		$this->run_scheduled_webhooks();
		foreach ( array( 'meta', 'post' ) as $webhook_name ) {
			$request = $this->get_captured_request_by_webhook_name( $webhook_name );
			$this->assertSame( 0, $request['body']['id'] );
			$this->assertArrayNotHasKey( 'post_type', $request['body'] );
			$this->assertArrayNotHasKey( 'slug', $request['body'] );
		}
	}

	/**
	 * Ensures ENTITY mode only emits the parent entity update.
	 */
	public function test_post_meta_entity_mode_only_dispatches_parent_update(): void {
		$meta_webhook = $this->get_meta_webhook();
		$meta_webhook->emission_mode( Meta_Emission_Mode::ENTITY );

		try {
			$post_id = self::factory()->post->create(
				array(
					'post_title' => 'Entity mode post',
				)
			);

			add_post_meta( $post_id, 'entity_only_key', 'before', true );
			$this->clear_scheduled_webhooks();
			$this->reset_captured_requests();

			update_post_meta( $post_id, 'entity_only_key', 'after' );

			$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( 'meta' ) );
			$this->assertCount( 1, $this->get_scheduled_actions_by_webhook_name( 'post' ) );

			$this->run_scheduled_webhooks();

			$requests = $this->get_captured_requests();
			$this->assertCount( 1, $requests );
			$this->assertSame( 'post', $requests[0]['webhook_name'] );
			$this->assertSame( 'update', $requests[0]['body']['action'] );
		} finally {
			$meta_webhook->emission_mode( Meta_Emission_Mode::BOTH );
		}
	}

	/**
	 * Ensures META mode emits only the meta webhook for term changes.
	 */
	public function test_term_meta_meta_mode_only_dispatches_meta_webhook(): void {
		$meta_webhook = $this->get_meta_webhook();
		$meta_webhook->emission_mode( Meta_Emission_Mode::META );

		try {
			$term = wp_insert_term( 'Meta term', 'category' );
			$this->assertIsArray( $term );
			$term_id = (int) $term['term_id'];

			add_term_meta( $term_id, 'term_color', 'green', true );
			$this->clear_scheduled_webhooks();
			$this->reset_captured_requests();

			update_term_meta( $term_id, 'term_color', 'yellow' );

			$this->assertCount( 1, $this->get_scheduled_actions_by_webhook_name( 'meta' ) );
			$this->assertCount( 0, $this->get_scheduled_actions_by_webhook_name( 'term' ) );

			$this->run_scheduled_webhooks();

			$request = $this->get_captured_request_by_webhook_name( 'meta' );
			$this->assertSame( 'update', $request['body']['action'] );
			$this->assertSame( 'term', $request['body']['meta_type'] );
			$this->assertSame( 'term_color', $request['body']['meta_key'] );
			$this->assertSame( 'category', $request['body']['taxonomy'] );
			$this->assertSame( 'meta-term', $request['body']['slug'] );
			$this->assertStringContainsString( '/wp/v2/categories/' . $term_id, $request['body']['rest_url'] );
		} finally {
			$meta_webhook->emission_mode( Meta_Emission_Mode::BOTH );
		}
	}

	/**
	 * Ensures user meta deletion emits the expected delete payloads.
	 */
	public function test_user_meta_delete_dispatches_meta_delete_and_parent_update(): void {
		$meta_webhook = $this->get_meta_webhook();
		$meta_webhook->emission_mode( Meta_Emission_Mode::BOTH );

		$user_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'meta-editor',
			)
		);

		add_user_meta( $user_id, 'profile_badge', 'gold', true );
		$this->clear_scheduled_webhooks();
		$this->reset_captured_requests();

		delete_user_meta( $user_id, 'profile_badge' );

		$this->assertCount( 1, $this->get_scheduled_actions_by_webhook_name( 'meta' ) );
		$this->assertCount( 1, $this->get_scheduled_actions_by_webhook_name( 'user' ) );

		$this->run_scheduled_webhooks();

		$meta_request = $this->get_captured_request_by_webhook_name( 'meta' );
		$user_request = $this->get_captured_request_by_webhook_name( 'user' );

		$this->assertSame( 'delete', $meta_request['body']['action'] );
		$this->assertSame( 'user', $meta_request['body']['meta_type'] );
		$this->assertSame( 'profile_badge', $meta_request['body']['meta_key'] );
		$this->assertSame( array( 'editor' ), $meta_request['body']['roles'] );
		$this->assertSame( 'meta-editor', $meta_request['body']['username'] );
		$this->assertArrayNotHasKey( 'slug', $meta_request['body'] );
		$this->assertStringContainsString( '/wp/v2/users/' . $user_id, $meta_request['body']['rest_url'] );

		$this->assertSame( 'update', $user_request['body']['action'] );
		$this->assertSame( 'user', $user_request['body']['entity'] );
		$this->assertSame( $user_id, $user_request['body']['id'] );
	}

	/**
	 * Returns the shared fixture meta webhook.
	 *
	 * @return Meta_Webhook
	 */
	private function get_meta_webhook(): Meta_Webhook {
		$webhook = Service_Provider::get_registry()->get( 'meta' );

		$this->assertInstanceOf( Meta_Webhook::class, $webhook );

		return $webhook;
	}
}
