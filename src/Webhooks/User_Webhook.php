<?php
/**
 * User webhook implementation.
 *
 * @package Citation\WP_Webhook_Framework\Webhooks
 */

declare(strict_types=1);

namespace Citation\WP_Webhook_Framework\Webhooks;

use Citation\WP_Webhook_Framework\Webhook;
use Citation\WP_Webhook_Framework\Entities\User;

/**
 * User webhook implementation with configuration capabilities.
 *
 * Handles user-related webhook events with configurable retry policies,
 * timeouts, and other webhook-specific settings.
 */
class User_Webhook extends Webhook {

	/**
	 * The user handler instance.
	 *
	 * @var User
	 */
	private User $user_handler;

	/**
	 * Constructor.
	 *
	 * @param string $name The webhook name.
	 * @phpstan-param non-empty-string $name
	 */
	public function __construct( string $name = 'user' ) {
		parent::__construct( $name );

		// Get dispatcher from registry
		$this->user_handler = new User();
	}

	/**
	 * Initialize the webhook by registering WordPress hooks.
	 */
	public function init(): void {
		add_action( 'user_register', array( $this, 'on_user_register' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 1 );
		add_action( 'deleted_user', array( $this, 'on_deleted_user' ), 10, 1 );
	}

	/**
	 * Handle user registration event.
	 *
	 * @param int $user_id The user ID.
	 */
	public function on_user_register( int $user_id ): void {
		$payload = $this->user_handler->prepare_payload( $user_id );
		$this->emit( 'create', 'user', $user_id, $payload );
	}

	/**
	 * Handle user profile update event.
	 *
	 * @param int $user_id The user ID.
	 */
	public function on_profile_update( int $user_id ): void {
		$payload = $this->user_handler->prepare_payload( $user_id );
		$this->emit( 'update', 'user', $user_id, $payload );
	}

	/**
	 * Handle user deletion event.
	 *
	 * @param int $user_id The user ID.
	 */
	public function on_deleted_user( int $user_id ): void {
		$payload = $this->user_handler->prepare_payload( $user_id );
		$this->emit( 'delete', 'user', $user_id, $payload );
	}

	/**
	 * Get the user handler instance.
	 *
	 * @return User
	 */
	public function get_handler(): User {
		return $this->user_handler;
	}
}
