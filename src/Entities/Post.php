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
	 * @return array<string,mixed> The prepared payload data containing post type and REST URL if supported.
	 */
	public function prepare_payload( int $post_id ): array {
		$post_type = get_post_type( $post_id );
		$payload   = array( 'post_type' => $post_type );

		if ( false === $post_type ) {
			return $payload;
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! $post_type_object->show_in_rest ) {
			return $payload;
		}

		$rest_base      = $post_type_object->rest_base ?: $post_type;
		$rest_namespace = $post_type_object->rest_namespace ?: 'wp/v2';
		$payload['rest_url'] = rest_url( "{$rest_namespace}/{$rest_base}/{$post_id}" );

		return $payload;
	}
}
