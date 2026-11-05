<?php
/**
 * Builds the normalized source payload shared by all sources.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Dates, values and `dow` covariates for history + horizon.
 */
final class SeriesBuilder {

	/**
	 * Build the payload.
	 *
	 * @param array  $points      Point list ( d, v ).
	 * @param string $granularity day|week.
	 * @param int    $horizon     Future steps.
	 * @param string $id          Series id.
	 * @return array
	 */
	public static function build( array $points, string $granularity, int $horizon, string $id = 'series' ): array {
		$granularity = ( 'week' === $granularity ) ? 'week' : 'day';
		$horizon     = max( 0, $horizon );

		$dates  = array();
		$values = array();
		foreach ( $points as $point ) {
			$dates[]  = (string) $point['d'];
			$values[] = ( isset( $point['v'] ) && is_numeric( $point['v'] ) ) ? (float) $point['v'] : null;
		}

		$future = empty( $dates ) ? array() : self::future_dates( (string) end( $dates ), $granularity, $horizon );

		$dow = array();
		foreach ( array_merge( $dates, $future ) as $date ) {
			$timestamp = strtotime( $date );
			$dow[]     = ( false === $timestamp ) ? 0 : (int) gmdate( 'w', $timestamp );
		}

		return array(
			'id'               => $id,
			'metric'           => $id,
			'granularity'      => $granularity,
			'dates'            => $dates,
			'values'           => $values,
			'future_dates'     => $future,
			'past_covariates'  => array( 'dow' => array_slice( $dow, 0, count( $dates ) ) ),
			'known_covariates' => array( 'dow' => $dow ),
		);
	}

	/**
	 * Future dates after a last known date.
	 *
	 * @param string $last_date   Last history date.
	 * @param string $granularity day|week.
	 * @param int    $horizon     Steps.
	 * @return string[]
	 */
	public static function future_dates( string $last_date, string $granularity, int $horizon ): array {
		$step   = ( 'week' === $granularity ) ? '+7 days' : '+1 day';
		$cursor = $last_date;
		$out    = array();

		for ( $i = 0; $i < $horizon; $i++ ) {
			$timestamp = strtotime( $cursor . ' ' . $step );
			if ( false === $timestamp ) {
				break;
			}
			$cursor = gmdate( 'Y-m-d', $timestamp );
			$out[]  = $cursor;
		}
		return $out;
	}
}
