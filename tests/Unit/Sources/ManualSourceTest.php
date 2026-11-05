<?php
/**
 * ManualSource tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

use Brain\Monkey\Functions;
use ImmensForecast\Sources\ManualSource;
use ImmensForecast\Storage\Datasets;
use ImmensForecast\Tests\Support\FakeWpdb;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Dataset-backed series.
 */
final class ManualSourceTest extends TestCase {

	/**
	 * Fake database.
	 *
	 * @var FakeWpdb
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_time' )->justReturn( '2026-11-01 10:00:00' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return (int) abs( (int) $value );
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Create a dataset with three daily points.
	 *
	 * @return int Dataset id.
	 */
	private function seed_dataset(): int {
		return (int) Datasets::create(
			'Web sales',
			'day',
			array(
				array( 'd' => '2026-01-01', 'v' => 10 ),
				array( 'd' => '2026-01-02', 'v' => 20 ),
				array( 'd' => '2026-01-03', 'v' => 30 ),
			)
		);
	}

	public function test_is_available_depends_on_datasets(): void {
		$source = new ManualSource();
		self::assertFalse( $source->is_available() );
		$this->seed_dataset();
		self::assertTrue( $source->is_available() );
	}

	public function test_get_series_builds_payload_and_honors_history_start(): void {
		$id     = $this->seed_dataset();
		$source = new ManualSource();

		$payload = $source->get_series(
			array(
				'dataset_id'    => $id,
				'metric'        => 'value',
				'granularity'   => 'day',
				'history_start' => '2026-01-02',
				'horizon'       => 2,
			)
		);

		self::assertSame( array( '2026-01-02', '2026-01-03' ), $payload['dates'] );
		self::assertSame( array( 20.0, 30.0 ), $payload['values'] );
		self::assertSame( array( '2026-01-04', '2026-01-05' ), $payload['future_dates'] );
		self::assertSame( 'value', $payload['id'] );
	}

	public function test_get_series_errors_when_dataset_missing(): void {
		$source = new ManualSource();
		$error  = $source->get_series( array( 'dataset_id' => 123 ) );
		self::assertSame( 'immens_forecast_unsupported_params', $error->get_error_code() );
	}

	public function test_invalid_granularity_falls_back_to_dataset(): void {
		$id     = $this->seed_dataset();
		$source = new ManualSource();
		$payload = $source->get_series(
			array(
				'dataset_id'  => $id,
				'granularity' => 'bogus',
				'horizon'     => 1,
			)
		);
		self::assertSame( 'day', $payload['granularity'] );
	}
}
