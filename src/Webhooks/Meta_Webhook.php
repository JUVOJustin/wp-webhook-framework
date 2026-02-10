<?php
/**
 * Meta webhook implementation.
 *
 * @package Citation\WP_Webhook_Framework\Webhooks
 */

declare(strict_types=1);

namespace Citation\WP_Webhook_Framework\Webhooks;

use Citation\WP_Webhook_Framework\Webhook;
use Citation\WP_Webhook_Framework\Entities\Meta;
use Citation\WP_Webhook_Framework\Entities\Post;
use Citation\WP_Webhook_Framework\Entities\Term;
use Citation\WP_Webhook_Framework\Entities\User;
use Citation\WP_Webhook_Framework\Webhook_Registry;
use Citation\WP_Webhook_Framework\Support\AcfUtil;

/**
 * Meta webhook implementation with configurable emission modes.
 *
 * Supports three emission modes that control which webhooks are dispatched
 * when a meta value changes:
 *
 * - `EMIT_META`:   Only emit the meta-entity webhook (entity = "meta").
 * - `EMIT_BOTH`:   Emit the meta-entity webhook AND trigger the parent
 *                   entity update webhook. This is the default.
 * - `EMIT_ENTITY`: Only trigger the parent entity update webhook
 *                   (e.g. entity = "post"). The Dispatcher deduplicates
 *                   on (url, action, entity, id), so rapid meta changes
 *                   on the same object collapse into a single delivery.
 */
class Meta_Webhook extends Webhook {

	/**
	 * Emit only the meta-entity webhook.
	 *
	 * @var string
	 */
	public const EMIT_META = 'meta';

	/**
	 * Emit both the meta-entity webhook and the parent entity update.
	 *
	 * @var string
	 */
	public const EMIT_BOTH = 'both';

	/**
	 * Emit only the parent entity update webhook.
	 *
	 * Deduplication in the Dispatcher ensures rapid meta changes on the
	 * same object result in a single scheduled delivery.
	 *
	 * @var string
	 */
	public const EMIT_ENTITY = 'entity';

	/**
	 * Valid emission mode values.
	 *
	 * @var string[]
	 */
	private const VALID_MODES = array(
		self::EMIT_META,
		self::EMIT_BOTH,
		self::EMIT_ENTITY,
	);

	/**
	 * The meta handler instance.
	 *
	 * @var Meta
	 */
	private Meta $meta_handler;

	/**
	 * Controls which webhooks are dispatched on meta changes.
	 *
	 * @var string
	 * @phpstan-var self::EMIT_META|self::EMIT_BOTH|self::EMIT_ENTITY
	 */
	private string $emission_mode = self::EMIT_BOTH;

	/**
	 * Tracks meta updates already processed during this request.
	 *
	 * Plugins like ACF or Meta Box fire their own hook (e.g. acf/update_value)
	 * before the underlying update_post_metadata filter runs. Both paths lead
	 * to on_meta_update(). The first call records the key here; the second
	 * call sees it and bails, preventing duplicate webhooks.
	 *
	 * Keyed by "entity:object_id:meta_key". Cleaned up on shutdown.
	 *
	 * @var array<string, true>
	 */
	private static array $processed_meta_updates = array();

	/**
	 * Constructor.
	 *
	 * @param string $name The webhook name.
	 * @phpstan-param non-empty-string $name
	 */
	public function __construct( string $name = 'meta' ) {
		parent::__construct( $name );
		$this->meta_handler = new Meta();
	}

	/**
	 * Set the emission mode for meta changes.
	 *
	 * Use one of the class constants: EMIT_META, EMIT_BOTH, or EMIT_ENTITY.
	 *
	 * @param string $mode The emission mode.
	 * @phpstan-param self::EMIT_META|self::EMIT_BOTH|self::EMIT_ENTITY $mode
	 * @return static
	 *
	 * @throws \InvalidArgumentException If the mode is not a valid constant.
	 */
	public function emission_mode( string $mode ): static {
		if ( ! in_array( $mode, self::VALID_MODES, true ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Invalid emission mode "%s". Use one of: %s',
					esc_html( $mode ),
					esc_html( implode( ', ', self::VALID_MODES ) )
				)
			);
		}

		$this->emission_mode = $mode;
		return $this;
	}

	/**
	 * Get the current emission mode.
	 *
	 * @return string
	 * @phpstan-return self::EMIT_META|self::EMIT_BOTH|self::EMIT_ENTITY
	 */
	public function get_emission_mode(): string {
		return $this->emission_mode;
	}

	/**
	 * Initialize the webhook by registering WordPress hooks.
	 */
	public function init(): void {
		add_action( 'deleted_post_meta', array( $this, 'on_deleted_post_meta' ), 10, 4 );
		add_action( 'deleted_term_meta', array( $this, 'on_deleted_term_meta' ), 10, 4 );
		add_action( 'deleted_user_meta', array( $this, 'on_deleted_user_meta' ), 10, 4 );

		add_filter(
			'acf/update_value',
			function ( $value, $object_id, $field ): mixed {
				[$entity, $id] = AcfUtil::parse_object_id( $object_id );

				if ( null === $entity || null === $id ) {
					return $value;
				}

				$meta_key = is_array( $field ) ? ( $field['name'] ?? '' ) : '';
				if ( '' === $meta_key ) {
					return $value;
				}

				// Fetch the current persisted value for accurate change detection.
				// $object_id is already in ACF format (123, "term_123", "user_123").
				$old_value = get_field( $meta_key, $object_id, false );

				$this->on_acf_update( $entity, (int) $id, is_array( $field ) ? $field : array(), $value, $old_value );

				return $value;
			},
			10,
			3
		);

		// Add filters for all meta types with high priority to run late
		add_filter( 'update_post_metadata', array( $this, 'on_updated_post_meta' ), 999, 5 );
		add_filter( 'update_term_metadata', array( $this, 'on_updated_term_meta' ), 999, 5 );
		add_filter( 'update_user_metadata', array( $this, 'on_updated_user_meta' ), 999, 5 );

		// Clean up processed tracking on shutdown to prevent stale entries across long-running processes
		add_action(
			'shutdown',
			static function (): void {
				self::$processed_meta_updates = array();
			}
		);
	}

	/**
	 * Check whether this exact meta update has already been processed.
	 *
	 * @param string $entity    The entity type (post, term, user).
	 * @param int    $object_id The object ID.
	 * @param string $meta_key  The meta key.
	 * @return bool True if already processed during this request.
	 */
	private static function is_already_processed( string $entity, int $object_id, string $meta_key ): bool {
		return isset( self::$processed_meta_updates[ $entity . ':' . $object_id . ':' . $meta_key ] );
	}

	/**
	 * Record a meta update as processed for the current request.
	 *
	 * @param string $entity    The entity type (post, term, user).
	 * @param int    $object_id The object ID.
	 * @param string $meta_key  The meta key.
	 */
	private static function mark_as_processed( string $entity, int $object_id, string $meta_key ): void {
		self::$processed_meta_updates[ $entity . ':' . $object_id . ':' . $meta_key ] = true;
	}

	/**
	 * Remove a meta update from the processed set.
	 *
	 * Used by deletion handlers so that a deletion always emits a webhook,
	 * even if an update for the same key was already processed.
	 *
	 * @param string $entity    The entity type (post, term, user).
	 * @param int    $object_id The object ID.
	 * @param string $meta_key  The meta key.
	 */
	private static function unmark_as_processed( string $entity, int $object_id, string $meta_key ): void {
		unset( self::$processed_meta_updates[ $entity . ':' . $object_id . ':' . $meta_key ] );
	}

	/**
	 * Handle post meta update event.
	 *
	 * @param bool|null $check      Whether to allow updating metadata for the given type.
	 * @param int       $object_id  The object ID.
	 * @param string    $meta_key   The meta key.
	 * @param mixed     $meta_value The meta value.
	 * @param mixed     $prev_value The previous value. Most likely not filled. Value has to be passed to update_metadata().
	 * @return bool|null
	 */
	public function on_updated_post_meta( ?bool $check, int $object_id, string $meta_key, mixed $meta_value, mixed $prev_value ): ?bool {
		if ( wp_is_post_revision( $object_id ) || wp_is_post_autosave( $object_id ) ) {
			return $check;
		}

		if ( empty( $prev_value ) ) {
			$prev_value = get_post_meta( $object_id, $meta_key, true );
		}

		$this->on_meta_update( 'post', $object_id, $meta_key, $meta_value, $prev_value );

		return $check;
	}

	/**
	 * Handle post meta deletion event.
	 *
	 * @param mixed  $meta_ids   The meta IDs.
	 * @param int    $object_id  The object ID.
	 * @param string $meta_key   The meta key.
	 * @param mixed  $meta_value The meta value.
	 */
	public function on_deleted_post_meta( $meta_ids, int $object_id, string $meta_key, $meta_value ): void {
		if ( wp_is_post_revision( $object_id ) || wp_is_post_autosave( $object_id ) ) {
			return;
		}

		$this->on_meta_update( 'post', $object_id, $meta_key, $meta_value );
	}

	/**
	 * Handle term meta update event.
	 *
	 * @param bool|null $check      Whether to allow updating metadata for the given type.
	 * @param int       $object_id  The object ID.
	 * @param string    $meta_key   The meta key.
	 * @param mixed     $meta_value The meta value.
	 * @param mixed     $prev_value The previous value. Most likely not filled. Value has to be passed to update_metadata().
	 * @return bool|null
	 */
	public function on_updated_term_meta( ?bool $check, int $object_id, string $meta_key, mixed $meta_value, mixed $prev_value ): ?bool {
		if ( empty( $prev_value ) ) {
			$prev_value = get_term_meta( $object_id, $meta_key, true );
		}

		$this->on_meta_update( 'term', $object_id, $meta_key, $meta_value, $prev_value );

		return $check;
	}

	/**
	 * Handle term meta deletion event.
	 *
	 * @param mixed  $meta_ids   The meta IDs.
	 * @param int    $object_id  The object ID.
	 * @param string $meta_key   The meta key.
	 * @param mixed  $meta_value The meta value.
	 */
	public function on_deleted_term_meta( $meta_ids, int $object_id, string $meta_key, $meta_value ): void {
		$this->on_meta_update( 'term', $object_id, $meta_key, $meta_value );
	}

	/**
	 * Handle user meta update event.
	 *
	 * @param bool|null $check      Whether to allow updating metadata for the given type.
	 * @param int       $object_id  The object ID.
	 * @param string    $meta_key   The meta key.
	 * @param mixed     $meta_value The meta value.
	 * @param mixed     $prev_value The previous value. Most likely not filled. Value has to be passed to update_metadata().
	 * @return bool|null
	 */
	public function on_updated_user_meta( ?bool $check, int $object_id, string $meta_key, mixed $meta_value, mixed $prev_value ): ?bool {
		if ( empty( $prev_value ) ) {
			$prev_value = get_user_meta( $object_id, $meta_key, true );
		}

		$this->on_meta_update( 'user', $object_id, $meta_key, $meta_value, $prev_value );

		return $check;
	}

	/**
	 * Handle user meta deletion event.
	 *
	 * @param mixed  $meta_ids   The meta IDs.
	 * @param int    $object_id  The object ID.
	 * @param string $meta_key   The meta key.
	 * @param mixed  $meta_value The meta value.
	 */
	public function on_deleted_user_meta( $meta_ids, int $object_id, string $meta_key, $meta_value ): void {
		$this->on_meta_update( 'user', $object_id, $meta_key, $meta_value );
	}

	/**
	 * Handle ACF update events.
	 *
	 * Routes ACF field updates to the central meta update handler.
	 *
	 * @param string              $entity    The entity type.
	 * @param int                 $id        The entity ID.
	 * @param array<string,mixed> $field     The field data.
	 * @param mixed               $value     The new value.
	 * @param mixed               $old_value The current persisted value fetched via get_field().
	 */
	private function on_acf_update( string $entity, int $id, array $field, $value = null, $old_value = null ): void {
		$meta_key = $field['name'] ?? '';
		if ( empty( $meta_key ) ) {
			return;
		}

		$this->on_meta_update( $entity, $id, $meta_key, $value, $old_value );
	}

	/**
	 * Central handler for all metadata updates with automatic change and deletion detection.
	 *
	 * Dispatches webhooks according to the configured emission mode.
	 *
	 * @param string $meta_type  The meta type (post, term, user).
	 * @param int    $object_id  The object ID.
	 * @param string $meta_key   The meta key.
	 * @param mixed  $new_value  The new value.
	 * @param mixed  $old_value  The old value.
	 */
	private function on_meta_update( string $meta_type, int $object_id, string $meta_key, mixed $new_value, mixed $old_value = null ): void {
		// No change, do nothing (ALWAYS use loose equality for value comparison)
		// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison,Universal.Operators.StrictComparisons.LooseEqual
		if ( $new_value == $old_value ) {
			return;
		}

		// Check if this meta key should be excluded from webhook emission
		if ( $this->meta_handler->is_meta_key_excluded( $meta_key, $meta_type, $object_id ) ) {
			return;
		}

		// Automatically detect if this is effectively a deletion
		$is_deletion = $this->meta_handler->is_deletion( $new_value, $old_value );

		// Deletions clear prior tracking so they always re-emit
		if ( $is_deletion ) {
			self::unmark_as_processed( $meta_type, $object_id, $meta_key );
		}

		// Deduplicate: skip if this exact key was already processed during this request.
		// Plugins like ACF fire their own hook AND the underlying WP meta filter for the
		// same change. The first path through here marks the key; the second bails.
		if ( self::is_already_processed( $meta_type, $object_id, $meta_key ) ) {
			return;
		}

		self::mark_as_processed( $meta_type, $object_id, $meta_key );

		$action = $is_deletion ? 'delete' : 'update';

		// Emit meta-level webhook when mode includes meta emission
		if ( self::EMIT_META === $this->emission_mode || self::EMIT_BOTH === $this->emission_mode ) {
			$payload = $this->meta_handler->prepare_payload( $meta_type, $object_id, $meta_key );
			$this->emit( $action, 'meta', $object_id, $payload );
		}

		// Trigger parent entity update when mode includes entity emission.
		// The Dispatcher deduplicates on (url, action, entity, id), so
		// rapid meta changes on the same object collapse into one delivery.
		if ( self::EMIT_ENTITY === $this->emission_mode || self::EMIT_BOTH === $this->emission_mode ) {
			$this->trigger_entity_update( $meta_type, $object_id );
		}
	}

	/**
	 * Trigger the appropriate entity-level update webhook.
	 *
	 * When meta changes, the parent entity (post/term/user) is also considered updated.
	 * Uses the parent entity's webhook instance for configuration.
	 *
	 * @param string $meta_type The meta type.
	 * @param int    $object_id The object ID.
	 */
	private function trigger_entity_update( string $meta_type, int $object_id ): void {
		$registry = Webhook_Registry::instance();

		// Get the parent entity webhook instance
		$parent_webhook = $registry->get( $meta_type );
		if ( ! $parent_webhook ) {
			return;
		}

		// Get entity payload without meta_key and pass directly to emit
		$payload = $this->meta_handler->get_entity_payload( $meta_type, $object_id );
		$parent_webhook->emit( 'update', $meta_type, $object_id, $payload );
	}

	/**
	 * Get the meta handler instance.
	 *
	 * @return Meta
	 */
	public function get_handler(): Meta {
		return $this->meta_handler;
	}
}
