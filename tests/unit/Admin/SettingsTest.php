<?php
/**
 * Settings tests.
 *
 * @package ImmensForecast\Tests\Unit\Admin
 */

namespace ImmensForecast\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use ImmensForecast\Admin\Settings;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Settings accessor and sanitizer.
 */
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Settings::reset_cache();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return (int) abs( (int) $value );
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
	}

	public function test_defaults_have_expected_keys(): void {
		$defaults = Settings::defaults();
		foreach ( array( 'provider', 'endpoint_url', 'endpoint_token_enc', 'endpoint_model', 'endpoint_timeout', 'quantiles', 'history_days', 'horizon', 'delete_data_on_uninstall' ) as $key ) {
			self::assertArrayHasKey( $key, $defaults );
		}
		self::assertSame( array( 0.1, 0.5, 0.9 ), $defaults['quantiles'] );
	}

	public function test_all_merges_stored_over_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array( 'endpoint_model' => '28m' ) );
		Settings::reset_cache();
		self::assertSame( '28m', Settings::get( 'endpoint_model' ) );
		self::assertSame( 'baseline', Settings::get( 'provider' ) );
		self::assertSame( 'fallback', Settings::get( 'missing', 'fallback' ) );
	}

	public function test_update_merges_and_persists(): void {
		Functions\expect( 'update_option' )->once()->with( Settings::OPTION, \Mockery::type( 'array' ) )->andReturn( true );
		$result = Settings::update( array( 'horizon' => 14 ) );
		self::assertSame( 14, $result['horizon'] );
	}

	public function test_capability_uses_filter_value(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		self::assertSame( 'manage_options', Settings::capability() );
	}

	public function test_sanitize_clamps_timeout_and_history(): void {
		$clean = Settings::sanitize( array( 'endpoint_timeout' => 1, 'history_days' => 99999, 'horizon' => 9999 ) );
		self::assertSame( 5, $clean['endpoint_timeout'] );
		self::assertSame( 1095, $clean['history_days'] );
		self::assertSame( 180, $clean['horizon'] );

		$clean = Settings::sanitize( array( 'endpoint_timeout' => 9999 ) );
		self::assertSame( 300, $clean['endpoint_timeout'] );
	}

	public function test_sanitize_endpoint_url_rejects_non_http_and_trims(): void {
		self::assertSame( '', Settings::sanitize( array( 'endpoint_url' => 'ftp://example.test' ) )['endpoint_url'] );
		self::assertSame( 'https://api.example.test', Settings::sanitize( array( 'endpoint_url' => 'https://api.example.test/' ) )['endpoint_url'] );
	}

	public function test_sanitize_encrypts_new_token_and_keeps_existing_on_blank(): void {
		$clean = Settings::sanitize( array( 'endpoint_token' => 'secret-token' ) );
		self::assertNotSame( 'secret-token', $clean['endpoint_token_enc'] );
		self::assertMatchesRegularExpression( '/^[as]1:/', $clean['endpoint_token_enc'] );

		Functions\when( 'get_option' )->justReturn( array( 'endpoint_token_enc' => 'a1:keepme' ) );
		Settings::reset_cache();
		self::assertSame( 'a1:keepme', Settings::sanitize( array( 'endpoint_token' => '' ) )['endpoint_token_enc'] );

		self::assertSame( '', Settings::sanitize( array( 'endpoint_clear_token' => '1' ) )['endpoint_token_enc'] );
	}

	public function test_sanitize_quantiles_parses_dedupes_and_falls_back(): void {
		self::assertSame( array( 0.1, 0.5, 0.9 ), Settings::sanitize_quantiles( '0.1, 0.5, 0.9' ) );
		self::assertSame( array( 0.1, 0.5, 0.9 ), Settings::sanitize_quantiles( array( 0.9, 0.1, 0.5, 0.5 ) ) );
		self::assertSame( array( 0.1, 0.5, 0.9 ), Settings::sanitize_quantiles( array( 'x' ) ) );
		self::assertSame( array( 0.1, 0.5, 0.9 ), Settings::sanitize_quantiles( array( 2 ) ) );
	}

	public function test_endpoint_helpers(): void {
		Functions\when( 'get_option' )->justReturn( array( 'endpoint_url' => 'https://api.example.test' ) );
		Settings::reset_cache();
		self::assertTrue( Settings::is_endpoint_configured() );

		$crypto = new \ImmensForecast\Forecast\Crypto( array( 'openssl' ) );
		Functions\when( 'get_option' )->justReturn( array( 'endpoint_token_enc' => $crypto->encrypt( 'tok' ) ) );
		Settings::reset_cache();
		self::assertSame( 'tok', Settings::endpoint_token() );
	}
}
