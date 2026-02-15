<?php
/**
 * Post webhook implementation.
 *
 * @package juvo\WP_Webhook_Framework\Webhooks
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework\Webhooks;

use juvo\WP_Webhook_Framework\Webhook;
use juvo\WP_Webhook_Framework\Entities\Post;

/**
 * Post webhook implementation with configuration capabilities.
 *
 * Handles post-related webhook events with configurable retry policies,
 * timeouts, and other webhook-specific settings.
 */
class Post_Webhook extends Webhook {

	/**
	 * The post handler instance.
	 *
	 * @var Post
	 */
	private Post $post_handler;

	/**
	 * Constructor.
	 *
	 * @param string $name The webhook name.
	 * @phpstan-param non-empty-string $name
	 */
	public function __construct( string $name = 'post' ) {
		parent::__construct( $name );

		// Get dispatcher from registry
		$this->post_handler = new Post();
	}

	/**
	 * Initialize the webhook by registering WordPress hooks.
	 */
	public function init(): void {
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ), 10, 1 );
	}

	/**
	 * Handle post save event (create/update).
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 * @param bool     $update  Whether this is an update or new post.
	 */
	public function on_save_post( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$action  = $update ? 'update' : 'create';
		$payload = $this->post_handler->prepare_payload( $post_id );
		$this->emit( $action, 'post', $post_id, $payload );
	}

	/**
	 * Handle post deletion event.
	 *
	 * @param int $post_id The post ID.
	 */
	public function on_delete_post( int $post_id ): void {
		$payload = $this->post_handler->prepare_payload( $post_id );
		$this->emit( 'delete', 'post', $post_id, $payload );
	}

	/**
	 * Get the post handler instance.
	 *
	 * @return Post
	 */
	public function get_handler(): Post {
		return $this->post_handler;
	}
}
