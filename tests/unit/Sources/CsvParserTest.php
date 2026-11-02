<?php
/**
 * CSV parser tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

use Brain\Monkey\Functions;
use ImmensForecast\Sources\CsvParser;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Parsing, validation and error codes.
 */
final class CsvParserTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2026-01-10' );
	}

	public function test_parses_date_value_pairs_with_various_separators(): void {
		$points = CsvParser::parse( "date,value\n2026-01-01,10\n2026-01-02; 12,5\n2026-01-03\t20" );
		self::assertSame( array( '2026-01-01', '2026-01-02', '2026-01-03' ), array_column( $points, 'd' ) );
		self::assertSame( array( 10.0, 12.5, 20.0 ), array_column( $points, 'v' ) );

		$points = CsvParser::parse( "2026-01-01 10\n2026-01-02 12" );
		self::assertCount( 2, $points );
	}

	public function test_accepts_dd_mm_yyyy(): void {
		$points = CsvParser::parse( "15/03/2026,42" );
		self::assertSame( '2026-03-15', $points[0]['d'] );
	}

	public function test_missing_values_with_nan_tokens(): void {
		$points = CsvParser::parse( "2026-01-01,NaN\n2026-01-02,-" );
		self::assertSame( array( null, null ), array_column( $points, 'v' ) );
	}

	public function test_values_only_generates_dates_backwards(): void {
		$points = CsvParser::parse( "10\n20\n30", 'day', '2026-01-03' );
		self::assertSame( array( '2026-01-01', '2026-01-02', '2026-01-03' ), array_column( $points, 'd' ) );

		$weekly = CsvParser::parse( "10\n20", 'week', '2026-01-15' );
		self::assertSame( array( '2026-01-08', '2026-01-15' ), array_column( $weekly, 'd' ) );
	}

	public function test_sorts_ascending(): void {
		$points = CsvParser::parse( "2026-02-01,1\n2026-01-01,2" );
		self::assertSame( array( '2026-01-01', '2026-02-01' ), array_column( $points, 'd' ) );
	}

	public function test_error_codes(): void {
		self::assertSame( 'immens_forecast_csv_empty', CsvParser::parse( "  \n " )->get_error_code() );
		self::assertSame( 'immens_forecast_csv_mixed_lines', CsvParser::parse( "2026-01-01,1\n2" )->get_error_code() );
		self::assertSame( 'immens_forecast_csv_invalid_date', CsvParser::parse( "31/02/2026,1" )->get_error_code() );
		self::assertSame( 'immens_forecast_csv_invalid_value', CsvParser::parse( "2026-01-01,abc" )->get_error_code() );
		self::assertSame( 'immens_forecast_unsupported_params', CsvParser::parse( "1", 'bogus' )->get_error_code() );
	}

	public function test_too_many_points(): void {
		$raw = implode( "\n", array_fill( 0, CsvParser::MAX_POINTS + 1, '1' ) );
		self::assertSame( 'immens_forecast_too_many_points', CsvParser::parse( $raw )->get_error_code() );
	}

	public function test_parse_date_helper(): void {
		self::assertSame( '2026-03-05', CsvParser::parse_date( '2026-3-5' ) );
		self::assertSame( '2026-03-05', CsvParser::parse_date( '05/03/2026' ) );
		self::assertNull( CsvParser::parse_date( 'not-a-date' ) );
		self::assertNull( CsvParser::parse_date( '2026-02-30' ) );
	}
}
