<?php
/**
 * Deterministic local baseline provider.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast\Providers;

use ImmensForecast\Forecast\Math;

defined( 'ABSPATH' ) || exit;

/**
 * Seasonal baseline: level · seasonal factor plus residual quantiles.
 */
final class BaselineProvider implements ProviderInterface {

	const MIN_POINTS              = 16;
	const MIN_FINITE              = 4;
	const LEVEL_WINDOW            = 28;
	const RESIDUAL_WINDOW         = 90;
	const MIN_EMPIRICAL_RESIDUALS = 10;
	const MAX_HORIZON             = 180;
	const MAX_SERIES              = 20;
	const MAX_CONTEXT             = 5000;
	const MIN_FACTOR              = 0.2;
	const MAX_FACTOR              = 5.0;
	const DEFAULT_QUANTILES       = array( 0.1, 0.5, 0.9 );

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'baseline';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Local baseline', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Deterministic seasonal baseline, runs offline in PHP.', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capabilities(): array {
		$levels = array();
		for ( $i = 1; $i <= 99; $i++ ) {
			$levels[] = $i / 100;
		}

		return array(
			'max_horizon'               => self::MAX_HORIZON,
			'max_series'                => self::MAX_SERIES,
			'max_context'               => self::MAX_CONTEXT,
			'supports_multi_series'     => true,
			'supports_known_covariates' => true,
			'supports_past_covariates'  => false,
			'supports_categorical'      => false,
			'quantile_levels'           => $levels,
			'requires_remote'           => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $series  Normalized series list.
	 * @param int   $horizon Steps to forecast.
	 * @param array $options Options.
	 * @return array|\WP_Error
	 */
	public function forecast( array $series, int $horizon, array $options ) {
		if ( $horizon < 1 || $horizon > self::MAX_HORIZON ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'The requested horizon is not supported.', 'immens-forecast' ) );
		}

		$levels       = $this->normalize_levels( isset( $options['quantiles'] ) ? $options['quantiles'] : null );
		$granularity  = ( isset( $options['granularity'] ) && 'week' === $options['granularity'] ) ? 'week' : 'day';
		$non_negative = ! isset( $options['non_negative'] ) || (bool) $options['non_negative'];

		$out = array();
		foreach ( $series as $item ) {
			$result = $this->forecast_one( (array) $item, $horizon, $levels, $granularity, $non_negative );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$out[] = $result;
		}

		return array(
			'model'           => 'baseline',
			'horizon'         => $horizon,
			'quantile_levels' => $levels,
			'series'          => $out,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function health() {
		return array(
			'status'  => 'ok',
			'offline' => true,
		);
	}

	/**
	 * Keep valid quantile levels, defaulting to 0.1/0.5/0.9.
	 *
	 * @param mixed $levels Requested levels.
	 * @return float[]
	 */
	private function normalize_levels( $levels ): array {
		$clean = array();
		if ( is_array( $levels ) ) {
			foreach ( $levels as $level ) {
				if ( ! is_numeric( $level ) ) {
					continue;
				}
				$level = (float) $level;
				if ( $level > 0.0 && $level < 1.0 ) {
					$clean[] = $level;
				}
			}
		}
		if ( empty( $clean ) ) {
			$clean = self::DEFAULT_QUANTILES;
		}
		$clean = array_values( array_unique( $clean ) );
		sort( $clean );
		return $clean;
	}

	/**
	 * Forecast a single series.
	 *
	 * @param array  $item        Series.
	 * @param int    $horizon     Horizon.
	 * @param float[] $levels     Quantile levels.
	 * @param string $granularity day|week.
	 * @param bool   $non_negative Clamp at zero.
	 * @return array|\WP_Error
	 */
	private function forecast_one( array $item, int $horizon, array $levels, string $granularity, bool $non_negative ) {
		$id     = isset( $item['id'] ) ? (string) $item['id'] : 'series';
		$values = ( isset( $item['values'] ) && is_array( $item['values'] ) ) ? array_values( $item['values'] ) : array();
		$n      = count( $values );
		$finite = Math::finite( $values );

		if ( $n < self::MIN_POINTS || count( $finite ) < self::MIN_FINITE ) {
			return new \WP_Error(
				'immens_forecast_insufficient_data',
				sprintf(
					/* translators: 1: points, 2: finite values, 3: minimum points, 4: minimum finite values. */
					__( 'Not enough data: %1$d points with %2$d finite values (minimum %3$d points and %4$d finite values).', 'immens-forecast' ),
					$n,
					count( $finite ),
					self::MIN_POINTS,
					self::MIN_FINITE
				),
				array(
					'points' => $n,
					'finite' => count( $finite ),
				)
			);
		}

		$period = ( 'week' === $granularity ) ? 52 : 7;
		$key    = ( 'week' === $granularity ) ? 'woy' : 'dow';

		$past    = $this->in_sample_covariate( $item, $key, $n );
		$future  = $this->future_covariate( $item, $key, $n, $horizon );
		$level   = Math::median( array_slice( $finite, -self::LEVEL_WINDOW ) );
		$factors = $this->seasonal_factors( $values, $past, $period );
		$resid   = $this->residuals( $values, $past, $factors, $period );

		$empirical = count( $resid ) >= self::MIN_EMPIRICAL_RESIDUALS;
		$sigma     = 1.4826 * Math::mad( $resid );

		$median    = array();
		$quantiles = array();
		foreach ( $levels as $quantile_level ) {
			$quantiles[ Math::format_level( $quantile_level ) ] = array();
		}

		for ( $step = 0; $step < $horizon; $step++ ) {
			$factor = 1.0;
			if ( null !== $future && isset( $future[ $step ] ) && is_numeric( $future[ $step ] ) ) {
				$slot   = ( (int) $future[ $step ] ) % $period;
				$factor = isset( $factors[ $slot ] ) ? $factors[ $slot ] : 1.0;
			}
			$point    = $level * $factor;
			$median[] = $non_negative ? max( 0.0, $point ) : $point;
			foreach ( $levels as $quantile_level ) {
				$quantiles[ Math::format_level( $quantile_level ) ][] = $this->spread( $point, $quantile_level, $resid, $empirical, $sigma, $non_negative );
			}
		}

		return array(
			'id'           => $id,
			'context_used' => count( $finite ),
			'median'       => $median,
			'quantiles'    => $quantiles,
		);
	}

	/**
	 * Final forecast value for one step and level.
	 *
	 * @param float   $point        Point forecast.
	 * @param float   $level        Quantile level.
	 * @param float[] $residuals    In-sample residuals.
	 * @param bool    $empirical    Use empirical residuals.
	 * @param float   $sigma        Gaussian sigma.
	 * @param bool    $non_negative Clamp at zero.
	 * @return float
	 */
	private function spread( float $point, float $level, array $residuals, bool $empirical, float $sigma, bool $non_negative ): float {
		$spread = $empirical ? Math::quantile( $residuals, $level ) : $sigma * $this->normal_quantile( $level );
		$value  = $point + $spread;
		return $non_negative ? max( 0.0, $value ) : $value;
	}

	/**
	 * In-sample covariate slice (past first, known as fallback).
	 *
	 * @param array  $item Series.
	 * @param string $key  Covariate key.
	 * @param int    $n    Series length.
	 * @return array|null
	 */
	private function in_sample_covariate( array $item, string $key, int $n ): ?array {
		foreach ( array( 'past_covariates', 'known_covariates' ) as $group ) {
			if ( isset( $item[ $group ][ $key ] ) && is_array( $item[ $group ][ $key ] ) ) {
				$source = array_values( $item[ $group ][ $key ] );
				if ( count( $source ) >= $n ) {
					return array_slice( $source, 0, $n );
				}
			}
		}
		return null;
	}

	/**
	 * Future covariate slice from known_covariates (length n + horizon).
	 *
	 * @param array  $item    Series.
	 * @param string $key     Covariate key.
	 * @param int    $n       Series length.
	 * @param int    $horizon Horizon.
	 * @return array|null
	 */
	private function future_covariate( array $item, string $key, int $n, int $horizon ): ?array {
		if ( ! isset( $item['known_covariates'][ $key ] ) || ! is_array( $item['known_covariates'][ $key ] ) ) {
			return null;
		}
		$source = array_values( $item['known_covariates'][ $key ] );
		if ( count( $source ) < $n + $horizon ) {
			return null;
		}
		return array_slice( $source, $n, $horizon );
	}

	/**
	 * Seasonal factors s(k): median of value / M per slot, clamped.
	 *
	 * @param array      $values     Values.
	 * @param array|null $covariates Slot indices aligned with values.
	 * @param int        $period     Season length.
	 * @return float[]
	 */
	private function seasonal_factors( array $values, ?array $covariates, int $period ): array {
		$factors = array_fill( 0, $period, 1.0 );
		if ( null === $covariates || count( $covariates ) < 2 * $period ) {
			return $factors;
		}
		$m = Math::median( $values );
		if ( $m <= 0.0 ) {
			return $factors;
		}
		$buckets = array_fill( 0, $period, array() );
		foreach ( $values as $index => $value ) {
			if ( ! is_numeric( $value ) || ! isset( $covariates[ $index ] ) || ! is_numeric( $covariates[ $index ] ) ) {
				continue;
			}
			$slot               = ( (int) $covariates[ $index ] ) % $period;
			$buckets[ $slot ][] = (float) $value / $m;
		}
		foreach ( $buckets as $slot => $ratios ) {
			if ( ! empty( $ratios ) ) {
				$factors[ $slot ] = Math::clamp( Math::median( $ratios ), self::MIN_FACTOR, self::MAX_FACTOR );
			}
		}
		return $factors;
	}

	/**
	 * In-sample residuals over the last min(90, n) finite points.
	 *
	 * @param array      $values     Values.
	 * @param array|null $covariates Slot indices aligned with values.
	 * @param float[]    $factors    Seasonal factors.
	 * @param int        $period     Season length.
	 * @return float[]
	 */
	private function residuals( array $values, ?array $covariates, array $factors, int $period ): array {
		$n     = count( $values );
		$start = max( 0, $n - self::RESIDUAL_WINDOW );
		$out   = array();
		for ( $i = $start; $i < $n; $i++ ) {
			if ( ! is_numeric( $values[ $i ] ) ) {
				continue;
			}
			$from = max( 0, $i - self::LEVEL_WINDOW );
			$prev = Math::finite( array_slice( $values, $from, $i - $from ) );
			if ( count( $prev ) < 1 ) {
				continue;
			}
			$factor = 1.0;
			if ( null !== $covariates && isset( $covariates[ $i ] ) && is_numeric( $covariates[ $i ] ) ) {
				$slot   = ( (int) $covariates[ $i ] ) % $period;
				$factor = isset( $factors[ $slot ] ) ? $factors[ $slot ] : 1.0;
			}
			$out[] = (float) $values[ $i ] - ( Math::median( $prev ) * $factor );
		}
		return $out;
	}

	/**
	 * Standard normal quantile (Acklam's rational approximation).
	 *
	 * @param float $p Probability in (0,1).
	 * @return float
	 */
	private function normal_quantile( float $p ): float {
		$a = array( -3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00 );
		$b = array( -5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01 );
		$c = array( -7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00 );
		$d = array( 7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00 );

		$p = Math::clamp( $p, 1e-9, 1.0 - 1e-9 );
		if ( $p < 0.02425 ) {
			$q = sqrt( -2.0 * log( $p ) );
			return ( ( ( ( ( $c[0] * $q + $c[1] ) * $q + $c[2] ) * $q + $c[3] ) * $q + $c[4] ) * $q + $c[5] ) / ( ( ( ( $d[0] * $q + $d[1] ) * $q + $d[2] ) * $q + $d[3] ) * $q + 1.0 );
		}
		if ( $p > 0.97575 ) {
			$q = sqrt( -2.0 * log( 1.0 - $p ) );
			return -( ( ( ( ( $c[0] * $q + $c[1] ) * $q + $c[2] ) * $q + $c[3] ) * $q + $c[4] ) * $q + $c[5] ) / ( ( ( ( $d[0] * $q + $d[1] ) * $q + $d[2] ) * $q + $d[3] ) * $q + 1.0 );
		}
		$q = $p - 0.5;
		$r = $q * $q;
		return ( ( ( ( ( $a[0] * $r + $a[1] ) * $r + $a[2] ) * $r + $a[3] ) * $r + $a[4] ) * $r + $a[5] ) * $q / ( ( ( ( ( $b[0] * $r + $b[1] ) * $r + $b[2] ) * $r + $b[3] ) * $r + $b[4] ) * $r + 1.0 );
	}
}
