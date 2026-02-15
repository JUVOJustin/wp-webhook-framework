<?php
/**
 * Term webhook implementation.
 *
 * @package juvo\WP_Webhook_Framework\Webhooks
 */

declare(strict_types=1);

namespace juvo\WP_Webhook_Framework\Webhooks;

use juvo\WP_Webhook_Framework\Webhook;
use juvo\WP_Webhook_Framework\Entities\Term;

/**
 * Term webhook implementation with configuration capabilities.
 *
 * Handles term-related webhook events with configurable retry policies,
 * timeouts, and other webhook-specific settings.
 */
class Term_Webhook extends Webhook {

	/**
	 * The term handler instance.
	 *
	 * @var Term
	 */
	private Term $term_handler;

	/**
	 * Constructor.
	 *
	 * @param string $name The webhook name.
	 * @phpstan-param non-empty-string $name
	 */
	public function __construct( string $name = 'term' ) {
		parent::__construct( $name );

		// Get dispatcher from registry
		$this->term_handler = new Term();
	}

	/**
	 * Initialize the webhook by registering WordPress hooks.
	 */
	public function init(): void {
		add_action( 'created_term', array( $this, 'on_created_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_edited_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_deleted_term' ), 10, 3 );
	}

	/**
	 * Handle term creation event.
	 *
	 * @param int    $term_id  The term ID.
	 * @param int    $tt_id    The term taxonomy ID.
	 * @param string $taxonomy The taxonomy name.
	 */
	public function on_created_term( int $term_id, int $tt_id, string $taxonomy ): void {
		$payload = $this->term_handler->prepare_payload( $term_id );
		$this->emit( 'create', 'term', $term_id, $payload );
	}

	/**
	 * Handle term update event.
	 *
	 * @param int    $term_id  The term ID.
	 * @param int    $tt_id    The term taxonomy ID.
	 * @param string $taxonomy The taxonomy name.
	 */
	public function on_edited_term( int $term_id, int $tt_id, string $taxonomy ): void {
		$payload = $this->term_handler->prepare_payload( $term_id );
		$this->emit( 'update', 'term', $term_id, $payload );
	}

	/**
	 * Handle term deletion event.
	 *
	 * @param int    $term_id  The term ID.
	 * @param int    $tt_id    The term taxonomy ID.
	 * @param string $taxonomy The taxonomy name.
	 */
	public function on_deleted_term( int $term_id, int $tt_id, string $taxonomy ): void {
		$payload = $this->term_handler->prepare_payload( $term_id );
		$this->emit( 'delete', 'term', $term_id, $payload );
	}

	/**
	 * Get the term handler instance.
	 *
	 * @return Term
	 */
	public function get_handler(): Term {
		return $this->term_handler;
	}
}
