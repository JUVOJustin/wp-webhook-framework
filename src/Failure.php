<?php
/**
 * Data Transfer Object for webhook failure data.
 *
 * @package juvo\WP_Webhook_Framework
 */

namespace juvo\WP_Webhook_Framework;

/**
 * Data Transfer Object for webhook failure data.
 *
 * Tracks consecutive failed webhook events (not retry attempts) per URL.
 * Each increment represents a unique webhook that failed after all retries exhausted.
 */
class Failure {

	/**
	 * Number of consecutive failed webhook events.
	 *
	 * This counts unique webhook events that failed after all retries exhausted,
	 * not individual retry attempts. Example: 3 webhooks each failing after 5 retries = count of 3.
	 *
	 * @var int
	 */
	private int $count;

	/**
	 * Timestamp of first failure in current window.
	 *
	 * @var int
	 */
	private int $first_failure_at;

	/**
	 * Whether URL is currently blocked.
	 *
	 * @var bool
	 */
	private bool $blocked;

	/**
	 * When URL was blocked.
	 *
	 * @var int
	 */
	private int $blocked_time;

	/**
	 * Constructor.
	 *
	 * @param int  $count            Number of consecutive failures.
	 * @param int  $first_failure_at Timestamp of first failure.
	 * @param bool $blocked          Whether URL is blocked.
	 * @param int  $blocked_time     When URL was blocked.
	 */
	public function __construct( int $count = 0, int $first_failure_at = 0, bool $blocked = false, int $blocked_time = 0 ) {
		$this->count            = $count;
		$this->first_failure_at = $first_failure_at;
		$this->blocked          = $blocked;
		$this->blocked_time     = $blocked_time;
	}

	/**
	 * Create instance from transient by URL.
	 *
	 * @param string $url The webhook URL.
	 * @return Failure
	 */
	public static function from_transient( string $url ): Failure {
		$transient_key = self::get_transient_key( $url );
		$failure_data  = get_transient( $transient_key );

		if ( false === $failure_data ) {
			return self::create_fresh();
		}

		return new self(
			$failure_data['count'] ?? 0,
			$failure_data['first_failure_at'] ?? time(),
			$failure_data['blocked'] ?? false,
			$failure_data['blocked_time'] ?? 0
		);
	}

	/**
	 * Convert to array for storage.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'count'            => $this->count,
			'first_failure_at' => $this->first_failure_at,
			'blocked'          => $this->blocked,
			'blocked_time'     => $this->blocked_time,
		);
	}

	/**
	 * Get failed webhook count.
	 *
	 * Returns the number of consecutive webhook events that failed after all retries exhausted.
	 *
	 * @return int
	 */
	public function get_count(): int {
		return $this->count;
	}

	/**
	 * Set failure count.
	 *
	 * @param int $count The failure count.
	 * @return self
	 */
	public function set_count( int $count ): self {
		$this->count = $count;
		return $this;
	}

	/**
	 * Increment failure count.
	 *
	 * @return self
	 */
	public function increment_count(): self {
		++$this->count;
		return $this;
	}

	/**
	 * Get first failure timestamp.
	 *
	 * @return int
	 */
	public function get_first_failure_at(): int {
		return $this->first_failure_at;
	}

	/**
	 * Set first failure timestamp.
	 *
	 * @param int $timestamp The timestamp.
	 * @return self
	 */
	public function set_first_failure_at( int $timestamp ): self {
		$this->first_failure_at = $timestamp;
		return $this;
	}

	/**
	 * Check if URL is blocked.
	 *
	 * @return bool
	 */
	public function is_blocked(): bool {
		return $this->blocked;
	}

	/**
	 * Set blocked status.
	 *
	 * @param bool $blocked Whether URL is blocked.
	 * @return self
	 */
	public function set_blocked( bool $blocked ): self {
		$this->blocked = $blocked;
		return $this;
	}

	/**
	 * Get blocked timestamp.
	 *
	 * @return int
	 */
	public function get_blocked_time(): int {
		return $this->blocked_time;
	}

	/**
	 * Set blocked timestamp.
	 *
	 * @param int $timestamp The timestamp.
	 * @return self
	 */
	public function set_blocked_time( int $timestamp ): self {
		$this->blocked_time = $timestamp;
		return $this;
	}

	/**
	 * Check if block has expired.
	 *
	 * @return bool True if block has expired.
	 */
	public function is_block_expired(): bool {
		return $this->blocked && HOUR_IN_SECONDS < time() - $this->blocked_time;
	}

	/**
	 * Reset failure data.
	 *
	 * @return self
	 */
	public function reset(): self {
		$this->count            = 0;
		$this->first_failure_at = time();
		$this->blocked          = false;
		$this->blocked_time     = 0;
		return $this;
	}

	/**
	 * Create a fresh instance for new failures.
	 *
	 * @return Failure
	 */
	public static function create_fresh(): Failure {
		return new self( 0, time(), false, 0 );
	}

	/**
	 * Create a blocked instance.
	 *
	 * @return Failure
	 */
	public static function create_blocked(): Failure {
		return new self( 0, time(), true, time() );
	}

	/**
	 * Save current state to transient by URL.
	 *
	 * @param string $url The webhook URL.
	 * @return bool True on success, false on failure.
	 */
	public function save( string $url ): bool {
		$transient_key = self::get_transient_key( $url );
		return set_transient( $transient_key, $this->to_array(), HOUR_IN_SECONDS );
	}

	/**
	 * Get the transient key for a URL.
	 *
	 * @param string $url The webhook URL.
	 * @return string The transient key.
	 */
	private static function get_transient_key( string $url ): string {
		return 'wpwf_failures_' . md5( $url );
	}
}
