<?php
/**
 * Meta emission mode enum for Meta_Webhook.
 *
 * Native enum controlling which webhooks are dispatched on meta changes.
 *
 * @package juvo\WP_Webhook_Framework\Webhooks
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework\Webhooks;

/**
 * Emission mode controlling which webhooks are dispatched on meta changes.
 *
 * - META:   Only emit the meta-entity webhook (entity = "meta").
 * - BOTH:   Emit the meta-entity webhook AND trigger the parent entity update.
 * - ENTITY: Only trigger the parent entity update webhook.
 */
enum Meta_Emission_Mode: string {

	/**
	 * Only emit the meta-entity webhook.
	 */
	case META = 'meta';

	/**
	 * Emit both meta-entity and parent entity webhooks.
	 */
	case BOTH = 'both';

	/**
	 * Only trigger the parent entity update webhook.
	 */
	case ENTITY = 'entity';

	/**
	 * Check if this mode includes meta emission.
	 *
	 * @return bool
	 */
	public function includes_meta(): bool {
		return self::META === $this || self::BOTH === $this;
	}

	/**
	 * Check if this mode includes entity emission.
	 *
	 * @return bool
	 */
	public function includes_entity(): bool {
		return self::ENTITY === $this || self::BOTH === $this;
	}
}
