<?php
/**
 * Service_Provider class for the WP Webhook Framework.
 *
 * @package juvo\WP_Webhook_Framework
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework;

use juvo\WP_Webhook_Framework\Notifications\Blocked;
use juvo\WP_Webhook_Framework\Notifications\Notification_Registry;

/**
 * Bootstraps the framework by wiring up the Dispatcher, Registry, and
 * Notification system. Does not register any webhooks -- consumers must
 * register their own via the `wpwf_register_webhooks` action.
 */
class Service_Provider {

	/**
	 * Minimum WordPress version supported by the framework.
	 *
	 * The dispatcher signals every failure by throwing `WP_Exception`, which was
	 * added to WordPress core in 6.7.0. On older releases those throw sites raise
	 * an uncatchable "class not found" fatal, so the framework refuses to boot.
	 */
	public const MIN_WP_VERSION = '6.7';

	/**
	 * Singleton instance.
	 *
	 * @var Service_Provider|null
	 */
	private static ?Service_Provider $instance = null;

	/**
	 * Registration state flag.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * The webhook dispatcher instance.
	 *
	 * @var Dispatcher
	 */
	private Dispatcher $dispatcher;

	/**
	 * The webhook registry instance.
	 *
	 * @var Webhook_Registry
	 */
	private Webhook_Registry $registry;

	/**
	 * The notification registry instance.
	 *
	 * @var Notification_Registry
	 */
	private Notification_Registry $notification_registry;

	/**
	 * Private constructor to prevent direct instantiation.
	 *
	 * @param Dispatcher|null $dispatcher Optional dispatcher instance.
	 */
	private function __construct( ?Dispatcher $dispatcher = null ) {
		$this->dispatcher            = $dispatcher ?: new Dispatcher();
		$this->registry              = Webhook_Registry::instance( $this->dispatcher );
		$this->notification_registry = Notification_Registry::instance();
	}

	/**
	 * Get singleton instance.
	 *
	 * @param Dispatcher|null $dispatcher Optional dispatcher instance.
	 * @return Service_Provider
	 */
	private static function get_instance( ?Dispatcher $dispatcher = null ): Service_Provider {
		if ( null === self::$instance ) {
			self::$instance = new self( $dispatcher );
		}
		return self::$instance;
	}

	/**
	 * Check whether the current WordPress version supports the framework.
	 *
	 * @return bool True when WordPress is new enough to boot the framework.
	 */
	public static function is_supported_wp_version(): bool {
		return version_compare( (string) get_bloginfo( 'version' ), self::MIN_WP_VERSION, '>=' );
	}

	/**
	 * Print an admin notice explaining why the framework did not boot.
	 */
	public static function render_unsupported_wp_version_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: required WordPress version, 2: current WordPress version */
					__( 'WP Webhook Framework requires WordPress %1$s or newer and has been disabled. This site runs WordPress %2$s.', 'wp-webhook-framework' ),
					self::MIN_WP_VERSION,
					(string) get_bloginfo( 'version' )
				)
			)
		);
	}

	/**
	 * Registers all actions/filters. Safe to call multiple times.
	 *
	 * Bails out without registering anything when the WordPress version is older
	 * than {@see self::MIN_WP_VERSION}, surfacing an admin notice instead of
	 * letting the dispatcher fatal on a missing `WP_Exception` class.
	 */
	public static function register(): void {

		// Guard against duplicate registration
		if ( self::$registered ) {
			return;
		}

		// Bail before loading Action Scheduler; an unsupported site boots nothing.
		if ( ! self::is_supported_wp_version() ) {
			add_action( 'admin_notices', array( self::class, 'render_unsupported_wp_version_notice' ) );
			self::$registered = true;
			return;
		}

		self::bootstrap_action_scheduler();

		$instance = self::get_instance();

		add_action(
			'wpwf_send_webhook',
			array( $instance->dispatcher, 'process_scheduled_webhook' ),
			10,
			6
		);

		// Defer webhook and notification registration until 'init' to avoid race conditions.
		// This allows other plugins to hook into 'wpwf_register_webhooks' and
		// 'wpwf_register_notifications' actions before they are fired.
		add_action( 'init', array( $instance, 'on_init' ) );

		self::$registered = true;
	}

	/**
	 * Fire the registration action so consumers can register webhooks.
	 *
	 * No webhooks are registered by default. Consumers choose which
	 * webhooks to register via the `wpwf_register_webhooks` action.
	 * Each call to `Webhook_Registry::register()` calls `init()` on the
	 * webhook, activating its hooks during the WordPress 'init' action.
	 */
	private function register_webhooks(): void {
		/**
		 * Register webhooks with the framework.
		 *
		 * @param Webhook_Registry $registry The webhook registry instance.
		 */
		do_action( 'wpwf_register_webhooks', $this->registry );
	}

	/**
	 * Handle the WordPress 'init' action.
	 *
	 * Registers webhooks and notification handlers during WordPress initialization.
	 */
	public function on_init(): void {
		$this->register_available_notifications();
		$this->register_webhooks();
	}

	/**
	 * Register notification handlers.
	 *
	 * Registers available notification handlers to the registry.
	 * Notifications must be explicitly enabled per-webhook to be initialized.
	 */
	private function register_available_notifications(): void {
		// Register built-in notification handlers
		$this->notification_registry->register( new Blocked() );

		/**
		 * Allow third parties to register custom notification handlers.
		 *
		 * @param Notification_Registry $notification_registry The notification registry instance.
		 */
		do_action( 'wpwf_register_notifications', $this->notification_registry );
	}

	/**
	 * Get the webhook registry instance.
	 *
	 * @return Webhook_Registry
	 */
	public static function get_registry(): Webhook_Registry {
		$instance = self::get_instance();
		return $instance->registry;
	}

	/**
	 * Get the notification registry instance.
	 *
	 * @return Notification_Registry
	 */
	public static function get_notification_registry(): Notification_Registry {
		$instance = self::get_instance();
		return $instance->notification_registry;
	}

	/**
	 * Get the dispatcher instance.
	 *
	 * @return Dispatcher
	 */
	public static function get_dispatcher(): Dispatcher {
		$instance = self::get_instance();
		return $instance->dispatcher;
	}

	/**
	 * Require the bundled Action Scheduler bootstrap.
	 *
	 * Action Scheduler ships as a `type:wordpress-plugin` package, so Composer
	 * never requires it for us.
	 *
	 * There is deliberately no "is Action Scheduler already loaded" check. Every
	 * bundled copy must register itself so the version manager can initialise the
	 * newest one; bailing out because another plugin loaded an older copy first
	 * would pin the site to that older version. Action Scheduler exposes no
	 * constant or global to test anyway -- it guards itself with the
	 * version-suffixed `action_scheduler_register_*()` function in
	 * action-scheduler.php, which together with `require_once` already makes a
	 * repeat load a no-op.
	 *
	 * Call `register()` while your plugin file loads. Action Scheduler registers
	 * on `plugins_loaded` at priority 0 and initialises at priority 1, so a
	 * `register()` call made from inside `plugins_loaded` loads it too late for
	 * those hooks and leaves the `as_*` functions undefined.
	 *
	 * @return void
	 */
	private static function bootstrap_action_scheduler(): void {
		// Two layouts occur and neither path covers the other. The first is the
		// production one and is checked first, so a real install never evaluates
		// the second; the second only matters when this repository is checked out
		// on its own, which is how the wp-env test suite runs it.
		$paths = array(
			// vendor/<vendor>/wp-webhook-framework/src -> vendor/woocommerce/...
			__DIR__ . '/../../../woocommerce/action-scheduler/action-scheduler.php',
			// <repo>/src -> <repo>/vendor/woocommerce/...
			__DIR__ . '/../vendor/woocommerce/action-scheduler/action-scheduler.php',
		);

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}

		// Without this the failure only surfaces as an undefined `as_*` function
		// once a webhook is emitted, far from the actual cause.
		wp_trigger_error(
			__METHOD__,
			'Action Scheduler was not found. Webhooks cannot be dispatched. This usually '
				. 'means "composer install" has not run, or the consuming project relocates '
				. 'type:wordpress-plugin packages via extra.installer-paths.'
		);
	}
}
