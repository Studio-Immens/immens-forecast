<?php
/**
 * CSV / pasted-values parser.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Parses "date,value" pairs or bare value lists into a point list.
 */
final class CsvParser {

	const MAX_POINTS = 5000;

	/**
	 * Tokens accepted as a missing value.
	 *
	 * @var string[]
	 */
	private static $null_tokens = array( 'nan', 'na', 'n/a', '-', 'null', 'none' );

	/**
	 * Parse raw text.
	 *
	 * @param string      $raw         Raw input.
	 * @param string      $granularity day|week (for values-only input).
	 * @param string|null $end_date    Last date for values-only input (defaults to today).
	 * @return array|\WP_Error
	 */
	public static function parse( string $raw, string $granularity = 'day', ?string $end_date = null ) {
		$granularity = strtolower( trim( $granularity ) );
		if ( ! in_array( $granularity, array( 'day', 'week' ), true ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Invalid granularity.', 'immens-forecast' ) );
		}

		$lines = array();
		foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $raw ) ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		if ( empty( $lines ) ) {
			return new \WP_Error( 'immens_forecast_csv_empty', __( 'No data found.', 'immens-forecast' ) );
		}

		$mode   = '';
		$points = array();

		foreach ( $lines as $index => $line ) {
			$tokens = self::tokenize( $line );
			if ( empty( $tokens ) ) {
				continue;
			}
			if ( 0 === $index && self::is_header_token( $tokens[0] ) ) {
				continue; // Header row (e.g. "date,value").
			}

			$row_mode = ( count( $tokens ) >= 2 ) ? 'pairs' : 'values';
			if ( '' === $mode ) {
				$mode = $row_mode;
			} elseif ( $mode !== $row_mode ) {
				/* translators: %d: row number. */
				return new \WP_Error( 'immens_forecast_csv_mixed_lines', sprintf( __( 'Row %d mixes dated and bare values.', 'immens-forecast' ), $index + 1 ) );
			}

			if ( 'pairs' === $row_mode ) {
				$date = self::parse_date( $tokens[0] );
				if ( null === $date ) {
					/* translators: %d: row number. */
					return new \WP_Error( 'immens_forecast_csv_invalid_date', sprintf( __( 'Invalid date on row %d.', 'immens-forecast' ), $index + 1 ) );
				}
				$value = self::to_value( $tokens[1] );
				if ( false === $value ) {
					/* translators: %d: row number. */
					return new \WP_Error( 'immens_forecast_csv_invalid_value', sprintf( __( 'Invalid value on row %d.', 'immens-forecast' ), $index + 1 ) );
				}
				$points[] = array(
					'd' => $date,
					'v' => $value,
				);
			} else {
				$value = self::to_value( $tokens[0] );
				if ( false === $value ) {
					/* translators: %d: row number. */
					return new \WP_Error( 'immens_forecast_csv_invalid_value', sprintf( __( 'Invalid value on row %d.', 'immens-forecast' ), $index + 1 ) );
				}
				$points[] = array(
					'd' => '',
					'v' => $value,
				);
			}
		}

		if ( count( $points ) > self::MAX_POINTS ) {
			return new \WP_Error(
				'immens_forecast_too_many_points',
				sprintf(
					/* translators: %d: maximum number of points. */
					__( 'Too many points: the maximum is %d.', 'immens-forecast' ),
					self::MAX_POINTS
				)
			);
		}

		if ( 'values' === $mode ) {
			$points = self::assign_dates( $points, $granularity, $end_date );
		}

		usort(
			$points,
			static function ( $first, $second ) {
				return strcmp( $first['d'], $second['d'] );
			}
		);
		return $points;
	}

	/**
	 * Split a line into tokens.
	 *
	 * A comma directly after a date-shaped prefix separates the date from its
	 * value (surrounding whitespace is ignored), so "2026-01-01, 10" and
	 * "2026-01-01 ,10" yield the date plus the value. Any other comma is kept,
	 * so decimal commas such as "12,5" survive for to_value(). Remaining parts
	 * are split on whitespace and semicolons.
	 *
	 * @param string $line Line.
	 * @return string[]
	 */
	private static function tokenize( string $line ): array {
		$tokens = array();
		$line   = trim( $line );

		if ( preg_match( '/^(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}\/\d{1,2}\/\d{4}|\d{8})\s*,\s*(.*)$/', $line, $match ) ) {
			$tokens[] = $match[1];
			$line     = $match[2];
		}

		foreach ( (array) preg_split( '/[\s;]+/', $line, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			$tokens[] = $part;
		}
		return $tokens;
	}

	/**
	 * Whether a first-line token is a column header rather than data.
	 *
	 * A header is not numeric, not a missing-value token and not date-shaped
	 * (a valid or calendar-invalid date still counts as data).
	 *
	 * @param string $token First token of the first line.
	 * @return bool
	 */
	private static function is_header_token( string $token ): bool {
		$token = strtolower( trim( $token ) );
		if ( is_numeric( str_replace( ',', '.', $token ) ) ) {
			return false;
		}
		if ( in_array( $token, self::$null_tokens, true ) ) {
			return false;
		}
		return ! self::looks_like_date( $token );
	}

	/**
	 * Whether a token has a supported date shape (ignores calendar validity).
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	private static function looks_like_date( string $token ): bool {
		return (bool) preg_match( '/^\d{4}-\d{1,2}-\d{1,2}$|^\d{1,2}\/\d{1,2}\/\d{4}$/', trim( $token ) );
	}

	/**
	 * Parse and normalize a date token to Y-m-d, or null.
	 *
	 * @param string $token Token.
	 * @return string|null
	 */
	public static function parse_date( string $token ): ?string {
		$token = trim( $token );
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $token, $match ) ) {
			$year = (int) $match[1];
			$mon  = (int) $match[2];
			$day  = (int) $match[3];
		} elseif ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $token, $match ) ) {
			$year = (int) $match[3];
			$mon  = (int) $match[2];
			$day  = (int) $match[1];
		} else {
			return null;
		}
		if ( ! checkdate( $mon, $day, $year ) ) {
			return null;
		}
		return sprintf( '%04d-%02d-%02d', $year, $mon, $day );
	}

	/**
	 * Convert a value token: float, null for missing tokens, false when invalid.
	 *
	 * @param string $token Token.
	 * @return float|null|false
	 */
	private static function to_value( string $token ) {
		if ( in_array( strtolower( trim( $token ) ), self::$null_tokens, true ) ) {
			return null;
		}
		$normalized = str_replace( ',', '.', trim( $token ) );
		return is_numeric( $normalized ) ? (float) $normalized : false;
	}

	/**
	 * Generate dates backwards from the end date for values-only input.
	 *
	 * @param array       $points      Points without dates.
	 * @param string      $granularity day|week.
	 * @param string|null $end_date    End date.
	 * @return array
	 */
	private static function assign_dates( array $points, string $granularity, ?string $end_date ): array {
		$end = ( null !== $end_date ) ? self::parse_date( $end_date ) : null;
		if ( null === $end ) {
			$end = (string) current_time( 'Y-m-d' );
		}

		$step   = ( 'week' === $granularity ) ? '-7 days' : '-1 day';
		$cursor = $end;
		$dates  = array();
		for ( $i = 0, $count = count( $points ); $i < $count; $i++ ) {
			array_unshift( $dates, $cursor );
			$cursor = gmdate( 'Y-m-d', (int) strtotime( $cursor . ' ' . $step ) );
		}
		foreach ( $points as $index => $point ) {
			$points[ $index ]['d'] = $dates[ $index ];
		}
		return $points;
	}
}
