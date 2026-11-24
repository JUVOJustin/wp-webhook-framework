<?php
/**
 * Covers the extension points used by existing entity integrations.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

use juvo\WP_Webhook_Framework\Entities\Post;
use juvo\WP_Webhook_Framework\Entities\Term;
use juvo\WP_Webhook_Framework\Entities\User;
use juvo\WP_Webhook_Framework\Webhooks\Post_Webhook;
use juvo\WP_Webhook_Framework\Webhooks\Term_Webhook;
use juvo\WP_Webhook_Framework\Webhooks\User_Webhook;

/**
 * Protects the public signatures that consumers can override or call by named argument.
 */
final class EntitySignatureCompatibilityTest extends WPWF_Webhook_Test_Case {

	/**
	 * Additional optional parameters on a base class would invalidate existing overrides in PHP.
	 */
	public function test_entity_methods_keep_their_original_parameters(): void {
		$methods = array(
			array( Post::class, 'prepare_payload', array( 'post_id' => 'int' ), 'array' ),
			array( Term::class, 'prepare_payload', array( 'term_id' => 'int' ), 'array' ),
			array( User::class, 'prepare_payload', array( 'user_id' => 'int' ), 'array' ),
			array( Post_Webhook::class, 'on_delete_post', array( 'post_id' => 'int' ), 'void' ),
			array(
				Term_Webhook::class,
				'on_deleted_term',
				array(
					'term_id'  => 'int',
					'tt_id'    => 'int',
					'taxonomy' => 'string',
				),
				'void',
			),
			array( User_Webhook::class, 'on_deleted_user', array( 'user_id' => 'int' ), 'void' ),
		);

		foreach ( $methods as [ $class, $name, $parameters, $return_type ] ) {
			$method = new ReflectionMethod( $class, $name );
			$actual = array();
			foreach ( $method->getParameters() as $parameter ) {
				$actual[ $parameter->getName() ] = (string) $parameter->getType();
			}
			$this->assertSame( $parameters, $actual, $class . '::' . $name );
			$this->assertSame( $return_type, (string) $method->getReturnType(), $class . '::' . $name );
		}
	}

	/**
	 * Register a consumer override with its original signature and exercise WordPress deletion.
	 */
	public function test_wordpress_invokes_the_existing_user_delete_override(): void {
		$user_id = self::factory()->user->create();
		$webhook = new class() extends User_Webhook {
			/**
			 * User ID observed by the consumer.
			 *
			 * @var int|null
			 */
			public ?int $deleted_id = null;

			/**
			 * Record the notification without sending another webhook.
			 *
			 * @param int $user_id The deleted user's ID.
			 */
			public function on_deleted_user( int $user_id ): void {
				$this->deleted_id = $user_id;
			}
		};

		$webhook->init();
		try {
			wp_delete_user( $user_id );
			$this->assertSame( $user_id, $webhook->deleted_id );
		} finally {
			remove_action( 'user_register', array( $webhook, 'on_user_register' ) );
			remove_action( 'profile_update', array( $webhook, 'on_profile_update' ) );
			remove_action( 'deleted_user', array( $webhook, 'on_deleted_user' ) );
		}
	}
}
