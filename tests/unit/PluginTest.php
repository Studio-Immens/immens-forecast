<?php
/**
 * Bootstrap smoke tests.
 *
 * @package ImmensForecast\Tests\Unit
 */

namespace ImmensForecast\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Verify constants, autoloader and singleton.
 */
final class PluginTest extends TestCase {

	public function test_constants_are_defined(): void {
		self::assertSame( '0.1.0-test', IMMENS_FORECAST_VERSION );
		self::assertNotEmpty( IMMENS_FORECAST_PATH );
	}

	public function test_singleton_identity(): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );

		$one = \ImmensForecast\Plugin::instance();
		$two = \ImmensForecast\Plugin::instance();
		self::assertSame( $one, $two );
	}

	public function test_autoloader_is_registered(): void {
		self::assertTrue( class_exists( 'ImmensForecast\\Plugin' ) );
	}
}
