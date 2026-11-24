<?php
/**
 * Post entity handler for handling post-related webhook events.
 *
 * @package juvo\WP_Webhook_Framework\Entities
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework\Entities;

/**
 * Post entity handler.
 *
 * Transforms post data into webhook payloads.
 */
class Post extends Entity_Handler {

	/**
	 * Prepare payload for a post.
	 *
	 * Capture the post type and slug while the entity is available.
	 *
	 * @param int           $post_id The post ID.
	 * @param \WP_Post|null $post    Object retained by the event hook.
	 * @return array<string,mixed> The prepared payload data.
	 */
	public function prepare_payload( int $post_id, ?\WP_Post $post = null ): array {
		$post ??= 0 < $post_id ? get_post( $post_id ) : null;
		if ( null === $post ) {
			return array();
		}

		$slug = $post->post_name;
		if ( 'trash' === $post->post_status ) {
			// A restored slugless draft can retain an earlier empty meta row.
			$original_slugs = get_post_meta( $post_id, '_wp_desired_post_slug', false );
			$original_slug  = is_array( $original_slugs ) ? end( $original_slugs ) : false;
			if ( is_string( $original_slug ) ) {
				$slug = $original_slug;
			}
		}

		return array(
			'post_type' => $post->post_type,
			'slug'      => $slug,
		);
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
