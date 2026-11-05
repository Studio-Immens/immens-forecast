<?php
/**
 * Baseline provider tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast\Providers
 */

namespace ImmensForecast\Tests\Unit\Forecast\Providers;

use Brain\Monkey\Functions;
use ImmensForecast\Forecast\Providers\BaselineProvider;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Deterministic seasonal baseline.
 */
final class BaselineProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Build a normalized series, deriving past covariates from the full ones.
	 *
	 * @param string $id     Series id.
	 * @param array  $values Values.
	 * @param array  $known  Full-length known covariates.
	 * @return array
	 */
	private function series( string $id, array $values, array $known = array() ): array {
		$series = array(
			'id'     => $id,
			'values' => $values,
		);
		if ( ! empty( $known ) ) {
			$series['known_covariates'] = $known;
			$series['past_covariates']  = array();
			foreach ( $known as $key => $full ) {
				$series['past_covariates'][ $key ] = array_slice( $full, 0, count( $values ) );
			}
		}
		return $series;
	}

	public function test_insufficient_points_error(): void {
		$provider = new BaselineProvider();
		$result   = $provider->forecast( array( $this->series( 'revenue', array_fill( 0, 15, 10.0 ) ) ), 3, array() );

		self::assertSame( 'immens_forecast_insufficient_data', $result->get_error_code() );
		self::assertSame( array( 'points' => 15, 'finite' => 15 ), $result->get_error_data() );
	}

	public function test_insufficient_finite_values_error(): void {
		$provider = new BaselineProvider();
		$values   = array_merge( array( 1.0, 2.0, 3.0 ), array_fill( 0, 13, null ) );
		$result   = $provider->forecast( array( $this->series( 'revenue', $values ) ), 2, array() );

		self::assertSame( 'immens_forecast_insufficient_data', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 16, $data['points'] );
		self::assertSame( 3, $data['finite'] );
	}

	public function test_horizon_out_of_range_error(): void {
		$provider = new BaselineProvider();
		$series   = array( $this->series( 'revenue', array_fill( 0, 16, 10.0 ) ) );

		self::assertSame( 'immens_forecast_unsupported_params', $provider->forecast( $series, 0, array() )->get_error_code() );
		self::assertSame( 'immens_forecast_unsupported_params', $provider->forecast( $series, 181, array() )->get_error_code() );
	}

	public function test_constant_series_flat_band(): void {
		$provider = new BaselineProvider();
		$result   = $provider->forecast(
			array( $this->series( 'revenue', array_fill( 0, 20, 50.0 ) ) ),
			3,
			array( 'quantiles' => array( 0.1, 0.5, 0.9 ) )
		);

		self::assertSame( 'baseline', $result['model'] );
		self::assertSame( 3, $result['horizon'] );
		self::assertSame( array( 0.1, 0.5, 0.9 ), $result['quantile_levels'] );

		$series = $result['series'][0];
		self::assertSame( 'revenue', $series['id'] );
		self::assertSame( 20, $series['context_used'] );
		self::assertSame( array( 50.0, 50.0, 50.0 ), $series['median'] );
		self::assertSame( array( 50.0, 50.0, 50.0 ), $series['quantiles']['0.1'] );
		self::assertSame( array( 50.0, 50.0, 50.0 ), $series['quantiles']['0.9'] );
	}

	public function test_daily_seasonality_repeats_pattern(): void {
		$provider = new BaselineProvider();
		$pattern  = array( 10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0 );
		$values   = array_merge( $pattern, $pattern, $pattern, $pattern );
		$dow      = array();
		for ( $i = 0; $i < 31; $i++ ) {
			$dow[] = $i % 7;
		}

		$result = $provider->forecast(
			array( $this->series( 'revenue', $values, array( 'dow' => $dow ) ) ),
			3,
			array( 'granularity' => 'day' )
		);

		$median = $result['series'][0]['median'];
		self::assertEqualsWithDelta( 10.0, $median[0], 1e-9 );
		self::assertEqualsWithDelta( 20.0, $median[1], 1e-9 );
		self::assertEqualsWithDelta( 30.0, $median[2], 1e-9 );
	}

	public function test_missing_covariates_gives_flat_forecast(): void {
		$provider = new BaselineProvider();
		$values   = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$values[] = ( 0 === $i % 7 ) ? 10.0 : 100.0;
		}

		$result = $provider->forecast( array( $this->series( 'revenue', $values ) ), 3, array() );
		$median = $result['series'][0]['median'];

		self::assertCount( 3, $median );
		self::assertEqualsWithDelta( $median[0], $median[1], 1e-9 );
		self::assertEqualsWithDelta( $median[0], $median[2], 1e-9 );
	}

	public function test_weekly_seasonality_with_woy(): void {
		$provider = new BaselineProvider();
		$pattern  = array();
		for ( $i = 0; $i < 52; $i++ ) {
			$pattern[] = 50.0 + $i;
		}
		$values = array_merge( $pattern, $pattern );
		$woy    = array();
		for ( $i = 0; $i < 156; $i++ ) {
			$woy[] = $i % 52;
		}

		$result = $provider->forecast(
			array( $this->series( 'revenue', $values, array( 'woy' => $woy ) ) ),
			52,
			array( 'granularity' => 'week' )
		);

		$median = $result['series'][0]['median'];
		self::assertCount( 52, $median );
		self::assertGreaterThan( $median[0], $median[1] );
		self::assertGreaterThan( $median[0], $median[51] );
	}

	public function test_weekly_without_woy_is_level_only(): void {
		$provider = new BaselineProvider();
		$pattern  = array();
		for ( $i = 0; $i < 52; $i++ ) {
			$pattern[] = 50.0 + $i;
		}

		$result = $provider->forecast(
			array( $this->series( 'revenue', array_merge( $pattern, $pattern ) ) ),
			6,
			array( 'granularity' => 'week' )
		);

		$median = $result['series'][0]['median'];
		self::assertEqualsWithDelta( $median[0], $median[5], 1e-9 );
	}

	public function test_empirical_quantiles_widen_and_stay_positive(): void {
		$provider = new BaselineProvider();
		$values   = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$values[] = ( 0 === $i % 2 ) ? 100.0 : 200.0;
		}

		$result = $provider->forecast(
			array( $this->series( 'revenue', $values ) ),
			4,
			array( 'quantiles' => array( 0.1, 0.5, 0.9 ) )
		);

		$series = $result['series'][0];
		for ( $step = 0; $step < 4; $step++ ) {
			self::assertLessThan( $series['median'][ $step ], $series['quantiles']['0.1'][ $step ] );
			self::assertGreaterThan( $series['median'][ $step ], $series['quantiles']['0.9'][ $step ] );
			self::assertGreaterThanOrEqual( 0.0, $series['quantiles']['0.1'][ $step ] );
		}
	}

	public function test_non_negative_clamp_applies_by_default(): void {
		$provider = new BaselineProvider();

		// Only 7 in-sample residuals → Gaussian fallback with σ = 1.4826·MAD, wide
		// enough to push the 10% quantile below zero. The level is small (10) because
		// the finite values cycle through 0, 10 and 300.
		$values = array();
		$cycle  = array( 0.0, 10.0, 300.0 );
		for ( $i = 0; $i < 16; $i++ ) {
			$values[] = ( 0 === $i % 2 ) ? $cycle[ ( $i / 2 ) % 3 ] : null;
		}
		$series = $this->series( 'revenue', $values );

		$clamped = $provider->forecast( array( $series ), 2, array( 'quantiles' => array( 0.1, 0.5, 0.9 ) ) );
		$raw     = $provider->forecast(
			array( $series ),
			2,
			array(
				'quantiles'    => array( 0.1, 0.5, 0.9 ),
				'non_negative' => false,
			)
		);

		$clamped_low = $clamped['series'][0]['quantiles']['0.1'][0];
		$raw_low     = $raw['series'][0]['quantiles']['0.1'][0];

		self::assertSame( 0.0, $clamped_low );
		self::assertLessThan( 0.0, $raw_low );
	}

	public function test_multiple_series_are_returned(): void {
		$provider = new BaselineProvider();
		$result   = $provider->forecast(
			array(
				$this->series( 'revenue', array_fill( 0, 18, 100.0 ) ),
				$this->series( 'orders', array_fill( 0, 18, 5.0 ) ),
			),
			2,
			array()
		);

		self::assertSame( array( 'revenue', 'orders' ), array_column( $result['series'], 'id' ) );
		self::assertSame( 5.0, $result['series'][1]['median'][1] );
	}
}
