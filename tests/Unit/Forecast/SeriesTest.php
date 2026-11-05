<?php
/**
 * Series normalization and validation tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast
 */

namespace ImmensForecast\Tests\Unit\Forecast;

use Brain\Monkey\Functions;
use ImmensForecast\Forecast\Series;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Series helper tests.
 */
final class SeriesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->returnArg();
	}

	public function test_normalize_casts_values_and_nullables(): void {
		$series = Series::normalize(
			array(
				'id'     => 'revenue',
				'values' => array( '1.5', 2, null, 'x' ),
			)
		);

		self::assertSame( 'revenue', $series['id'] );
		self::assertSame( array( 1.5, 2.0, null, null ), $series['values'] );
		self::assertNull( $series['past_covariates'] );
		self::assertNull( $series['known_covariates'] );
	}

	public function test_default_id_when_missing(): void {
		self::assertSame( 'series', Series::normalize( array( 'values' => array( 1 ) ) )['id'] );
	}

	public function test_finite_count_and_non_negative(): void {
		$series = array( 'values' => array( 1, 2, null, 'x', 0 ) );
		self::assertSame( 3, Series::finite_count( $series ) );
		self::assertTrue( Series::is_non_negative( $series ) );
		self::assertFalse( Series::is_non_negative( array( 'values' => array( 1, -1 ) ) ) );
	}

	public function test_hash_is_stable_and_sensitive(): void {
		$a = array( 'values' => array( 1, 2, 3 ) );
		$b = array( 'values' => array( 1, 2, 3 ) );
		$c = array( 'values' => array( 1, 2, 4 ) );
		self::assertSame( Series::hash( $a ), Series::hash( $b ) );
		self::assertNotSame( Series::hash( $a ), Series::hash( $c ) );
	}

	public function test_insufficient_error_returns_null_when_enough(): void {
		self::assertNull( Series::insufficient_error( array( 'values' => array_fill( 0, 16, 5 ) ) ) );
	}

	public function test_insufficient_error_reports_counts(): void {
		$error = Series::insufficient_error( array( 'values' => array( 1, 2, 3 ) ) );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'immens_forecast_insufficient_data', $error->get_error_code() );
		self::assertSame( array( 'points' => 3, 'finite' => 3 ), $error->get_error_data( 'immens_forecast_insufficient_data' ) );
	}
}
