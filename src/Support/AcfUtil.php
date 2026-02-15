<?php
/**
 * Utility class for parsing ACF object IDs.
 *
 * @package juvo\WP_Webhook_Framework\Support
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework\Support;

/**
 * Parses ACF object IDs into entity + numeric id.
 */
final class AcfUtil {

	/**
	 * Parse ACF object ID into entity type and numeric ID.
	 *
	 * @param int|string $object_id The ACF object ID to parse.
	 * @return array{0:string|null,1:int|null} Array with entity type and ID.
	 */
	public static function parse_object_id( int|string $object_id ): array {
		if ( is_numeric( $object_id ) ) {
			return array( 'post', (int) $object_id );
		}

		// At this point, $object_id is not numeric, so it must be a string
		if ( preg_match( '/^post_(\d+)$/', $object_id, $m ) ) {
			return array( 'post', (int) $m[1] );
		}

		if ( preg_match( '/^term_(\d+)$/', $object_id, $m ) ) {
			return array( 'term', (int) $m[1] );
		}

		if ( preg_match( '/^user_(\d+)$/', $object_id, $m ) ) {
			return array( 'user', (int) $m[1] );
		}

		return array( null, null );
	}
}
