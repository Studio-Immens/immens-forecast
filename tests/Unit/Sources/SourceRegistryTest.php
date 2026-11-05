<?php
/**
 * Source registry tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

use Brain\Monkey\Functions;
use ImmensForecast\Sources\SourceRegistry;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Registration, lookup and filtering.
 */
final class SourceRegistryTest extends TestCase {

	public function test_add_get_has_ids(): void {
		$woo   = new StubSource( 'woo' );
		$manual = new StubSource( 'manual' );
		$registry = new SourceRegistry( array( $woo, $manual ) );

		self::assertTrue( $registry->has( 'woo' ) );
		self::assertSame( $woo, $registry->get( 'woo' ) );
		self::assertNull( $registry->get( 'nope' ) );
		self::assertSame( array( 'woo', 'manual' ), $registry->ids() );
		self::assertCount( 2, $registry->all() );
	}

	public function test_duplicate_id_overwrites(): void {
		$registry = new SourceRegistry();
		$registry->add( new StubSource( 'woo' ) );
		$replacement = new StubSource( 'woo' );
		$registry->add( $replacement );

		self::assertSame( $replacement, $registry->get( 'woo' ) );
		self::assertCount( 1, $registry->all() );
	}

	public function test_available_filters_unavailable(): void {
		$registry = new SourceRegistry( array( new StubSource( 'woo', true ), new StubSource( 'manual', false ) ) );
		self::assertSame( array( 'woo' ), array_keys( $registry->available() ) );
	}

	public function test_build_ignores_non_sources_from_filter(): void {
		Functions\when( 'apply_filters' )->justReturn( array( new StubSource( 'woo' ), 'garbage', 42 ) );
		$registry = SourceRegistry::build();
		self::assertSame( array( 'woo' ), $registry->ids() );
	}
}
