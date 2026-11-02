<?php
/**
 * Pure numeric helpers (no WordPress dependency).
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast;

defined( 'ABSPATH' ) || exit;

/**
 * Statistical helpers shared by providers and the engine.
 */
final class Math {

	/**
	 * Keep only finite numeric values, cast to float.
	 *
	 * @param array $values Raw values.
	 * @return float[]
	 */
	public static function finite( array $values ): array {
		$out = array();
		foreach ( $values as $value ) {
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$float = (float) $value;
			if ( is_finite( $float ) ) {
				$out[] = $float;
			}
		}
		return $out;
	}

	/**
	 * Median of the finite values (0.0 when empty).
	 *
	 * @param array $values Values.
	 * @return float
	 */
	public static function median( array $values ): float {
		$values = self::finite( $values );
		$count  = count( $values );
		if ( 0 === $count ) {
			return 0.0;
		}
		sort( $values );
		$mid = intdiv( $count, 2 );
		if ( 0 === $count % 2 ) {
			return ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
		}
		return $values[ $mid ];
	}

	/**
	 * Arithmetic mean of the finite values.
	 *
	 * @param array $values Values.
	 * @return float
	 */
	public static function mean( array $values ): float {
		$values = self::finite( $values );
		$count  = count( $values );
		return ( 0 === $count ) ? 0.0 : array_sum( $values ) / $count;
	}

	/**
	 * Median absolute deviation.
	 *
	 * @param array      $values Values.
	 * @param float|null $median Pre-computed median (optional).
	 * @return float
	 */
	public static function mad( array $values, ?float $median = null ): float {
		$values = self::finite( $values );
		if ( empty( $values ) ) {
			return 0.0;
		}
		$median = ( null === $median ) ? self::median( $values ) : $median;
		$devs   = array();
		foreach ( $values as $value ) {
			$devs[] = abs( $value - $median );
		}
		return self::median( $devs );
	}

	/**
	 * Linear-interpolation quantile of an unsorted array.
	 *
	 * @param array $values Values.
	 * @param float $level  Level in [0,1].
	 * @return float
	 */
	public static function quantile( array $values, float $level ): float {
		$values = self::finite( $values );
		$count  = count( $values );
		if ( 0 === $count ) {
			return 0.0;
		}
		sort( $values );
		if ( 1 === $count ) {
			return $values[0];
		}
		$level = max( 0.0, min( 1.0, $level ) );
		$pos   = $level * ( $count - 1 );
		$low   = (int) floor( $pos );
		$high  = (int) ceil( $pos );
		if ( $low === $high ) {
			return $values[ $low ];
		}
		$fraction = $pos - $low;
		return $values[ $low ] + ( $values[ $high ] - $values[ $low ] ) * $fraction;
	}

	/**
	 * Clamp a value to an inclusive range.
	 *
	 * @param float $value Value.
	 * @param float $min   Minimum.
	 * @param float $max   Maximum.
	 * @return float
	 */
	public static function clamp( float $value, float $min, float $max ): float {
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Canonical quantile string key used in result arrays.
	 *
	 * @param float $level Level in [0,1].
	 * @return string
	 */
	public static function format_level( float $level ): string {
		$formatted = number_format( round( $level, 4 ), 4, '.', '' );
		$formatted = rtrim( rtrim( $formatted, '0' ), '.' );
		return ( '' === $formatted ) ? '0' : $formatted;
	}

	/**
	 * Round an array of values (non-numeric entries become null).
	 *
	 * @param array $values    Values.
	 * @param int   $precision Decimals.
	 * @return array
	 */
	public static function round( array $values, int $precision = 2 ): array {
		$out = array();
		foreach ( $values as $value ) {
			$out[] = is_numeric( $value ) ? round( (float) $value, $precision ) : null;
		}
		return $out;
	}
}
