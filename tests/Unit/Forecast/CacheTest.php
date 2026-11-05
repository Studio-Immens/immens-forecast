<?php
/**
 * Cache helper tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast
 */

namespace ImmensForecast\Tests\Unit\Forecast;

use Brain\Monkey\Functions;
use ImmensForecast\Forecast\Cache;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Keys, TTL, transients and locks.
 */
final class CacheTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	public function test_key_is_stable_and_prefixed(): void {
		$parts = array( 'woo', 'revenue', 'day', '2025-01-01', 30, 'baseline', '209m', '0.1,0.5,0.9', 'abc', '1.0.0' );

		$key = Cache::key( $parts );

		self::assertSame( $key, Cache::key( $parts ) );
		self::assertStringStartsWith( 'immens_forecast_', $key );
		self::assertSame( 32, strlen( substr( $key, strlen( 'immens_forecast_' ) ) ) );
	}

	public function test_key_changes_with_parts(): void {
		$base = array( 'woo', 'revenue', 'day', '2025-01-01', 30 );

		self::assertNotSame( Cache::key( $base ), Cache::key( array( 'woo', 'revenue', 'day', '2025-01-01', 60 ) ) );
	}

	public function test_ttl_default_is_12h(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		self::assertSame( 43200, Cache::ttl() );
	}

	public function test_ttl_uses_filter(): void {
		Functions\when( 'apply_filters' )->justReturn( 120 );

		self::assertSame( 120, Cache::ttl() );
	}

	public function test_ttl_clamps_to_minimum(): void {
		Functions\when( 'apply_filters' )->justReturn( 5 );

		self::assertSame( 60, Cache::ttl() );
	}

	public function test_get_delegates_to_transient(): void {
		Functions\expect( 'get_transient' )->once()->with( 'immens_forecast_abc' )->andReturn( array( 'ok' => true ) );

		self::assertSame( array( 'ok' => true ), Cache::get( 'immens_forecast_abc' ) );
	}

	public function test_set_delegates_to_transient_with_ttl(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\expect( 'set_transient' )->once()->with( 'immens_forecast_x', array( 'a' => 1 ), 43200 )->andReturn( true );

		Cache::set( 'immens_forecast_x', array( 'a' => 1 ) );

		// The Mockery expectation above is the assertion for this test.
		$this->addToAssertionCount( 1 );
	}

	public function test_forget_delegates_to_transient(): void {
		Functions\expect( 'delete_transient' )->once()->with( 'immens_forecast_x' )->andReturn( true );

		Cache::forget( 'immens_forecast_x' );

		// The Mockery expectation above is the assertion for this test.
		$this->addToAssertionCount( 1 );
	}

	public function test_lock_acquire_and_release(): void {
		self::assertSame( 'immens_forecast_lock_7', Cache::lock_key( 7 ) );

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		self::assertTrue( Cache::acquire_lock( 7 ) );

		Functions\when( 'get_transient' )->justReturn( 1 );
		self::assertFalse( Cache::acquire_lock( 7 ) );

		Functions\expect( 'delete_transient' )->once()->with( 'immens_forecast_lock_7' )->andReturn( true );
		Cache::release_lock( 7 );
	}
}
