<?php
/**
 * Abstract base class for entity handlers.
 *
 * @package juvo\WP_Webhook_Framework\Entities
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework\Entities;

/**
 * Abstract base class for entity handlers.
 *
 * Provides reusable WordPress hook callbacks and data transformation logic
 * for entity-specific webhook events. Does not emit webhooks directly.
 */
abstract class Entity_Handler {

	/**
	 * Enrich payload data at delivery time.
	 *
	 * @param int                 $entity_id The entity ID.
	 * @param array<string,mixed> $payload   The scheduled payload data.
	 * @return array<string,mixed> The updated payload data.
	 */
	public function prepare_delivery_payload( int $entity_id, array $payload ): array {
		return $payload;
	}
}
