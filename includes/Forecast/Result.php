<?php
/**
 * Result payload assembler.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast;

defined( 'ABSPATH' ) || exit;

/**
 * Enriches provider output with history, dates and KPIs.
 */
final class Result {

	/**
	 * Assemble the UI-ready payload.
	 *
	 * @param array $payload Provider payload ( model, series ).
	 * @param array $series  Series payload from SeriesBuilder.
	 * @param array $meta    Engine metadata.
	 * @return array
	 */
	public static function assemble( array $payload, array $series, array $meta ): array {
		$horizon = isset( $meta['horizon'] ) ? (int) $meta['horizon'] : 0;

		$history_dates  = isset( $series['dates'] ) ? array_values( (array) $series['dates'] ) : array();
		$history_values = isset( $series['values'] ) ? array_values( (array) $series['values'] ) : array();
		$future_dates   = isset( $series['future_dates'] )
			? array_slice( array_values( (array) $series['future_dates'] ), 0, max( 0, $horizon ) )
			: array();

		$first     = ( isset( $payload['series'][0] ) && is_array( $payload['series'][0] ) ) ? $payload['series'][0] : array();
		$median    = isset( $first['median'] ) ? array_slice( array_values( (array) $first['median'] ), 0, max( 0, $horizon ) ) : array();
		$quantiles = array();
		if ( isset( $first['quantiles'] ) && is_array( $first['quantiles'] ) ) {
			foreach ( $first['quantiles'] as $level => $values ) {
				$quantiles[ (string) $level ] = array_slice( array_values( (array) $values ), 0, max( 0, $horizon ) );
			}
		}

		return array(
			'provider'        => isset( $meta['provider'] ) ? (string) $meta['provider'] : '',
			'model'           => isset( $meta['model'] ) ? (string) $meta['model'] : '',
			'horizon'         => $horizon,
			'quantile_levels' => isset( $meta['quantile_levels'] ) ? (array) $meta['quantile_levels'] : array(),
			'elapsed_ms'      => isset( $meta['elapsed_ms'] ) ? (int) $meta['elapsed_ms'] : 0,
			'cached'          => ! empty( $meta['cached'] ),
			'fallback'        => ! empty( $meta['fallback'] ),
			'fallback_reason' => isset( $meta['fallback_reason'] ) ? $meta['fallback_reason'] : null,
			'fallback_code'   => isset( $meta['fallback_code'] ) ? $meta['fallback_code'] : null,
			'history'         => array(
				'dates'  => $history_dates,
				'values' => $history_values,
			),
			'dates'           => $future_dates,
			'median'          => $median,
			'quantiles'       => $quantiles,
			'kpis'            => self::kpis( $median, $quantiles, $history_values, $horizon ),
		);
	}

	/**
	 * Compute the dashboard KPIs.
	 *
	 * @param array $median         Median forecast.
	 * @param array $quantiles      Quantiles keyed by level.
	 * @param array $history_values Historical values.
	 * @param int   $horizon        Horizon.
	 * @return array
	 */
	private static function kpis( array $median, array $quantiles, array $history_values, int $horizon ): array {
		$forecast_total = 0.0;
		foreach ( $median as $value ) {
			if ( is_numeric( $value ) ) {
				$forecast_total += (float) $value;
			}
		}

		$finite = array();
		foreach ( $history_values as $value ) {
			if ( is_numeric( $value ) ) {
				$finite[] = (float) $value;
			}
		}
		$previous       = ( $horizon > 0 ) ? array_slice( $finite, max( 0, count( $finite ) - $horizon ) ) : array();
		$previous_total = 0.0;
		foreach ( $previous as $value ) {
			$previous_total += $value;
		}

		$delta_pct = ( $previous_total > 0.0 )
			? round( ( $forecast_total - $previous_total ) / $previous_total * 100.0, 1 )
			: null;

		$levels = array();
		foreach ( array_keys( $quantiles ) as $level ) {
			if ( is_numeric( $level ) ) {
				$levels[] = (float) $level;
			}
		}

		$interval_width = 0.0;
		if ( ! empty( $levels ) ) {
			$low_key  = Math::format_level( min( $levels ) );
			$high_key = Math::format_level( max( $levels ) );
			$low      = isset( $quantiles[ $low_key ] ) ? (array) $quantiles[ $low_key ] : array();
			$high     = isset( $quantiles[ $high_key ] ) ? (array) $quantiles[ $high_key ] : array();
			$count    = min( count( $low ), count( $high ) );
			$sum      = 0.0;
			$used     = 0;
			for ( $i = 0; $i < $count; $i++ ) {
				if ( is_numeric( $low[ $i ] ) && is_numeric( $high[ $i ] ) ) {
					$sum += (float) $high[ $i ] - (float) $low[ $i ];
					++$used;
				}
			}
			$interval_width = ( $used > 0 ) ? round( $sum / $used, 2 ) : 0.0;
		}

		return array(
			'forecast_total' => round( $forecast_total, 2 ),
			'previous_total' => round( $previous_total, 2 ),
			'delta_pct'      => $delta_pct,
			'interval_width' => $interval_width,
		);
	}
}
