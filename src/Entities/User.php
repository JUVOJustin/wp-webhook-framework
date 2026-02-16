<?php
/**
 * User entity handler for handling user-related webhook events.
 *
 * @package juvo\WP_Webhook_Framework\Entities
 */

namespace juvo\WP_Webhook_Framework\Entities;

use WP_User;

/**
 * User entity handler.
 *
 * Transforms user data into webhook payloads.
 */
class User extends Entity_Handler {

	/**
	 * Prepare payload for a user.
	 *
	 * Persists minimal event context for async delivery.
	 *
	 * @param int $user_id The user ID.
	 * @return array<string,mixed> The prepared payload data containing the user ID.
	 */
	public function prepare_payload( int $user_id ): array {
		return array( 'id' => $user_id );
	}

	/**
	 * Enrich payload data at delivery time.
	 *
	 * @param int                 $entity_id The user ID.
	 * @param array<string,mixed> $payload The scheduled payload data.
	 * @return array<string,mixed> The updated payload data.
	 */
	public function prepare_delivery_payload( int $entity_id, array $payload ): array {
		$roles = $payload['roles'] ?? null;
		if ( ! is_array( $roles ) ) {
			$user = get_userdata( $entity_id );
			if ( $user instanceof WP_User ) {
				$payload['roles'] = $user->roles ? array_values( $user->roles ) : array();
			}
		}

		if ( empty( $payload['rest_url'] ) ) {
			$payload['rest_url'] = rest_url( "wp/v2/users/{$entity_id}" );
		}
		return $payload;
	}
}
