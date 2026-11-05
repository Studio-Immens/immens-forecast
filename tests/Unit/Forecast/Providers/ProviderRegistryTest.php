<?php
/**
 * Provider registry tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast\Providers
 */

namespace ImmensForecast\Tests\Unit\Forecast\Providers;

use Brain\Monkey\Functions;
use ImmensForecast\Forecast\Providers\ProviderRegistry;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Registration, lookup and filtering.
 */
final class ProviderRegistryTest extends TestCase {

	public function test_add_get_has_ids(): void {
		$baseline = new StubProvider( 'baseline' );
		$endpoint = new StubProvider( 'endpoint' );
		$registry = new ProviderRegistry( array( $baseline, $endpoint ) );

		self::assertTrue( $registry->has( 'baseline' ) );
		self::assertSame( $baseline, $registry->get( 'baseline' ) );
		self::assertNull( $registry->get( 'nope' ) );
		self::assertSame( array( 'baseline', 'endpoint' ), $registry->ids() );
		self::assertCount( 2, $registry->all() );
	}

	public function test_duplicate_id_overwrites(): void {
		$registry = new ProviderRegistry();
		$registry->add( new StubProvider( 'baseline' ) );
		$replacement = new StubProvider( 'baseline' );
		$registry->add( $replacement );

		self::assertSame( $replacement, $registry->get( 'baseline' ) );
		self::assertCount( 1, $registry->all() );
	}

	public function test_available_filters_unavailable(): void {
		$registry = new ProviderRegistry(
			array(
				new StubProvider( 'baseline', true ),
				new StubProvider( 'endpoint', false ),
			)
		);

		self::assertSame( array( 'baseline' ), array_keys( $registry->available() ) );
	}

	public function test_build_uses_filter_and_ignores_non_providers(): void {
		Functions\when( 'apply_filters' )->justReturn( array( new StubProvider( 'baseline' ), 'garbage', 42 ) );

		$registry = ProviderRegistry::build();

		self::assertSame( array( 'baseline' ), $registry->ids() );
	}

	public function test_build_with_empty_default_returns_empty_registry(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$registry = ProviderRegistry::build();

		self::assertSame( array(), $registry->ids() );
	}
}
