<?php
/**
 * Forecast orchestration.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast;

use ImmensForecast\Admin\Settings;
use ImmensForecast\Forecast\Providers\ProviderRegistry;
use ImmensForecast\Sources\SourceRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Validates params, resolves the source and provider, caches and falls back.
 */
final class Engine {

	const HORIZON_MIN     = 1;
	const HORIZON_MAX     = 180;
	const HISTORY_MIN     = 7;
	const HISTORY_MAX     = 3650;
	const DEFAULT_HISTORY = 365;

	/**
	 * Run a forecast request.
	 *
	 * @param array $params  Request params.
	 * @param int   $user_id Current user (0 skips the lock).
	 * @return array|\WP_Error
	 */
	public static function run( array $params, int $user_id = 0 ) {
		$params = self::normalize_params( $params );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$source = SourceRegistry::build()->get( $params['source'] );
		if ( null === $source || ! $source->is_available() ) {
			return new \WP_Error( 'immens_forecast_source_unavailable', __( 'The requested data source is not available.', 'immens-forecast' ) );
		}

		$metrics = $source->get_metrics();
		if ( ! isset( $metrics[ $params['metric'] ] ) || ! is_array( $metrics[ $params['metric'] ] ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Unknown metric for this source.', 'immens-forecast' ) );
		}
		$granularities = isset( $metrics[ $params['metric'] ]['granularities'] )
			? (array) $metrics[ $params['metric'] ]['granularities']
			: array( 'day' );
		if ( ! in_array( $params['granularity'], $granularities, true ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Unsupported granularity for this metric.', 'immens-forecast' ) );
		}

		$history_start = gmdate( 'Y-m-d', time() - ( $params['history_days'] * 86400 ) );
		$args          = array(
			'metric'        => $params['metric'],
			'granularity'   => $params['granularity'],
			'history_start' => $history_start,
			'horizon'       => $params['horizon'],
		);
		if ( ! empty( $params['dataset_id'] ) ) {
			$args['dataset_id'] = (int) $params['dataset_id'];
		}

		$series = $source->get_series( $args );
		if ( is_wp_error( $series ) ) {
			return $series;
		}
		if ( ! is_array( $series ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'The source returned no usable series.', 'immens-forecast' ) );
		}

		$cache_key = Cache::key(
			array(
				$params['source'],
				$params['metric'],
				$params['granularity'],
				$history_start,
				$params['horizon'],
				$params['provider'],
				$params['model'],
				$params['quantiles'],
				self::last_point_hash( $series ),
				Cache::version(),
			)
		);

		if ( empty( $params['refresh'] ) ) {
			$cached = Cache::get( $cache_key );
			if ( null !== $cached ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$locked = ( $user_id > 0 ) && ! Cache::acquire_lock( $user_id );
		if ( $locked ) {
			return new \WP_Error( 'immens_forecast_locked', __( 'A forecast is already running. Please try again in a few seconds.', 'immens-forecast' ) );
		}

		try {
			$result = self::forecast( $series, $params );
		} finally {
			if ( $user_id > 0 ) {
				Cache::release_lock( $user_id );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Cache::set( $cache_key, $result );
		return $result;
	}

	/**
	 * Sanitize and clamp request params.
	 *
	 * @param array $params Raw params.
	 * @return array|\WP_Error
	 */
	private static function normalize_params( array $params ) {
		$source      = isset( $params['source'] ) ? sanitize_key( (string) $params['source'] ) : '';
		$metric      = isset( $params['metric'] ) ? sanitize_key( (string) $params['metric'] ) : '';
		$granularity = isset( $params['granularity'] ) ? strtolower( (string) $params['granularity'] ) : 'day';
		if ( ! in_array( $granularity, array( 'day', 'week' ), true ) ) {
			$granularity = 'day';
		}

		if ( '' === $source || '' === $metric ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'A source and a metric are required.', 'immens-forecast' ) );
		}

		$history_days = isset( $params['history_days'] ) ? (int) $params['history_days'] : self::DEFAULT_HISTORY;
		$history_days = max( self::HISTORY_MIN, min( self::HISTORY_MAX, $history_days ) );

		$horizon = isset( $params['horizon'] ) ? (int) $params['horizon'] : (int) Settings::get( 'horizon', 30 );
		$horizon = max( self::HORIZON_MIN, min( self::HORIZON_MAX, $horizon ) );

		$provider  = isset( $params['provider'] ) ? sanitize_key( (string) $params['provider'] ) : (string) Settings::get( 'provider', 'baseline' );
		$model     = isset( $params['model'] ) ? (string) $params['model'] : (string) Settings::get( 'endpoint_model', '209m' );
		$quantiles = isset( $params['quantiles'] ) ? (array) $params['quantiles'] : (array) Settings::get( 'quantiles', array( 0.1, 0.5, 0.9 ) );

		return array(
			'source'       => $source,
			'metric'       => $metric,
			'granularity'  => $granularity,
			'history_days' => $history_days,
			'horizon'      => $horizon,
			'provider'     => $provider,
			'model'        => $model,
			'quantiles'    => $quantiles,
			'dataset_id'   => isset( $params['dataset_id'] ) ? absint( $params['dataset_id'] ) : 0,
			'refresh'      => ! empty( $params['refresh'] ),
		);
	}

	/**
	 * Call the provider, falling back to the baseline on any error.
	 *
	 * @param array $series Series payload.
	 * @param array $params Normalized params.
	 * @return array|\WP_Error
	 */
	private static function forecast( array $series, array $params ) {
		$started   = microtime( true );
		$providers = ProviderRegistry::build();

		$provider        = $providers->get( $params['provider'] );
		$fallback_code   = null;
		$fallback_reason = null;

		if ( null === $provider || ! $provider->is_available() ) {
			$fallback_code   = 'immens_forecast_provider_unavailable';
			$fallback_reason = __( 'The selected provider is not available; the local baseline was used.', 'immens-forecast' );
			$provider        = $providers->get( 'baseline' );
		}
		if ( null === $provider ) {
			return new \WP_Error( 'immens_forecast_provider_unavailable', __( 'No forecast provider is available.', 'immens-forecast' ) );
		}

		$capabilities = $provider->get_capabilities();
		$max_horizon  = isset( $capabilities['max_horizon'] ) ? (int) $capabilities['max_horizon'] : self::HORIZON_MAX;
		if ( $max_horizon > 0 && $params['horizon'] > $max_horizon ) {
			return new \WP_Error(
				'immens_forecast_unsupported_params',
				/* translators: %d: maximum number of forecast steps. */
				sprintf( __( 'The selected provider supports at most %d steps.', 'immens-forecast' ), $max_horizon )
			);
		}

		$input   = array(
			array(
				'id'                           => isset( $series['id'] ) ? (string) $series['id'] : 'series',
				'values'                       => isset( $series['values'] ) ? (array) $series['values'] : array(),
				'past_covariates'              => isset( $series['past_covariates'] ) ? $series['past_covariates'] : null,
				'known_covariates'             => isset( $series['known_covariates'] ) ? $series['known_covariates'] : null,
				'categorical_past_covariates'  => null,
				'categorical_known_covariates' => null,
			),
		);
		$options = array(
			'model'       => $params['model'],
			'quantiles'   => $params['quantiles'],
			'granularity' => $params['granularity'],
		);

		$payload = $provider->forecast( $input, $params['horizon'], $options );

		if ( is_wp_error( $payload ) ) {
			$error_data      = $payload->get_error_data();
			$error_data      = is_array( $error_data ) ? $error_data : array();
			$fallback_code   = $payload->get_error_code();
			$fallback_reason = $payload->get_error_message();

			if ( 'immens_forecast_endpoint_http' === $fallback_code && isset( $error_data['status'] ) && 429 === (int) $error_data['status'] ) {
				$fallback_code = 'immens_forecast_rate_limited';
			}
			$fallback_reason = self::fallback_message( $fallback_code, $fallback_reason, $error_data );

			if ( 'baseline' !== $provider->get_id() ) {
				$baseline = $providers->get( 'baseline' );
				if ( null !== $baseline ) {
					$payload  = $baseline->forecast( $input, $params['horizon'], $options );
					$provider = $baseline;
				}
			}
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}
		}

		return Result::assemble(
			$payload,
			$series,
			array(
				'provider'        => $provider->get_id(),
				'model'           => isset( $payload['model'] ) ? (string) $payload['model'] : $params['model'],
				'horizon'         => $params['horizon'],
				'quantile_levels' => isset( $payload['quantile_levels'] ) ? (array) $payload['quantile_levels'] : $params['quantiles'],
				'elapsed_ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'cached'          => false,
				'fallback'        => null !== $fallback_code,
				'fallback_reason' => $fallback_reason,
				'fallback_code'   => $fallback_code,
			)
		);
	}

	/**
	 * Human message for a fallback reason.
	 *
	 * @param string $code    Error code.
	 * @param string $message Original message.
	 * @param array  $data    Error data.
	 * @return string
	 */
	private static function fallback_message( string $code, string $message, array $data ): string {
		if ( 'immens_forecast_rate_limited' === $code && isset( $data['retry_after'] ) ) {
			return sprintf(
				/* translators: %d: seconds to wait before retrying. */
				__( 'Rate limit reached. Retry in %d seconds; the local baseline was used.', 'immens-forecast' ),
				(int) $data['retry_after']
			);
		}
		return $message;
	}

	/**
	 * Hash of the last data point (invalidates the cache on new data).
	 *
	 * @param array $series Series payload.
	 * @return string
	 */
	private static function last_point_hash( array $series ): string {
		$dates  = isset( $series['dates'] ) ? (array) $series['dates'] : array();
		$values = isset( $series['values'] ) ? (array) $series['values'] : array();
		$count  = count( $dates );
		$last   = 'empty';

		if ( $count > 0 ) {
			$value = isset( $values[ $count - 1 ] ) && is_numeric( $values[ $count - 1 ] )
				? (string) (float) $values[ $count - 1 ]
				: 'null';
			$last  = (string) $dates[ $count - 1 ] . ':' . $value;
		}

		return md5( $last );
	}
}
