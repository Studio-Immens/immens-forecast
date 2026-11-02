<?php
/**
 * Remote endpoint provider (bring your own forecast API).
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast\Providers;

use ImmensForecast\Admin\Settings;
use ImmensForecast\Forecast\Math;

defined( 'ABSPATH' ) || exit;

/**
 * Calls a remote endpoint implementing the documented contract (§4.4).
 */
final class EndpointProvider implements ProviderInterface {

	const MAX_HORIZON   = 1024;
	const MAX_SERIES    = 20;
	const MAX_CONTEXT   = 32768;
	const DEFAULT_MODEL = '209m';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'endpoint';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Remote endpoint', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Your own forecasting API, compatible with the documented contract.', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capabilities(): array {
		$levels = array();
		for ( $i = 1; $i <= 99; $i++ ) {
			$levels[] = round( $i / 100, 2 );
		}

		return array(
			'max_horizon'               => self::MAX_HORIZON,
			'max_series'                => self::MAX_SERIES,
			'max_context'               => self::MAX_CONTEXT,
			'supports_multi_series'     => true,
			'supports_known_covariates' => true,
			'supports_past_covariates'  => true,
			'supports_categorical'      => false,
			'quantile_levels'           => $levels,
			'requires_remote'           => true,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return Settings::is_endpoint_configured();
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
		$base = $this->base_url();
		if ( '' === $base ) {
			return new \WP_Error( 'immens_forecast_provider_unavailable', __( 'No endpoint URL is configured.', 'immens-forecast' ) );
		}

		$model  = ( isset( $options['model'] ) && is_string( $options['model'] ) && '' !== $options['model'] )
			? $options['model']
			: (string) Settings::get( 'endpoint_model', self::DEFAULT_MODEL );
		$levels = $this->normalize_levels( isset( $options['quantiles'] ) ? $options['quantiles'] : null );

		$response = wp_remote_post(
			$base . '/v1/forecast',
			array(
				'timeout'   => $this->timeout(),
				'sslverify' => $this->sslverify(),
				'headers'   => $this->headers(),
				'body'      => wp_json_encode(
					array(
						'series'    => array_values( $series ),
						'horizon'   => $horizon,
						'model'     => $model,
						'quantiles' => $levels,
					)
				),
			)
		);

		return $this->parse_response( $response, $horizon, $levels );
	}

	/**
	 * {@inheritDoc}
	 */
	public function health() {
		$base = $this->base_url();
		if ( '' === $base ) {
			return new \WP_Error( 'immens_forecast_provider_unavailable', __( 'No endpoint URL is configured.', 'immens-forecast' ) );
		}

		$response = wp_remote_get(
			$base . '/health',
			array(
				'timeout'   => $this->timeout(),
				'sslverify' => $this->sslverify(),
			)
		);

		$error = $this->transport_error( $response );
		if ( null !== $error ) {
			return $error;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return $this->http_error( $status );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return array(
			'status' => 'ok',
			'http'   => $status,
			'body'   => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Trimmed base URL without a trailing slash.
	 *
	 * @return string
	 */
	private function base_url(): string {
		return rtrim( trim( (string) Settings::get( 'endpoint_url', '' ) ), '/' );
	}

	/**
	 * Clamped request timeout.
	 *
	 * @return int
	 */
	private function timeout(): int {
		$timeout = (int) Settings::get( 'endpoint_timeout', 60 );
		return max( Settings::TIMEOUT_MIN, min( Settings::TIMEOUT_MAX, $timeout ) );
	}

	/**
	 * Whether TLS verification is enabled (filterable for development).
	 *
	 * @return bool
	 */
	private function sslverify(): bool {
		return (bool) apply_filters( 'immens_forecast_sslverify', true );
	}

	/**
	 * Request headers, including the bearer token when configured.
	 *
	 * @return array
	 */
	private function headers(): array {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		$token = Settings::endpoint_token();
		if ( null !== $token && '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$extra = apply_filters( 'immens_forecast_endpoint_headers', array() );
		if ( is_array( $extra ) ) {
			$headers = array_merge( $headers, $extra );
		}

		return $headers;
	}

	/**
	 * Keep valid quantile levels, sorted and unique.
	 *
	 * @param mixed $levels Requested levels.
	 * @return float[]
	 */
	private function normalize_levels( $levels ): array {
		if ( ! is_array( $levels ) ) {
			$levels = Settings::get( 'quantiles', array( 0.1, 0.5, 0.9 ) );
		}

		$clean = array();
		foreach ( (array) $levels as $level ) {
			if ( ! is_numeric( $level ) ) {
				continue;
			}
			$level = (float) $level;
			if ( $level > 0.0 && $level < 1.0 ) {
				$clean[] = $level;
			}
		}
		if ( empty( $clean ) ) {
			$clean = array( 0.1, 0.5, 0.9 );
		}

		$clean = array_values( array_unique( $clean ) );
		sort( $clean );
		return $clean;
	}

	/**
	 * Map a transport failure to a WP_Error.
	 *
	 * @param mixed $response HTTP response or WP_Error.
	 * @return \WP_Error|null
	 */
	private function transport_error( $response ) {
		if ( ! is_wp_error( $response ) ) {
			return null;
		}

		$message = $response->get_error_message();
		$timeout = ( false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' ) );

		return new \WP_Error(
			$timeout ? 'immens_forecast_endpoint_timeout' : 'immens_forecast_endpoint_unreachable',
			$message
		);
	}

	/**
	 * Map a non-2xx status to a WP_Error.
	 *
	 * @param int $status HTTP status.
	 * @return \WP_Error
	 */
	private function http_error( int $status ) {
		if ( 401 === $status || 403 === $status ) {
			return new \WP_Error(
				'immens_forecast_endpoint_unauthorized',
				__( 'The endpoint rejected the credentials.', 'immens-forecast' ),
				array( 'status' => $status )
			);
		}

		return new \WP_Error(
			'immens_forecast_endpoint_http',
			/* translators: %d: HTTP status code. */
			sprintf( __( 'The endpoint returned HTTP %d.', 'immens-forecast' ), $status ),
			array( 'status' => $status )
		);
	}

	/**
	 * Parse and normalize a forecast response.
	 *
	 * @param mixed   $response HTTP response or WP_Error.
	 * @param int     $horizon  Requested horizon.
	 * @param float[] $levels   Requested levels.
	 * @return array|\WP_Error
	 */
	private function parse_response( $response, int $horizon, array $levels ) {
		$error = $this->transport_error( $response );
		if ( null !== $error ) {
			return $error;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return $this->http_error( $status );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['series'] ) || ! is_array( $data['series'] ) ) {
			return new \WP_Error( 'immens_forecast_endpoint_bad_response', __( 'The endpoint did not return a valid forecast payload.', 'immens-forecast' ) );
		}

		$model = self::DEFAULT_MODEL;
		if ( isset( $data['model'] ) ) {
			if ( is_array( $data['model'] ) && isset( $data['model']['alias'] ) ) {
				$model = (string) $data['model']['alias'];
			} elseif ( is_string( $data['model'] ) ) {
				$model = $data['model'];
			}
		}

		$quantile_levels = ( isset( $data['quantile_levels'] ) && is_array( $data['quantile_levels'] ) )
			? array_values( $data['quantile_levels'] )
			: $levels;

		return array(
			'model'           => $model,
			'horizon'         => isset( $data['horizon'] ) ? (int) $data['horizon'] : $horizon,
			'quantile_levels' => $quantile_levels,
			'series'          => $this->normalize_series( $data['series'] ),
		);
	}

	/**
	 * Normalize the returned series list.
	 *
	 * @param array $items Raw series list.
	 * @return array
	 */
	private function normalize_series( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			$item      = (array) $item;
			$quantiles = array();

			if ( isset( $item['quantiles'] ) && is_array( $item['quantiles'] ) ) {
				foreach ( $item['quantiles'] as $level => $values ) {
					$key               = is_string( $level ) ? $level : Math::format_level( (float) $level );
					$quantiles[ $key ] = array_values( (array) $values );
				}
			}

			if ( isset( $item['median'] ) && is_array( $item['median'] ) ) {
				$median = array_values( $item['median'] );
			} elseif ( isset( $quantiles['0.5'] ) ) {
				$median = $quantiles['0.5'];
			} else {
				$median = array();
			}

			$out[] = array(
				'id'           => isset( $item['id'] ) ? (string) $item['id'] : 'series',
				'context_used' => isset( $item['context_used'] ) ? (int) $item['context_used'] : 0,
				'median'       => $median,
				'quantiles'    => $quantiles,
			);
		}
		return $out;
	}
}
