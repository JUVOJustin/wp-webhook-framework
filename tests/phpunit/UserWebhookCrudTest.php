<?php
/**
 * Covers user lifecycle webhook behaviour.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

/**
 * Verifies user webhooks dispatch stable CRUD payloads.
 */
final class UserWebhookCrudTest extends WPWF_Webhook_Test_Case {

	/**
	 * Ensures user create, update, and delete events dispatch stable payloads.
	 */
	public function test_user_create_update_and_delete_webhooks(): void {
		$filter_user_requests = static function ( array $requests ): array {
			return array_values(
				array_filter(
					$requests,
					static fn( array $request ): bool => 'user' === (string) ( $request['webhook_name'] ?? '' )
				)
			);
		};

		$user_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_login' => 'fixture-editor',
			)
		);

		$actions = $this->get_scheduled_actions_by_webhook_name( 'user' );

		$this->assertNotEmpty( $actions );
		$this->assertContains( 'create', array_column( $actions, 'action' ) );

		$this->run_scheduled_webhooks();

		$requests       = $filter_user_requests( $this->get_captured_requests() );
		$create_request = array_values(
			array_filter(
				$requests,
				static fn( array $request ): bool => 'create' === (string) ( $request['body']['action'] ?? '' )
			)
		);

		$this->assertNotEmpty( $create_request );
		$request = $create_request[0];

		$this->assertSame( $this->get_receiver_webhook_url( 'primary-user' ), $request['url'] );
		$this->assertSame( 'create', $request['body']['action'] );
		$this->assertSame( array( 'editor' ), $request['body']['roles'] );
		$this->assertSame( 'fixture-editor', $request['body']['username'] );
		$this->assertArrayNotHasKey( 'slug', $request['body'] );
		$this->assertStringContainsString( '/wp/v2/users/' . $user_id, $request['body']['rest_url'] );

		$this->reset_captured_requests();

		\wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => 'Updated editor',
			)
		);

		$actions = $this->get_scheduled_actions_by_webhook_name( 'user' );
		$this->assertNotEmpty( $actions );
		$this->assertContains( 'update', array_column( $actions, 'action' ) );

		$this->run_scheduled_webhooks();

		$requests = $filter_user_requests( $this->get_captured_requests() );
		$request  = array_values(
			array_filter(
				$requests,
				static fn( array $candidate ): bool => 'update' === (string) ( $candidate['body']['action'] ?? '' )
			)
		)[0];
		$this->assertSame( 'update', $request['body']['action'] );
		$this->assertSame( array( 'editor' ), $request['body']['roles'] );
		$this->assertSame( 'fixture-editor', $request['body']['username'] );
		$this->assertArrayNotHasKey( 'slug', $request['body'] );

		$this->reset_captured_requests();

		\wp_delete_user( $user_id );

		$actions = $this->get_scheduled_actions_by_webhook_name( 'user' );
		$this->assertNotEmpty( $actions );
		$this->assertContains( 'delete', array_column( $actions, 'action' ) );

		$this->run_scheduled_webhooks();

		$requests = $filter_user_requests( $this->get_captured_requests() );
		$request  = array_values(
			array_filter(
				$requests,
				static fn( array $candidate ): bool => 'delete' === (string) ( $candidate['body']['action'] ?? '' )
			)
		)[0];
		$this->assertSame( 'delete', $request['body']['action'] );
		$this->assertSame( 'user', $request['body']['entity'] );
		$this->assertSame( $user_id, $request['body']['id'] );
		$this->assertSame( array( 'editor' ), $request['body']['roles'] );
		$this->assertSame( 'fixture-editor', $request['body']['username'] );
		$this->assertArrayNotHasKey( 'slug', $request['body'] );

		$meta_request = $this->get_captured_request_by_webhook_name( 'meta' );
		$this->assertSame( 'delete', $meta_request['body']['action'] );
		$this->assertSame( 'user', $meta_request['body']['meta_type'] );
		$this->assertSame( 'fixture-editor', $meta_request['body']['username'] );
		$this->assertArrayNotHasKey( 'slug', $meta_request['body'] );
	}
}
