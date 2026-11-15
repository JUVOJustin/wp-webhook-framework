<?php
/**
 * Webhook dispatcher class.
 *
 * @package juvo\WP_Webhook_Framework
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework;

use juvo\WP_Webhook_Framework\Entities\Entity_Handler;
use juvo\WP_Webhook_Framework\Entities\Meta;
use juvo\WP_Webhook_Framework\Entities\Post;
use juvo\WP_Webhook_Framework\Entities\Term;
use juvo\WP_Webhook_Framework\Entities\User;
use WP_Exception;

/**
 * Queues and sends webhooks with scheduled and immediate modes.
 *
 * Scheduled mode preserves the first pending payload per destination, action and entity.
 */
class Dispatcher {

	/**
	 * Schedule only when the same destination, action and entity has no pending webhook.
	 *
	 * @param string              $action        The action type.
	 * @param string              $entity        The entity type.
	 * @param int|string          $id            The entity ID.
	 * @param string              $url           The webhook URL.
	 * @param array<string,mixed> $payload       The request payload data.
	 * @param array<string,mixed> $headers       The request headers.
	 *
	 * @throws WP_Exception If Action Scheduler is not active or URL/payload issues.
	 */
	public function schedule( string $action, string $entity, int|string $id, string $url = '', array $payload = array(), array $headers = array() ): void {
		$this->ensure_action_scheduler_available();

		$dispatch_data = $this->resolve_dispatch_data( $entity, $id, $url, $payload );
		if ( null === $dispatch_data ) {
			return;
		}

		$url     = $dispatch_data['url'];
		$payload = $dispatch_data['payload'];

		$webhook = Webhook_Registry::instance()->get( (string) ( $headers['wpwf-webhook-name'] ?? '' ) );
		if ( 'update' === $action && null !== $webhook && $webhook->get_stack_updates() && Stack_Store::is_supported() ) {
			$body = array_merge(
				$this->prepare_delivery_payload( $entity, $id, $payload ),
				array(
					'action' => $action,
					'entity' => $entity,
					'id'     => $id,
				)
			);
			$this->get_update_stack()->schedule( $url, $entity, $id, $body, $headers );
			return;
		}

		$group = sanitize_title( 'wpwf-' . $entity );
		if ( $this->has_pending_webhook( $url, $action, $entity, $id, $group ) ) {
			return;
		}

		$action_id = as_schedule_single_action(
			time() + 5,
			'wpwf_send_webhook',
			array(
				'url'     => $url,
				'action'  => $action,
				'entity'  => $entity,
				'id'      => $id,
				'payload' => $payload,
				'headers' => $headers,
			),
			$group
		);

		if ( 0 === $action_id ) {
			throw new WP_Exception( 'webhook_schedule_failed' );
		}
	}

	/**
	 * Read complete arguments through the store: partial JSON queries miss extended_args.
	 *
	 * Preserve the first pending payload and the existing destination/action/entity identity.
	 * This check and enqueue are not atomic across concurrent producers.
	 *
	 * @param string     $url Destination URL.
	 * @param string     $action Event action.
	 * @param string     $entity Entity type.
	 * @param int|string $id Entity ID.
	 * @param string     $group Scheduler group.
	 * @return bool
	 */
	private function has_pending_webhook( string $url, string $action, string $entity, int|string $id, string $group ): bool {
		// Canonical integer IDs cover WordPress entities. Other IDs retain the full scan
		// so JSON escaping or custom serialization cannot hide an existing action.
		$searches = array( '' );
		if ( (string) (int) $id === (string) $id ) {
			$searches = array( '"id":' . $id . ',', '"id":"' . $id . '"' );
		}
		foreach ( $searches as $search ) {
			$offset = 0;
			do {
				$pending = as_get_scheduled_actions(
					array(
						'hook'     => 'wpwf_send_webhook',
						'group'    => $group,
						'search'   => $search,
						'status'   => \ActionScheduler_Store::STATUS_PENDING,
						'per_page' => 100,
						'offset'   => $offset,
						'orderby'  => 'action_id',
						'order'    => 'ASC',
					)
				);
				foreach ( $pending as $scheduled ) {
					$args = $scheduled->get_args();
					if ( ( $args['url'] ?? null ) === $url && ( $args['action'] ?? null ) === $action
						&& ( $args['entity'] ?? null ) === $entity && isset( $args['id'] ) && (string) $args['id'] === (string) $id ) {
						return true;
					}
				}
				$offset += 100;
				$count   = count( $pending );
			} while ( 100 === $count );
		}
		return false;
	}

	/**
	 * Deliver a webhook immediately in the current request.
	 *
	 * Uses the same URL and payload filters as scheduled dispatch.
	 * Failures can still schedule asynchronous retries.
	 *
	 * @param string              $action        The action type.
	 * @param string              $entity        The entity type.
	 * @param int|string          $id            The entity ID.
	 * @param string              $url           The webhook URL.
	 * @param array<string,mixed> $payload       The request payload data.
	 * @param array<string,mixed> $headers       The request headers.
	 *
	 * @throws WP_Exception If URL/payload/webhook configuration is invalid.
	 */
	public function dispatch_immediately( string $action, string $entity, int|string $id, string $url = '', array $payload = array(), array $headers = array() ): void {
		$dispatch_data = $this->resolve_dispatch_data( $entity, $id, $url, $payload );
		if ( null === $dispatch_data ) {
			return;
		}

		$webhook = $this->get_webhook_from_headers( $headers );

		$this->send_webhook_request(
			$dispatch_data['url'],
			$action,
			$entity,
			$id,
			$dispatch_data['payload'],
			$headers,
			$webhook
		);
	}

	/**
	 * Ensure Action Scheduler APIs required for queued actions are available.
	 *
	 * @throws WP_Exception If Action Scheduler is missing or its store is not ready.
	 */
	private function ensure_action_scheduler_available(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_get_scheduled_actions' ) || ! \ActionScheduler::is_initialized() ) {
			throw new WP_Exception( 'action_scheduler_not_active' );
		}
	}

	/**
	 * Resolve URL and payload through shared dispatch validation.
	 *
	 * @param string              $entity  The entity type.
	 * @param int|string          $id      The entity ID.
	 * @param string              $url     The webhook URL.
	 * @param array<string,mixed> $payload The payload data.
	 * @return array{url: string, payload: array<string,mixed>}|null
	 *
	 * @throws WP_Exception If URL or payload validation fails.
	 */
	private function resolve_dispatch_data( string $entity, int|string $id, string $url, array $payload ): ?array {
		$url = apply_filters( 'wpwf_url', $url, $entity, $id );

		if ( empty( $url ) ) {
			throw new WP_Exception( 'webhook_url_not_set' );
		}

		if ( $this->is_url_blocked( $url ) ) {
			throw new WP_Exception( 'webhook_url_blocked' );
		}

		$original_payload = $payload;
		$payload          = apply_filters( 'wpwf_payload', $payload, $entity, $id );
		if ( false === $payload || null === $payload ) {
			return null;
		}

		if ( ! is_array( $payload ) ) {
			throw new WP_Exception( 'webhook_payload_invalid' );
		}

		if ( empty( $payload ) && ! empty( $original_payload ) ) {
			throw new WP_Exception( 'webhook_payload_empty' );
		}

		return array(
			'url'     => $url,
			'payload' => $payload,
		);
	}

	/**
	 * Action Scheduler callback for queued webhook deliveries.
	 *
	 * Reconstructs the webhook instance from the registry using the persisted webhook name.
	 *
	 * @param string              $url          The webhook URL.
	 * @param string              $action       The action type.
	 * @param string              $entity       The entity type.
	 * @param int|string          $id           The entity ID.
	 * @param array<string,mixed> $payload      The payload data.
	 * @param array<string,mixed> $headers      The HTTP headers.
	 *
	 * @throws WP_Exception If URL is blocked or webhook is not found.
	 */
	public function process_scheduled_webhook( string $url, string $action, string $entity, int|string $id, array $payload, array $headers ): void {
		$webhook = $this->get_webhook_from_headers( $headers );

		$this->send_webhook_request( $url, $action, $entity, $id, $payload, $headers, $webhook );
	}

	/**
	 * Resolve the webhook instance from scheduled headers.
	 *
	 * @param array<string,mixed> $headers The webhook headers.
	 * @return Webhook
	 *
	 * @throws WP_Exception If the webhook cannot be resolved from the registry.
	 */
	private function get_webhook_from_headers( array $headers ): Webhook {
		$registry = Webhook_Registry::instance();
		$name     = isset( $headers['wpwf-webhook-name'] ) ? (string) $headers['wpwf-webhook-name'] : '';
		$webhook  = $registry->get( $name );

		if ( null === $webhook ) {
			throw new WP_Exception( 'webhook_not_found' );
		}

		return $webhook;
	}

	/**
	 * Send a webhook request and handle retries and failure tracking.
	 *
	 * @param string              $url     The webhook URL.
	 * @param string              $action  The action type.
	 * @param string              $entity  The entity type.
	 * @param int|string          $id      The entity ID.
	 * @param array<string,mixed> $payload The payload data.
	 * @param array<string,mixed> $headers The HTTP headers.
	 * @param Webhook             $webhook The webhook configuration instance.
	 *
	 * @throws WP_Exception If delivery fails.
	 */
	private function send_webhook_request( string $url, string $action, string $entity, int|string $id, array $payload, array $headers, Webhook $webhook ): void {
		if ( $this->is_url_blocked( $url ) ) {
			throw new WP_Exception( 'webhook_url_blocked' );
		}

		$payload = $this->prepare_delivery_payload( $entity, $id, $payload );

		$body = array_merge(
			$payload,
			array(
				'action' => $action,
				'entity' => $entity,
				'id'     => $id,
			)
		);

		/**
		 * Filter the webhook request body before sending.
		 *
		 * @param array<string,mixed> $body    The full webhook body.
		 * @param string              $action  The action type.
		 * @param string              $entity  The entity type.
		 * @param int|string          $id      The entity ID.
		 * @param array<string,mixed> $payload The scheduled payload data.
		 * @param Webhook             $webhook The webhook instance.
		 */
		$body = apply_filters( 'wpwf_request_body', $body, $action, $entity, $id, $payload, $webhook );

		$response = $this->perform_request( $url, $entity, $id, (string) wp_json_encode( $body ), $headers, $webhook );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			try {
				$this->schedule_retry_if_applicable( $url, $action, $entity, $id, $payload, $headers, $webhook );
			} finally {
				// A rejected retry must still count as a failed delivery and fire failure hooks.
				$this->trigger_webhook_failure( $url, $response, $webhook );
			}
			return;
		}

		$failure_dto = Failure::create_fresh();
		$failure_dto->save( $url );

		do_action( 'wpwf_webhook_success', $url, $body, $response, $webhook );
	}

	/**
	 * Queue boundary shared by producer and scheduler callbacks.
	 *
	 * @return Update_Stack
	 */
	public function get_update_stack(): Update_Stack {
		return new Update_Stack( $this );
	}

	/**
	 * Apply the request-body filter once, then persist its exact JSON for all retries.
	 *
	 * @param array<string,mixed> $body Root event fields with additive stacking metadata.
	 * @param string              $entity Entity type.
	 * @param int|string          $id Entity ID.
	 * @param array<string,mixed> $payload Latest event body.
	 * @param Webhook             $webhook Owning configuration.
	 * @return string
	 * @throws WP_Exception If filters produce an invalid or oversized request.
	 */
	public function seal_stacked_body( array $body, string $entity, int|string $id, array $payload, Webhook $webhook ): string {
		$body = apply_filters( 'wpwf_request_body', $body, 'update', $entity, $id, $payload, $webhook );
		$json = is_array( $body ) ? wp_json_encode( $body ) : false;
		if ( false === $json || 1048576 < strlen( $json ) ) {
			throw new WP_Exception( 'webhook_stack_body_invalid' );
		}
		return $json;
	}

	/**
	 * Deliver an already sealed stack; the stack owns retry scheduling and accounting.
	 *
	 * @param string               $url Destination.
	 * @param string               $entity Entity type.
	 * @param int|string           $id Entity ID.
	 * @param string               $json Immutable request JSON.
	 * @param array<string,mixed>  $headers Stored request headers.
	 * @param array<string,string> $delivery_headers Immutable stack metadata, applied after filters.
	 * @throws WP_Exception If the destination is blocked or delivery fails.
	 */
	public function process_stacked_webhook( string $url, string $entity, int|string $id, string $json, array $headers, array $delivery_headers = array() ): void {
		$webhook = $this->get_webhook_from_headers( $headers );
		if ( $this->is_url_blocked( $url ) ) {
			throw new WP_Exception( 'webhook_url_blocked' );
		}
		$response = $this->perform_request( $url, $entity, $id, $json, $headers, $webhook, $delivery_headers );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			try {
				$this->trigger_webhook_failure( $url, $response, $webhook );
			} catch ( \Throwable $error ) {
				// Listener errors must not turn a retryable HTTP failure into terminal cleanup.
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The third argument preserves the cause; only the literal error code is the public message.
				throw new WP_Exception( 'webhook_delivery_failed', 0, $error );
			}
		}
		Failure::create_fresh()->save( $url );
		do_action( 'wpwf_webhook_success', $url, json_decode( $json, true ), $response, $webhook );
	}

	/**
	 * Share transport and per-attempt header filters without mutating sealed stack bodies.
	 *
	 * @param string               $url Destination.
	 * @param string               $entity Entity type.
	 * @param int|string           $id Entity ID.
	 * @param string               $json Encoded request body.
	 * @param array<string,mixed>  $headers Request headers.
	 * @param Webhook              $webhook Owning configuration.
	 * @param array<string,string> $delivery_headers Immutable stack metadata, applied after filters.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function perform_request( string $url, string $entity, int|string $id, string $json, array $headers, Webhook $webhook, array $delivery_headers = array() ): array|\WP_Error {
		if ( ! isset( $headers['Content-Type'] ) ) {
			$headers['Content-Type'] = 'application/json';
		}
		$headers = apply_filters( 'wpwf_headers', $headers, $entity, $id, $webhook );
		// HTTP names are case-insensitive: filters cannot remove, replace or duplicate delivery identity.
		foreach ( array_keys( $headers ) as $name ) {
			if ( isset( $delivery_headers[ strtolower( (string) $name ) ] ) ) {
				unset( $headers[ $name ] );
			}
		}
		$headers = array_merge( $headers, $delivery_headers );

		$args = array(
			'body'     => $json,
			'headers'  => $headers,
			'timeout'  => $webhook->get_timeout(),
			'blocking' => true,
		);

		return wp_remote_post( $url, $args );
	}

	/**
	 * Enrich entity hints at the capture time chosen by the delivery mode.
	 *
	 * @param string              $entity  The entity type.
	 * @param int|string          $id      The entity ID.
	 * @param array<string,mixed> $payload The payload data.
	 * @return array<string,mixed> The updated payload data.
	 */
	private function prepare_delivery_payload( string $entity, int|string $id, array $payload ): array {
		$entity_id = $this->normalize_int_id( $id );
		if ( null === $entity_id ) {
			return $payload;
		}

		$handler = $this->get_entity_handler( $entity );
		if ( null === $handler ) {
			return $payload;
		}

		return $handler->prepare_delivery_payload( $entity_id, $payload );
	}

	/**
	 * Resolve the entity handler for delivery-time enrichment.
	 *
	 * @param string $entity The entity type.
	 * @return Entity_Handler|null The handler instance when available.
	 */
	private function get_entity_handler( string $entity ): ?Entity_Handler {
		return match ( $entity ) {
			'post' => new Post(),
			'term' => new Term(),
			'user' => new User(),
			'meta' => new Meta(),
			default => null,
		};
	}

	/**
	 * Normalize a mixed ID to a positive integer.
	 *
	 * @param int|string $id The entity ID.
	 * @return int|null The normalized integer ID, or null when invalid.
	 */
	private function normalize_int_id( int|string $id ): ?int {
		if ( is_int( $id ) ) {
			return 1 > $id ? null : $id;
		}

		if ( '' === $id || ! ctype_digit( $id ) ) {
			return null;
		}

		$parsed_id = (int) $id;
		return 1 > $parsed_id ? null : $parsed_id;
	}

	/**
	 * Handle failed webhook delivery.
	 *
	 * Each failed delivery attempt, including retries, increments the URL counter.
	 * If max_consecutive_failures is 0, blocking is disabled entirely.
	 *
	 * @param string  $url      The webhook URL.
	 * @param mixed   $response The response from wp_remote_post.
	 * @param Webhook $webhook  Webhook instance for configuration.
	 *
	 * @throws WP_Exception Always throws to mark Action Scheduler action as failed.
	 */
	private function trigger_webhook_failure( string $url, $response, Webhook $webhook ): void {

		// Get max consecutive failures threshold
		$max_failures = $webhook->get_max_consecutive_failures();

		// Skip failure tracking if blocking is disabled (0)
		if ( 0 === $max_failures ) {
			do_action( 'wpwf_webhook_failed', $url, $response, 0, $max_failures, $webhook );
			throw new WP_Exception( 'webhook_delivery_failed' );
		}

		// Increment consecutive failure count
		$failure_dto = Failure::from_transient( $url );
		$failure_dto->increment_count();
		$failure_dto->save( $url );

		do_action( 'wpwf_webhook_failed', $url, $response, $failure_dto->get_count(), $max_failures, $webhook );

		// Block URL if consecutive failures reach threshold
		if ( $failure_dto->get_count() >= $max_failures && ! $failure_dto->is_blocked() ) {
			$this->block_url( $url );
			do_action( 'wpwf_webhook_blocked', $url, $response, $max_failures, $webhook );
		}

		// Retries are scheduled by the framework; the exception marks this attempt failed.
		throw new WP_Exception( 'webhook_delivery_failed' );
	}

	/**
	 * Block a URL due to too many failures.
	 *
	 * @param string $url The webhook URL.
	 */
	private function block_url( string $url ): void {
		$failure_dto = Failure::from_transient( $url );
		$failure_dto->set_blocked( true )->set_blocked_time( time() );
		$failure_dto->save( $url );
	}

	/**
	 * Check if a URL is blocked due to too many failures.
	 *
	 * @param string $url The webhook URL.
	 * @return bool True if the URL is blocked.
	 */
	private function is_url_blocked( string $url ): bool {
		$failure_dto = Failure::from_transient( $url );

		// Check if block has expired (more than 1 hour ago)
		if ( $failure_dto->is_block_expired() ) {
			// Expired blocks start a fresh failure window.
			$failure_dto->reset();
			$failure_dto->save( $url );
			return false;
		}

		return $failure_dto->is_blocked();
	}

	/**
	 * Schedule a retry if retries are configured and not exhausted.
	 *
	 * @param string              $url          The webhook URL.
	 * @param string              $action       The action type.
	 * @param string              $entity       The entity type.
	 * @param int|string          $id           The entity ID.
	 * @param array<string,mixed> $payload      The payload data.
	 * @param array<string,mixed> $headers      The headers.
	 * @param Webhook|null        $webhook      Webhook instance for configuration.
	 *
	 * @throws WP_Exception If the scheduler rejects the retry.
	 */
	private function schedule_retry_if_applicable( string $url, string $action, string $entity, int|string $id, array $payload, array $headers, ?Webhook $webhook ): void {
		if ( ! $webhook ) {
			return;
		}

		// Get current retry count from headers (default to 0)
		$retry_count = isset( $headers['wpwf-retry-count'] ) ? (int) $headers['wpwf-retry-count'] : 0;
		$max_retries = $webhook->get_max_retries();

		// Exit if max retries reached
		if ( $retry_count >= $max_retries ) {
			return;
		}

		$next_retry = $retry_count + 1;
		$delay      = $webhook->calculate_retry_delay( $next_retry );

		// Update headers with new retry count
		$headers['wpwf-retry-count'] = $next_retry;

		$this->ensure_action_scheduler_available();

		// Schedule the retry
		$action_id = as_schedule_single_action(
			time() + $delay,
			'wpwf_send_webhook',
			array(
				'url'     => $url,
				'action'  => $action,
				'entity'  => $entity,
				'id'      => $id,
				'payload' => $payload,
				'headers' => $headers,
			),
			'wpwf'
		);

		if ( 0 === $action_id ) {
			throw new WP_Exception( 'webhook_retry_schedule_failed' );
		}
	}
}
