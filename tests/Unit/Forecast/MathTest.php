<?php
/**
 * Math helper tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast
 */

namespace ImmensForecast\Tests\Unit\Forecast;

use ImmensForecast\Forecast\Math;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Median, MAD, quantile, formatting.
 */
final class MathTest extends TestCase {

	public function test_median_odd_and_even(): void {
		self::assertSame( 2.0, Math::median( array( 3, 1, 2 ) ) );
		self::assertSame( 2.5, Math::median( array( 4, 1, 3, 2 ) ) );
	}

	public function test_finite_ignores_null_and_nan_and_strings(): void {
		self::assertSame( array( 1.0, 2.0 ), Math::finite( array( 1, null, 2, 'x', NAN ) ) );
		self::assertSame( 0.0, Math::median( array( null, 'a' ) ) );
	}

	public function test_quantile_linear_interpolation(): void {
		$values = array( 1, 2, 3, 4, 5 );
		self::assertSame( 1.0, Math::quantile( $values, 0.0 ) );
		self::assertSame( 1.4, round( Math::quantile( $values, 0.1 ), 4 ) );
		self::assertSame( 3.0, Math::quantile( $values, 0.5 ) );
		self::assertSame( 5.0, Math::quantile( $values, 1.0 ) );
	}

	public function test_mad_around_median(): void {
		self::assertSame( 1.0, Math::mad( array( 1, 2, 3, 4, 5 ) ) );
	}

	public function test_format_level_matches_output_contract(): void {
		self::assertSame( '0.1', Math::format_level( 0.1 ) );
		self::assertSame( '0.5', Math::format_level( 0.5 ) );
		self::assertSame( '0.9', Math::format_level( 0.9 ) );
		self::assertSame( '0.05', Math::format_level( 0.05 ) );
		self::assertSame( '0.01', Math::format_level( 0.01 ) );
		self::assertSame( '0.99', Math::format_level( 0.99 ) );
	}

	public function test_clamp_and_round(): void {
		self::assertSame( 5.0, Math::clamp( 1.0, 5.0, 10.0 ) );
		self::assertSame( 10.0, Math::clamp( 99.0, 5.0, 10.0 ) );
		self::assertSame( array( 1.23, null ), Math::round( array( 1.234, 'x' ), 2 ) );
	}
}
