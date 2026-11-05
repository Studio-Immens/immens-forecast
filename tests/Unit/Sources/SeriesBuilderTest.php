<?php
/**
 * SeriesBuilder tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

use ImmensForecast\Sources\SeriesBuilder;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Payload shape and future dates.
 */
final class SeriesBuilderTest extends TestCase {

	/**
	 * Three daily points ending 2026-01-03.
	 *
	 * @return array
	 */
	private function points(): array {
		return array(
			array( 'd' => '2026-01-01', 'v' => 10.0 ),
			array( 'd' => '2026-01-02', 'v' => null ),
			array( 'd' => '2026-01-03', 'v' => 30.0 ),
		);
	}

	public function test_build_payload_shape(): void {
		$payload = SeriesBuilder::build( $this->points(), 'day', 2, 'value' );

		self::assertSame( 'value', $payload['id'] );
		self::assertSame( array( '2026-01-01', '2026-01-02', '2026-01-03' ), $payload['dates'] );
		self::assertSame( array( 10.0, null, 30.0 ), $payload['values'] );
		self::assertSame( array( '2026-01-04', '2026-01-05' ), $payload['future_dates'] );
		self::assertCount( 3, $payload['past_covariates']['dow'] );
		self::assertCount( 5, $payload['known_covariates']['dow'] );
	}

	public function test_future_dates_weekly_step(): void {
		self::assertSame( array( '2026-01-10', '2026-01-17' ), SeriesBuilder::future_dates( '2026-01-03', 'week', 2 ) );
	}

	public function test_dow_alignment(): void {
		$payload = SeriesBuilder::build( $this->points(), 'day', 1 );
		self::assertSame( array( 4, 5, 6, 0 ), $payload['known_covariates']['dow'] );
	}
}
