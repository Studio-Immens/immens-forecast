<?php
/**
 * Series normalization and minimum-history validation.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical series helpers.
 */
final class Series {

	const MIN_POINTS = 16;
	const MIN_FINITE = 4;

	/**
	 * Canonicalize a series array (float values, null for missing).
	 *
	 * @param array $series Raw series.
	 * @return array
	 */
	public static function normalize( array $series ): array {
		$values = self::raw_values( $series );
		$clean  = array();
		foreach ( $values as $value ) {
			$clean[] = is_numeric( $value ) ? (float) $value : null;
		}

		$covariates = array(
			'past_covariates',
			'known_covariates',
			'categorical_past_covariates',
			'categorical_known_covariates',
		);

		$normalized = array(
			'id'     => isset( $series['id'] ) ? (string) $series['id'] : 'series',
			'values' => $clean,
		);
		foreach ( $covariates as $key ) {
			$normalized[ $key ] = ( isset( $series[ $key ] ) && is_array( $series[ $key ] ) ) ? $series[ $key ] : null;
		}
		return $normalized;
	}

	/**
	 * Raw values array of a series (empty when missing).
	 *
	 * @param array $series Series.
	 * @return array
	 */
	public static function values( array $series ): array {
		return self::raw_values( $series );
	}

	/**
	 * Count of finite values.
	 *
	 * @param array $series Series.
	 * @return int
	 */
	public static function finite_count( array $series ): int {
		return count( Math::finite( self::raw_values( $series ) ) );
	}

	/**
	 * Whether the series has no negative finite values.
	 *
	 * @param array $series Series.
	 * @return bool
	 */
	public static function is_non_negative( array $series ): bool {
		foreach ( Math::finite( self::raw_values( $series ) ) as $value ) {
			if ( $value < 0 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Hash of the values, for cache-key invalidation.
	 *
	 * @param array $series Series.
	 * @return string
	 */
	public static function hash( array $series ): string {
		return md5( (string) wp_json_encode( self::raw_values( $series ) ) );
	}

	/**
	 * Validation error when the series has too little history, null otherwise.
	 *
	 * @param array $series Series.
	 * @return \WP_Error|null
	 */
	public static function insufficient_error( array $series ): ?\WP_Error {
		$points = count( self::raw_values( $series ) );
		$finite = self::finite_count( $series );
		if ( $points >= self::MIN_POINTS && $finite >= self::MIN_FINITE ) {
			return null;
		}
		$message = sprintf(
			/* translators: 1: minimum points, 2: points present, 3: minimum values, 4: values present. */
			__( 'Not enough history: at least %1$d data points (%2$d present) and %3$d valid values (%4$d present) are required.', 'immens-forecast' ),
			self::MIN_POINTS,
			$points,
			self::MIN_FINITE,
			$finite
		);
		return new \WP_Error(
			'immens_forecast_insufficient_data',
			$message,
			array(
				'points' => $points,
				'finite' => $finite,
			)
		);
	}

	/**
	 * Read the raw values array from a series (no casting).
	 *
	 * @param array $series Series.
	 * @return array
	 */
	private static function raw_values( array $series ): array {
		return ( isset( $series['values'] ) && is_array( $series['values'] ) ) ? $series['values'] : array();
	}
}
