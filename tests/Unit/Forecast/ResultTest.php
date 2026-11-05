<?php
/**
 * Result assembler tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast
 */

namespace ImmensForecast\Tests\Unit\Forecast;

use Brain\Monkey\Functions;
use ImmensForecast\Forecast\Result;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Payload enrichment and KPI maths.
 */
final class ResultTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Build the provider payload fixture.
	 *
	 * @param int $median_points Number of forecast points.
	 * @return array
	 */
	private function payload( int $median_points = 2 ): array {
		$median = array_slice( array( 40.0, 50.0, 60.0 ), 0, $median_points );
		$low    = array_slice( array( 30.0, 40.0, 50.0 ), 0, $median_points );
		$high   = array_slice( array( 50.0, 60.0, 70.0 ), 0, $median_points );

		return array(
			'model'           => 'baseline-seasonal',
			'horizon'         => 2,
			'quantile_levels' => array( 0.1, 0.5, 0.9 ),
			'series'          => array(
				array(
					'id'           => 'revenue',
					'context_used' => 3,
					'median'       => $median,
					'quantiles'    => array(
						'0.1' => $low,
						'0.5' => $median,
						'0.9' => $high,
					),
				),
			),
		);
	}

	/**
	 * Build the series fixture.
	 *
	 * @return array
	 */
	private function series(): array {
		return array(
			'id'           => 'revenue',
			'dates'        => array( '2025-01-01', '2025-01-02', '2025-01-03' ),
			'values'       => array( 10.0, 20.0, 30.0 ),
			'future_dates' => array( '2025-01-04', '2025-01-05' ),
		);
	}

	/**
	 * Build the meta fixture.
	 *
	 * @param array $overrides Meta overrides.
	 * @return array
	 */
	private function meta( array $overrides = array() ): array {
		return array_merge(
			array(
				'provider'        => 'baseline',
				'model'           => 'baseline-seasonal',
				'horizon'         => 2,
				'quantile_levels' => array( 0.1, 0.5, 0.9 ),
				'elapsed_ms'      => 12,
				'cached'          => false,
				'fallback'        => false,
				'fallback_reason' => null,
				'fallback_code'   => null,
			),
			$overrides
		);
	}

	public function test_assemble_attaches_dates_history_and_slices(): void {
		$result = Result::assemble( $this->payload(), $this->series(), $this->meta() );

		self::assertSame( array( '2025-01-04', '2025-01-05' ), $result['dates'] );
		self::assertSame( array( '2025-01-01', '2025-01-02', '2025-01-03' ), $result['history']['dates'] );
		self::assertSame( array( 10.0, 20.0, 30.0 ), $result['history']['values'] );
		self::assertCount( 2, $result['median'] );
		self::assertCount( 2, $result['quantiles']['0.9'] );
		self::assertSame( 'baseline', $result['provider'] );
		self::assertSame( 12, $result['elapsed_ms'] );
	}

	public function test_assemble_slices_longer_median_to_horizon(): void {
		$result = Result::assemble( $this->payload( 3 ), $this->series(), $this->meta() );

		self::assertSame( array( 40.0, 50.0 ), $result['median'] );
		self::assertCount( 2, $result['quantiles']['0.1'] );
	}

	public function test_kpis_compute_totals_delta_and_width(): void {
		$result = Result::assemble( $this->payload(), $this->series(), $this->meta() );

		self::assertSame( 90.0, $result['kpis']['forecast_total'] );
		self::assertSame( 50.0, $result['kpis']['previous_total'] );
		self::assertSame( 80.0, $result['kpis']['delta_pct'] );
		self::assertSame( 20.0, $result['kpis']['interval_width'] );
	}

	public function test_kpis_delta_null_without_prior_data(): void {
		$series        = $this->series();
		$series['values'] = array();

		$result = Result::assemble( $this->payload(), $series, $this->meta() );

		self::assertSame( 0.0, $result['kpis']['previous_total'] );
		self::assertNull( $result['kpis']['delta_pct'] );
	}

	public function test_fallback_meta_is_preserved(): void {
		$meta = $this->meta(
			array(
				'provider'        => 'baseline',
				'fallback'        => true,
				'fallback_reason' => 'The endpoint timed out.',
				'fallback_code'   => 'immens_forecast_endpoint_timeout',
			)
		);

		$result = Result::assemble( $this->payload(), $this->series(), $meta );

		self::assertTrue( $result['fallback'] );
		self::assertSame( 'The endpoint timed out.', $result['fallback_reason'] );
		self::assertSame( 'immens_forecast_endpoint_timeout', $result['fallback_code'] );
		self::assertFalse( $result['cached'] );
	}
}
