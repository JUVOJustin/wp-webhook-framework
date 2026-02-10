<?php
/**
 * Webhook registry for managing webhook instances.
 *
 * @package Citation\WP_Webhook_Framework
 */

declare(strict_types=1);

namespace Citation\WP_Webhook_Framework;

/**
 * Registry for managing webhook instances and enabling third-party extensibility.
 *
 * Provides a centralized way to register, configure, and manage webhook instances
 * for both core framework webhooks and third-party extensions.
 */
class Webhook_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var Webhook_Registry|null
	 */
	private static ?Webhook_Registry $instance = null;

	/**
	 * Registered webhooks.
	 *
	 * @var array<string,Webhook>
	 */
	private array $webhooks = array();

	/**
	 * The dispatcher instance.
	 *
	 * @var Dispatcher
	 */
	private Dispatcher $dispatcher;

	/**
	 * Private constructor to prevent direct instantiation.
	 *
	 * @param Dispatcher|null $dispatcher Optional dispatcher instance.
	 */
	private function __construct( ?Dispatcher $dispatcher = null ) {
		$this->dispatcher = $dispatcher ?: new Dispatcher();
	}

	/**
	 * Get singleton instance.
	 *
	 * @param Dispatcher|null $dispatcher Optional dispatcher instance.
	 * @return Webhook_Registry
	 */
	public static function instance( ?Dispatcher $dispatcher = null ): Webhook_Registry {
		if ( null === self::$instance ) {
			self::$instance = new self( $dispatcher );
		}
		return self::$instance;
	}

	/**
	 * Register a webhook instance.
	 *
	 * @param Webhook $webhook The webhook instance to register.
	 * @return static
	 * @phpstan-return static
	 *
	 * @throws \InvalidArgumentException If webhook name is already registered.
	 */
	public function register( Webhook $webhook ): static {
		$name = $webhook->get_name();

		if ( isset( $this->webhooks[ $name ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Webhook with name "%s" is already registered.', esc_html( $name ) )
			);
		}

		$this->webhooks[ $name ] = $webhook;

		// Register WordPress hooks and initialize notifications
		$webhook->init();

		$notifications = $webhook->get_enabled_notifications();
		if ( ! empty( $notifications ) ) {
			$notification_registry = Service_Provider::get_notification_registry();
			$notification_registry->init_selected( $notifications );
		}

		return $this;
	}

	/**
	 * Get a registered webhook by name.
	 *
	 * @param string $name The webhook name.
	 * @return Webhook|null
	 * @phpstan-param non-empty-string $name
	 */
	public function get( string $name ): ?Webhook {
		return $this->webhooks[ $name ] ?? null;
	}

	/**
	 * Get all registered webhooks.
	 *
	 * @return array<string,Webhook>
	 * @phpstan-return array<non-empty-string,Webhook>
	 */
	public function get_all(): array {
		return $this->webhooks;
	}

	/**
	 * Check if a webhook is registered.
	 *
	 * @param string $name The webhook name.
	 * @return bool
	 * @phpstan-param non-empty-string $name
	 */
	public function has( string $name ): bool {
		return isset( $this->webhooks[ $name ] );
	}

	/**
	 * Unregister a webhook.
	 *
	 * @param string $name The webhook name.
	 * @return bool True if webhook was found and removed, false otherwise.
	 * @phpstan-param non-empty-string $name
	 */
	public function unregister( string $name ): bool {
		if ( isset( $this->webhooks[ $name ] ) ) {
			unset( $this->webhooks[ $name ] );
			return true;
		}
		return false;
	}

	/**
	 * Get the dispatcher instance.
	 *
	 * @return Dispatcher
	 */
	public function get_dispatcher(): Dispatcher {
		return $this->dispatcher;
	}

	/**
	 * Apply webhook-specific configuration to dispatcher arguments.
	 *
	 * This method allows webhooks to customize the HTTP request arguments
	 * based on their configuration (timeout, headers, etc.).
	 *
	 * @param array<string,mixed> $args    Original HTTP request arguments.
	 * @param string              $webhook_name The webhook name.
	 * @return array<string,mixed> Modified arguments.
	 * @phpstan-param array<string,mixed> $args
	 * @phpstan-param non-empty-string $webhook_name
	 * @phpstan-return array<string,mixed>
	 */
	public function apply_webhook_config( array $args, string $webhook_name ): array {
		$webhook = $this->get( $webhook_name );
		if ( ! $webhook ) {
			return $args;
		}

		// Apply timeout
		$args['timeout'] = $webhook->get_timeout();

		// Apply custom headers
		$webhook_headers = $webhook->get_headers();
		if ( ! empty( $webhook_headers ) ) {
			$args['headers'] = array_merge( $args['headers'] ?? array(), $webhook_headers );
		}

		return $args;
	}
}
