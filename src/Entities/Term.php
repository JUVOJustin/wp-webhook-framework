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
	 * @param int $term_id The term ID.
	 * @return array<string,mixed> The prepared payload data.
	 */
	public function prepare_payload( int $term_id ): array {
		$term = get_term( $term_id );

		// Return empty payload if term is not found.
		if ( ! is_a( $term, WP_Term::class ) ) {
			return array();
		}

		return array( 'taxonomy' => $term->taxonomy );
	}
}
