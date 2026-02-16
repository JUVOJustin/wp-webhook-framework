<?php
/**
 * Meta entity handler for handling meta-related webhook events.
 *
 * @package juvo\WP_Webhook_Framework\Entities
 */

namespace juvo\WP_Webhook_Framework\Entities;

/**
 * Meta entity handler.
 *
 * Provides utilities for detecting meta changes, filtering excluded keys,
 * and preparing payloads for meta-related webhooks.
 */
class Meta extends Entity_Handler {

	/**
	 * Post handler instance.
	 *
	 * @var Post
	 */
	private Post $post_handler;

	/**
	 * Term handler instance.
	 *
	 * @var Term
	 */
	private Term $term_handler;

	/**
	 * User handler instance.
	 *
	 * @var User
	 */
	private User $user_handler;

	/**
	 * Constructor for Meta handler.
	 *
	 * Initializes the Meta handler with a dispatcher and entity handlers.
	 */
	public function __construct() {
		$this->post_handler = new Post();
		$this->term_handler = new Term();
		$this->user_handler = new User();
	}

	/**
	 * Determine if a metadata update represents a deletion.
	 *
	 * @param mixed $new_value The new value.
	 * @param mixed $old_value The old value.
	 * @return bool True if this represents a deletion.
	 */
	public function is_deletion( mixed $new_value, mixed $old_value ): bool {
		// If new value is empty/null and old value existed, it's a deletion
		return empty( $new_value ) && ! empty( $old_value );
	}

	/**
	 * Check if a meta key should be excluded from webhook emission.
	 *
	 * @param string $meta_key   The meta key to check.
	 * @param string $meta_type  The meta type (post, term, user).
	 * @param int    $object_id  The object ID.
	 * @return bool True if the meta key should be excluded.
	 */
	public function is_meta_key_excluded( string $meta_key, string $meta_type, int $object_id ): bool {

		$excluded_keys = array(
			'_edit_lock',
			'_edit_last',
			'session_tokens',
		);

		$excluded = in_array( $meta_key, $excluded_keys, true ) || str_starts_with( $meta_key, '_' );

		/**
		 * Filter the list of meta keys that should be excluded from webhook emission.
		 *
		 * @since 1.0.0
		 *
		 * @param bool          $excluded      Boolean value if meta field should be excluded or not.
		 * @param string        $meta_key      The current meta key being processed.
		 * @param string        $meta_type     The meta type (post, term, user).
		 * @param int           $object_id     The object ID.
		 */
		return apply_filters(
			'wpwf_excluded_meta',
			$excluded,
			$meta_key,
			$meta_type,
			$object_id
		);
	}

	/**
	 * Prepare payload for a meta update.
	 *
	 * @param string $meta_type The meta type (post, term, user).
	 * @param int    $object_id The object ID.
	 * @param string $meta_key  The meta key.
	 * @return array<string,mixed> The prepared payload data with meta_type and meta_key included.
	 */
	public function prepare_payload( string $meta_type, int $object_id, string $meta_key ): array {
		return array(
			'meta_type' => $meta_type,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Not a database query, just webhook payload data
			'meta_key'  => $meta_key,
		);
	}

	/**
	 * Enrich payload data at delivery time.
	 *
	 * @param int                 $entity_id The object ID.
	 * @param array<string,mixed> $payload   The scheduled payload data.
	 * @return array<string,mixed> The updated payload data.
	 */
	public function prepare_delivery_payload( int $entity_id, array $payload ): array {
		$meta_type = $payload['meta_type'] ?? '';
		if ( is_string( $meta_type ) && '' !== $meta_type ) {
			return match ( $meta_type ) {
				'post' => $this->post_handler->prepare_delivery_payload( $entity_id, $payload ),
				'term' => $this->term_handler->prepare_delivery_payload( $entity_id, $payload ),
				'user' => $this->user_handler->prepare_delivery_payload( $entity_id, $payload ),
				default => $payload,
			};
		}

		$post_type = $payload['post_type'] ?? '';
		if ( is_string( $post_type ) && '' !== $post_type ) {
			$payload['meta_type'] = 'post';
			return $this->post_handler->prepare_delivery_payload( $entity_id, $payload );
		}

		$taxonomy = $payload['taxonomy'] ?? '';
		if ( is_string( $taxonomy ) && '' !== $taxonomy ) {
			$payload['meta_type'] = 'term';
			return $this->term_handler->prepare_delivery_payload( $entity_id, $payload );
		}

		if ( array_key_exists( 'roles', $payload ) ) {
			$payload['meta_type'] = 'user';
			return $this->user_handler->prepare_delivery_payload( $entity_id, $payload );
		}

		return $payload;
	}

	/**
	 * Get the entity payload (without meta_key) for triggering parent entity updates.
	 *
	 * @param string $meta_type The meta type (post, term, user).
	 * @param int    $object_id The object ID.
	 * @return array<string,mixed> The entity payload data.
	 */
	public function get_entity_payload( string $meta_type, int $object_id ): array {
		return match ( $meta_type ) {
			'post' => $this->post_handler->prepare_payload( $object_id ),
			'term' => $this->term_handler->prepare_payload( $object_id ),
			'user' => $this->user_handler->prepare_payload( $object_id ),
			default => array(),
		};
	}
}
