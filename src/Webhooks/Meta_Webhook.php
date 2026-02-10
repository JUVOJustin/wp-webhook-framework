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
 * Meta webhook implementation with configuration capabilities.
 *
 * Handles meta-related webhook events with configurable retry policies,
 * timeouts, and other webhook-specific settings.
 */
class Meta_Webhook extends Webhook {

	/**
	 * The meta handler instance.
	 *
	 * @var Meta
	 */
	private Meta $meta_handler;

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
	 * Initialize the webhook by registering WordPress hooks.
	 */
	public function init(): void {
		add_action( 'deleted_post_meta', array( $this, 'on_deleted_post_meta' ), 10, 4 );
		add_action( 'deleted_term_meta', array( $this, 'on_deleted_term_meta' ), 10, 4 );
		add_action( 'deleted_user_meta', array( $this, 'on_deleted_user_meta' ), 10, 4 );

		add_filter(
			'acf/update_value',
			function ( $value, $object_id, $field, $original ): mixed {
				[$entity, $id] = AcfUtil::parse_object_id( $object_id );

				if ( null === $entity || null === $id ) {
					return $value;
				}

				$this->on_acf_update( $entity, (int) $id, is_array( $field ) ? $field : array(), $value, $original );
				return $value;
			},
			10,
			4
		);

		// Add filters for all meta types with high priority to run late
		add_filter( 'update_post_metadata', array( $this, 'on_updated_post_meta' ), 999, 5 );
		add_filter( 'update_term_metadata', array( $this, 'on_updated_term_meta' ), 999, 5 );
		add_filter( 'update_user_metadata', array( $this, 'on_updated_user_meta' ), 999, 5 );
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
	 * @param string              $entity   The entity type.
	 * @param int                 $id       The entity ID.
	 * @param array<string,mixed> $field    The field data.
	 * @param mixed               $value    The new value.
	 * @param mixed               $original The original value.
	 */
	private function on_acf_update( string $entity, int $id, array $field, $value = null, $original = null ): void {
		$meta_key = $field['name'] ?? '';
		if ( empty( $meta_key ) ) {
			return;
		}

		$this->on_meta_update( $entity, $id, $meta_key, $value, $original );
	}

	/**
	 * Central handler for all metadata updates with automatic change and deletion detection.
	 *
	 * Emits both meta-level webhooks and triggers parent entity update webhooks.
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
		$action      = $is_deletion ? 'delete' : 'update';

		// Emit meta-level webhook with meta_key in payload
		$payload = $this->meta_handler->prepare_payload( $meta_type, $object_id, $meta_key );
		$this->emit( $action, 'meta', $object_id, $payload );

		// Trigger upstream entity-level update webhook
		$this->trigger_entity_update( $meta_type, $object_id );
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
