<?php
/**
 * Datasets storage tests.
 *
 * @package ImmensForecast\Tests\Unit\Storage
 */

namespace ImmensForecast\Tests\Unit\Storage;

use Brain\Monkey\Functions;
use ImmensForecast\Storage\Datasets;
use ImmensForecast\Tests\Support\FakeWpdb;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Datasets CRUD and validation.
 */
final class DatasetsTest extends TestCase {

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
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Two valid points.
	 *
	 * @return array
	 */
	private function points(): array {
		return array(
			array( 'd' => '2026-01-01', 'v' => 10 ),
			array( 'd' => '2026-01-02', 'v' => null ),
		);
	}

	public function test_table_name_uses_prefix(): void {
		self::assertSame( 'wp_immens_forecast_datasets', Datasets::table_name() );
	}

	public function test_schema_sql_has_required_columns(): void {
		$sql = Datasets::schema_sql( 'CHARSET' );
		foreach ( array( 'id', 'name', 'granularity', 'points', 'created_at', 'updated_at', 'PRIMARY KEY' ) as $needle ) {
			self::assertStringContainsString( $needle, $sql );
		}
	}

	public function test_create_table_calls_dbdelta_and_stores_version(): void {
		Functions\expect( 'dbDelta' )->once();
		Functions\expect( 'update_option' )->once()->with( Datasets::DB_VERSION_OPTION, Datasets::DB_VERSION );
		Datasets::create_table();

		// The two Mockery expectations above are the assertions for this test.
		$this->addToAssertionCount( 2 );
	}

	public function test_validate_points(): void {
		self::assertNull( Datasets::validate_points( $this->points() ) );

		self::assertSame( 'immens_forecast_csv_empty', Datasets::validate_points( array() )->get_error_code() );

		$too_many = array_fill( 0, Datasets::MAX_POINTS + 1, array( 'd' => '2026-01-01', 'v' => 1 ) );
		self::assertSame( 'immens_forecast_too_many_points', Datasets::validate_points( $too_many )->get_error_code() );

		self::assertSame( 'immens_forecast_csv_invalid_date', Datasets::validate_points( array( array( 'd' => '01/02/2026', 'v' => 1 ) ) )->get_error_code() );
		self::assertSame( 'immens_forecast_csv_invalid_value', Datasets::validate_points( array( array( 'd' => '2026-01-01', 'v' => 'x' ) ) )->get_error_code() );
	}

	public function test_encode_decode_round_trip(): void {
		$encoded = Datasets::encode_points( $this->points() );
		self::assertSame( '[{"d":"2026-01-01","v":10},{"d":"2026-01-02","v":null}]', $encoded );
		self::assertSame( array( array( 'd' => '2026-01-01', 'v' => 10.0 ), array( 'd' => '2026-01-02', 'v' => null ) ), Datasets::decode_points( $encoded ) );
		self::assertSame( array(), Datasets::decode_points( 'not-json' ) );
	}

	public function test_create_inserts_and_returns_id(): void {
		$id = Datasets::create( 'Web sales', 'day', $this->points() );
		self::assertSame( 1, $id );
		self::assertSame( 'Web sales', $this->wpdb->rows[1]['name'] );
		self::assertSame( 'day', $this->wpdb->rows[1]['granularity'] );
		self::assertStringContainsString( '2026-01-01', $this->wpdb->rows[1]['points'] );
	}

	public function test_create_rejects_when_limit_reached(): void {
		for ( $i = 0; $i < Datasets::MAX_DATASETS; $i++ ) {
			Datasets::create( 'ds-' . $i, 'day', $this->points() );
		}
		$error = Datasets::create( 'overflow', 'day', $this->points() );
		self::assertSame( 'immens_forecast_too_many_datasets', $error->get_error_code() );
	}

	public function test_get_decodes_points_and_null_when_missing(): void {
		$id  = Datasets::create( 'Web sales', 'week', $this->points() );
		$row = Datasets::get( $id );
		self::assertSame( 'week', $row['granularity'] );
		self::assertSame( 2, $row['point_count'] );
		self::assertSame( '2026-01-02', $row['points'][1]['d'] );
		self::assertNull( Datasets::get( 999 ) );
	}

	public function test_all_and_delete(): void {
		$id = Datasets::create( 'Web sales', 'day', $this->points() );
		Datasets::create( 'Store', 'day', $this->points() );

		$all = Datasets::all();
		self::assertCount( 2, $all );
		self::assertSame( 2, $all[0]['point_count'] );
		self::assertArrayNotHasKey( 'points', $all[0] );

		self::assertTrue( Datasets::delete( $id ) );
		self::assertCount( 1, Datasets::all() );
		self::assertFalse( Datasets::delete( 999 ) );
	}

	public function test_update_validates_and_persists(): void {
		$id = Datasets::create( 'Web sales', 'day', $this->points() );

		self::assertTrue( Datasets::update( $id, array( 'name' => 'Renamed', 'granularity' => 'bogus' ) ) );
		$row = Datasets::get( $id );
		self::assertSame( 'Renamed', $row['name'] );
		self::assertSame( 'day', $row['granularity'] );

		$error = Datasets::update( $id, array( 'points' => array() ) );
		self::assertSame( 'immens_forecast_csv_empty', $error->get_error_code() );

		self::assertSame( 'immens_forecast_unsupported_params', Datasets::update( 999, array( 'name' => 'x' ) )->get_error_code() );
	}
}
