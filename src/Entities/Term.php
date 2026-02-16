<?php
/**
 * Term entity handler for handling term-related webhook events.
 *
 * @package juvo\WP_Webhook_Framework\Entities
 */

namespace juvo\WP_Webhook_Framework\Entities;

use WP_Term;

/**
 * Term entity handler.
 *
 * Transforms term data into webhook payloads.
 */
class Term extends Entity_Handler {

	/**
	 * Prepare payload for a term.
	 *
	 * Persists minimal event context for async delivery.
	 *
	 * @param int $term_id The term ID.
	 * @return array<string,mixed> The prepared payload data containing the term ID.
	 */
	public function prepare_payload( int $term_id ): array {
		return array( 'id' => $term_id );
	}

	/**
	 * Enrich payload data at delivery time.
	 *
	 * @param int                 $entity_id The term ID.
	 * @param array<string,mixed> $payload The scheduled payload data.
	 * @return array<string,mixed> The updated payload data.
	 */
	public function prepare_delivery_payload( int $entity_id, array $payload ): array {
		$taxonomy = $payload['taxonomy'] ?? '';
		if ( ! is_string( $taxonomy ) || '' === $taxonomy ) {
			$term = get_term( $entity_id );
			if ( ! ( $term instanceof WP_Term ) ) {
				return $payload;
			}
			$taxonomy = $term->taxonomy;
		}

		if ( ! is_string( $taxonomy ) || '' === $taxonomy ) {
			return $payload;
		}

		$payload['taxonomy'] = $taxonomy;

		if ( ! empty( $payload['rest_url'] ) ) {
			return $payload;
		}

		$taxonomy_object = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_object || true !== $taxonomy_object->show_in_rest ) {
			return $payload;
		}

		$rest_base = $taxonomy_object->rest_base;
		if ( empty( $rest_base ) ) {
			$rest_base = $taxonomy;
		}

		$rest_namespace = $taxonomy_object->rest_namespace;
		if ( empty( $rest_namespace ) ) {
			$rest_namespace = 'wp/v2';
		}

		$payload['rest_url'] = rest_url( "{$rest_namespace}/{$rest_base}/{$entity_id}" );
		return $payload;
	}
}
