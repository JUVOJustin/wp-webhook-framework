<?php
/**
 * Covers the minimum WordPress version requirement.
 *
 * @package juvo\WP_Webhook_Framework\Tests
 */

declare(strict_types=1);

use juvo\WP_Webhook_Framework\Service_Provider;

/**
 * Verifies the framework refuses to boot below its documented WordPress floor.
 */
final class RequirementsTest extends WP_UnitTestCase {

	/**
	 * Ensures the documented floor matches the version that introduced WP_Exception.
	 */
	public function test_minimum_wp_version_matches_wp_exception_availability(): void {
		$this->assertSame( '6.7', Service_Provider::MIN_WP_VERSION );
	}

	/**
	 * Ensures the current test environment satisfies the requirement.
	 */
	public function test_current_environment_is_supported(): void {
		$this->assertTrue(
			Service_Provider::is_supported_wp_version(),
			sprintf( 'Test environment runs WordPress %s.', (string) get_bloginfo( 'version' ) )
		);
		$this->assertTrue( class_exists( '\WP_Exception' ) );
	}

	/**
	 * Ensures releases below the floor are rejected.
	 *
	 * @dataProvider unsupported_versions
	 *
	 * @param string $version A WordPress version older than the floor.
	 */
	public function test_older_wordpress_versions_are_rejected( string $version ): void {
		$this->with_wp_version(
			$version,
			function () use ( $version ): void {
				$this->assertFalse(
					Service_Provider::is_supported_wp_version(),
					sprintf( 'WordPress %s must be rejected.', $version )
				);
			}
		);
	}

	/**
	 * Ensures the floor itself and later releases are accepted.
	 *
	 * @dataProvider supported_versions
	 *
	 * @param string $version A WordPress version at or above the floor.
	 */
	public function test_supported_wordpress_versions_are_accepted( string $version ): void {
		$this->with_wp_version(
			$version,
			function () use ( $version ): void {
				$this->assertTrue(
					Service_Provider::is_supported_wp_version(),
					sprintf( 'WordPress %s must be accepted.', $version )
				);
			}
		);
	}

	/**
	 * Ensures the admin notice names both the requirement and the running version.
	 */
	public function test_unsupported_version_notice_reports_both_versions(): void {
		$this->with_wp_version(
			'6.6.2',
			function (): void {
				ob_start();
				Service_Provider::render_unsupported_wp_version_notice();
				$notice = (string) ob_get_clean();

				$this->assertStringContainsString( 'notice-error', $notice );
				$this->assertStringContainsString( '6.7', $notice );
				$this->assertStringContainsString( '6.6.2', $notice );
			}
		);
	}

	/**
	 * WordPress versions that must be rejected.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function unsupported_versions(): array {
		return array(
			'6.6.2' => array( '6.6.2' ),
			'6.6'   => array( '6.6' ),
			'6.5.5' => array( '6.5.5' ),
			'5.9'   => array( '5.9' ),
		);
	}

	/**
	 * WordPress versions that must be accepted.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function supported_versions(): array {
		return array(
			'6.7'   => array( '6.7' ),
			'6.7.1' => array( '6.7.1' ),
			'6.8'   => array( '6.8' ),
			'7.0.2' => array( '7.0.2' ),
		);
	}

	/**
	 * Run a callback with a temporarily overridden WordPress version.
	 *
	 * `get_bloginfo( 'version' )` reads the `$wp_version` global directly and applies
	 * no filter for the raw context, so the global is the only seam available.
	 *
	 * @param string   $version  The version to report while the callback runs.
	 * @param callable $callback The assertions to run.
	 */
	private function with_wp_version( string $version, callable $callback ): void {
		$original = $GLOBALS['wp_version'];

		$GLOBALS['wp_version'] = $version;

		try {
			$callback();
		} finally {
			$GLOBALS['wp_version'] = $original;
		}
	}
}
