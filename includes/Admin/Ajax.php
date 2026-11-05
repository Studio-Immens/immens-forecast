<?php
/**
 * Admin AJAX endpoints.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Admin;

use ImmensForecast\Forecast\Crypto;
use ImmensForecast\Forecast\Engine;
use ImmensForecast\Forecast\Providers\EndpointProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Nonce and capability guarded handlers for the forecast UI.
 */
final class Ajax {

	const NONCE         = 'immens_forecast';
	const MAX_QUANTILES = 9;

	/**
	 * Register the AJAX actions.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_immens_forecast_run', array( __CLASS__, 'run' ) );
		add_action( 'wp_ajax_immens_forecast_test_endpoint', array( __CLASS__, 'test_endpoint' ) );
		add_action( 'wp_ajax_immens_forecast_load_models', array( __CLASS__, 'load_models' ) );
	}

	/**
	 * Run a forecast request.
	 *
	 * @return void
	 */
	public static function run(): void {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$params = self::collect_params( wp_unslash( $_POST ) );
		self::respond( Engine::run( $params, get_current_user_id() ) );
	}

	/**
	 * Persist the endpoint settings and check their health.
	 *
	 * @return void
	 */
	public static function test_endpoint(): void {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$patch = self::collect_endpoint_settings( wp_unslash( $_POST ) );
		if ( is_wp_error( $patch ) ) {
			self::respond( $patch );
			return;
		}
		Settings::update( $patch );
		self::respond( ( new EndpointProvider() )->health() );
	}

	/**
	 * Persist the endpoint settings and list the remote models.
	 *
	 * @return void
	 */
	public static function load_models(): void {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$patch = self::collect_endpoint_settings( wp_unslash( $_POST ) );
		if ( is_wp_error( $patch ) ) {
			self::respond( $patch );
			return;
		}
		Settings::update( $patch );
		self::respond( ( new EndpointProvider() )->models() );
	}

	/**
	 * Collect and sanitize the forecast params.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function collect_params( array $input ): array {
		$quantiles = array();
		if ( isset( $input['quantiles'] ) && is_array( $input['quantiles'] ) ) {
			foreach ( array_slice( $input['quantiles'], 0, self::MAX_QUANTILES ) as $level ) {
				if ( ! is_numeric( $level ) ) {
					continue;
				}
				$level = (float) $level;
				if ( $level > 0.0 && $level < 1.0 ) {
					$quantiles[] = $level;
				}
			}
		}
		if ( empty( $quantiles ) ) {
			$quantiles = (array) Settings::get( 'quantiles', array( 0.1, 0.5, 0.9 ) );
		}

		$granularity = isset( $input['granularity'] ) ? strtolower( sanitize_text_field( (string) $input['granularity'] ) ) : 'day';
		if ( ! in_array( $granularity, array( 'day', 'week' ), true ) ) {
			$granularity = 'day';
		}

		$history_days = isset( $input['history_days'] ) ? (int) $input['history_days'] : Engine::DEFAULT_HISTORY;
		$horizon      = isset( $input['horizon'] ) ? (int) $input['horizon'] : (int) Settings::get( 'horizon', 30 );

		$params = array(
			'source'       => isset( $input['source'] ) ? sanitize_key( (string) $input['source'] ) : '',
			'metric'       => isset( $input['metric'] ) ? sanitize_key( (string) $input['metric'] ) : '',
			'granularity'  => $granularity,
			'history_days' => max( Engine::HISTORY_MIN, min( Engine::HISTORY_MAX, $history_days ) ),
			'horizon'      => max( Engine::HORIZON_MIN, min( Engine::HORIZON_MAX, $horizon ) ),
			'refresh'      => ! empty( $input['refresh'] ),
			'quantiles'    => $quantiles,
		);

		if ( ! empty( $input['provider'] ) ) {
			$params['provider'] = sanitize_key( (string) $input['provider'] );
		}
		if ( ! empty( $input['model'] ) ) {
			$params['model'] = sanitize_text_field( (string) $input['model'] );
		}
		if ( ! empty( $input['dataset_id'] ) ) {
			$params['dataset_id'] = absint( $input['dataset_id'] );
		}

		return $params;
	}

	/**
	 * Nonce and capability guard.
	 *
	 * @return void
	 */
	private static function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_send_json_error(
				array(
					'code'    => 'immens_forecast_forbidden',
					'message' => __( 'You are not allowed to run forecasts.', 'immens-forecast' ),
				),
				403
			);
		}
	}

	/**
	 * Send the JSON envelope for an engine/provider result.
	 *
	 * @param array|\WP_Error $result Result.
	 * @return void
	 */
	private static function respond( $result ): void {
		if ( ! is_wp_error( $result ) ) {
			wp_send_json_success( $result );
			return;
		}

		$code   = $result->get_error_code();
		$status = 400;
		if ( 'immens_forecast_locked' === $code ) {
			$status = 409;
		} elseif ( 'immens_forecast_rate_limited' === $code ) {
			$status = 429;
		}

		wp_send_json_error(
			array(
				'code'    => $code,
				'message' => $result->get_error_message(),
				'data'    => $result->get_error_data(),
			),
			$status
		);
	}

	/**
	 * Sanitize the endpoint settings patch.
	 *
	 * @param array $input Raw input.
	 * @return array|\WP_Error
	 */
	private static function collect_endpoint_settings( array $input ) {
		$patch = array();

		if ( isset( $input['endpoint_url'] ) ) {
			$url = esc_url_raw( (string) $input['endpoint_url'] );
			if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
				return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Only http and https endpoint URLs are allowed.', 'immens-forecast' ) );
			}
			$patch['endpoint_url'] = $url;
		}
		if ( isset( $input['endpoint_model'] ) ) {
			$patch['endpoint_model'] = sanitize_text_field( (string) $input['endpoint_model'] );
		}
		if ( isset( $input['endpoint_timeout'] ) ) {
			$timeout                   = (int) $input['endpoint_timeout'];
			$patch['endpoint_timeout'] = max( Settings::TIMEOUT_MIN, min( Settings::TIMEOUT_MAX, $timeout ) );
		}
		if ( ! empty( $input['endpoint_token'] ) ) {
			$crypto = new Crypto();
			if ( ! $crypto->has_method() ) {
				return new \WP_Error( 'immens_forecast_unsupported_params', __( 'The endpoint token cannot be encrypted on this server.', 'immens-forecast' ) );
			}
			$patch['endpoint_token_enc'] = $crypto->encrypt( sanitize_text_field( (string) $input['endpoint_token'] ) );
		}

		return $patch;
	}
}
