<?php
/**
 * User entity handler for handling user-related webhook events.
 *
 * @package juvo\WP_Webhook_Framework\Entities
 */

namespace juvo\WP_Webhook_Framework\Entities;

/**
 * User entity handler.
 *
 * Transforms user data into webhook payloads.
 */
class User extends Entity_Handler {

	/**
	 * Prepare payload for a user.
	 *
	 * @param int $user_id The user ID.
	 * @return array<string,mixed> The prepared payload data containing user roles.
	 */
	public function prepare_payload( int $user_id ): array {
		$user  = get_userdata( $user_id );
		$roles = ( $user && $user->roles ) ? array_values( $user->roles ) : array();

		return array( 'roles' => $roles );
	}

	/**
	 * Enrich payload data at delivery time.
	 *
	 * @param int                 $entity_id The user ID.
	 * @param array<string,mixed> $payload The scheduled payload data.
	 * @return array<string,mixed> The updated payload data.
	 */
	public function prepare_delivery_payload( int $entity_id, array $payload ): array {
		if ( ! empty( $payload['rest_url'] ) ) {
			return $payload;
		}

		$payload['rest_url'] = rest_url( "wp/v2/users/{$entity_id}" );
		return $payload;
	}
}
