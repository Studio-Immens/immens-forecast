<?php
/**
 * Bootstrap smoke tests.
 *
 * @package ImmensForecast\Tests\Unit
 */

namespace ImmensForecast\Tests\Unit;

use Brain\Monkey\Functions;
use ImmensForecast\Forecast\Engine;

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

	/**
	 * The engine accessor returns a usable engine instance.
	 */
	public function test_engine_returns_engine_instance(): void {
		self::assertInstanceOf( Engine::class, \ImmensForecast\Plugin::instance()->engine() );
	}

	/**
	 * Boot registers the bundled sources and providers.
	 */
	public function test_boot_registers_core_sources_and_providers(): void {
		$captured = array();

		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'plugin_basename' )->justReturn( 'immens-forecast/immens-forecast.php' );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'add_filter' )->alias(
			static function ( $tag, $callback ) use ( &$captured ) {
				$captured[ $tag ] = $callback;
				return null;
			}
		);

		\ImmensForecast\Plugin::instance()->boot();

		self::assertArrayHasKey( 'immens_forecast_sources', $captured );
		self::assertArrayHasKey( 'immens_forecast_providers', $captured );

		$sources = call_user_func( $captured['immens_forecast_sources'], array() );
		self::assertCount( 2, $sources );
		self::assertInstanceOf( \ImmensForecast\Sources\WooSource::class, $sources[0] );
		self::assertInstanceOf( \ImmensForecast\Sources\ManualSource::class, $sources[1] );

		$providers = call_user_func( $captured['immens_forecast_providers'], array() );
		self::assertCount( 2, $providers );
		self::assertInstanceOf( \ImmensForecast\Forecast\Providers\BaselineProvider::class, $providers[0] );
		self::assertInstanceOf( \ImmensForecast\Forecast\Providers\EndpointProvider::class, $providers[1] );
	}
}
