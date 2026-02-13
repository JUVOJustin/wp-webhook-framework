<?php
/**
 * Notification registry for managing webhook notification handlers.
 *
 * @package juvo\WP_Webhook_Framework
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework\Notifications;

/**
 * Registry for notification handlers.
 *
 * Provides centralized registration and access to notification handlers.
 * Uses singleton pattern to ensure a single registry instance across the application.
 */
class Notification_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var Notification_Registry|null
	 */
	private static ?Notification_Registry $instance = null;

	/**
	 * Registered notification handlers keyed by identifier.
	 *
	 * @var array<string,Notification>
	 */
	private array $notifications = array();

	/**
	 * Private constructor to enforce singleton pattern.
	 */
	private function __construct() {
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return Notification_Registry
	 */
	public static function instance(): Notification_Registry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register a notification handler.
	 *
	 * @param Notification $notification The notification handler to register.
	 * @return bool True if registered successfully, false if already registered.
	 */
	public function register( Notification $notification ): bool {
		$identifier = $notification->get_identifier();

		if ( isset( $this->notifications[ $identifier ] ) ) {
			return false;
		}

		$this->notifications[ $identifier ] = $notification;
		return true;
	}

	/**
	 * Get a notification handler by identifier.
	 *
	 * @param string $identifier The notification identifier.
	 * @return Notification|null The notification handler or null if not found.
	 */
	public function get( string $identifier ): ?Notification {
		return $this->notifications[ $identifier ] ?? null;
	}

	/**
	 * Initialize all registered notification handlers.
	 *
	 * Calls init() on all registered notifications.
	 */
	public function init_all(): void {
		foreach ( $this->notifications as $notification ) {
			$notification->init();
		}
	}

	/**
	 * Get all registered notification identifiers.
	 *
	 * @return string[] Array of notification identifiers.
	 */
	public function get_registered_identifiers(): array {
		return array_keys( $this->notifications );
	}

	/**
	 * Check if a notification handler is registered.
	 *
	 * @param string $identifier The notification identifier.
	 * @return bool True if registered, false otherwise.
	 */
	public function is_registered( string $identifier ): bool {
		return isset( $this->notifications[ $identifier ] );
	}

	/**
	 * Initialize specific notification handlers by identifiers.
	 *
	 * Only initializes notifications that match the provided identifiers.
	 * Used by webhooks to enable specific notifications.
	 *
	 * @param string[] $identifiers Array of notification identifiers to initialize.
	 */
	public function init_selected( array $identifiers ): void {
		foreach ( $identifiers as $identifier ) {
			if ( isset( $this->notifications[ $identifier ] ) ) {
				$this->notifications[ $identifier ]->init();
			}
		}
	}
}
