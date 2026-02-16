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
	 * Persists minimal event context for async delivery.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string,mixed> The prepared payload data.
	 */
	public function prepare_payload( int $post_id ): array {
		return array();
	}

	/**
	 * Enrich payload data at delivery time.
	 *
	 * @param int                 $entity_id The post ID.
	 * @param array<string,mixed> $payload The scheduled payload data.
	 * @return array<string,mixed> The updated payload data.
	 */
	public function prepare_delivery_payload( int $entity_id, array $payload ): array {
		$post_type = $payload['post_type'] ?? '';
		if ( ! is_string( $post_type ) || '' === $post_type ) {
			$post_type = get_post_type( $entity_id );
		}

		if ( ! is_string( $post_type ) || '' === $post_type ) {
			return $payload;
		}

		$payload['post_type'] = $post_type;

		if ( ! empty( $payload['rest_url'] ) ) {
			return $payload;
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || true !== $post_type_object->show_in_rest ) {
			return $payload;
		}

		$rest_base = $post_type_object->rest_base;
		if ( empty( $rest_base ) ) {
			$rest_base = $post_type;
		}

		$rest_namespace = $post_type_object->rest_namespace;
		if ( empty( $rest_namespace ) ) {
			$rest_namespace = 'wp/v2';
		}

		$payload['rest_url'] = rest_url( "{$rest_namespace}/{$rest_base}/{$entity_id}" );
		return $payload;
	}
}
