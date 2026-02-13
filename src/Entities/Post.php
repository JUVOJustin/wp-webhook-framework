<?php
/**
 * Post entity handler for handling post-related webhook events.
 *
 * @package juvo\WP_Webhook_Framework\Entities
 */

namespace juvo\WP_Webhook_Framework\Entities;

/**
 * Post entity handler.
 *
 * Transforms post data into webhook payloads.
 * Restricted to configured post types (default: empty array).
 */
class Post extends Entity_Handler {

	/**
	 * Prepare payload for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string,mixed> The prepared payload data.
	 */
	public function prepare_payload( int $post_id ): array {
		return array( 'post_type' => get_post_type( $post_id ) );
	}
}
