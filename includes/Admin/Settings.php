<?php
/**
 * Plugin settings accessor and sanitizer.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Admin;

use ImmensForecast\Forecast\Crypto;
use ImmensForecast\Forecast\Math;

defined( 'ABSPATH' ) || exit;

/**
 * Static accessor for the single `immens_forecast_settings` option.
 */
final class Settings {

	const OPTION      = 'immens_forecast_settings';
	const TIMEOUT_MIN = 5;
	const TIMEOUT_MAX = 300;

	/**
	 * In-request cache of the merged option.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'provider'                 => 'baseline',
			'endpoint_url'             => '',
			'endpoint_token_enc'       => '',
			'endpoint_model'           => '209m',
			'endpoint_timeout'         => 60,
			'quantiles'                => array( 0.1, 0.5, 0.9 ),
			'history_days'             => 365,
			'horizon'                  => 30,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			self::$cache = array_merge( self::defaults(), $stored );
		}
		return self::$cache;
	}

	/**
	 * Single setting value.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Persist a patch merged over current settings.
	 *
	 * @param array $patch Setting subset.
	 * @return array Merged settings.
	 */
	public static function update( array $patch ): array {
		$merged = array_merge( self::all(), $patch );
		update_option( self::OPTION, $merged );
		self::$cache = $merged;
		return $merged;
	}

	/**
	 * Clear the in-request cache (tests, after external option writes).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * Capability required to use the plugin.
	 *
	 * @return string
	 */
	public static function capability(): string {
		$capability = apply_filters( 'immens_forecast_capability', 'manage_options' );
		return ( is_string( $capability ) && '' !== $capability ) ? $capability : 'manage_options';
	}

	/**
	 * Whether an endpoint URL is configured.
	 *
	 * @return bool
	 */
	public static function is_endpoint_configured(): bool {
		return '' !== (string) self::get( 'endpoint_url', '' );
	}

	/**
	 * Decrypted endpoint token, or null.
	 *
	 * @return string|null
	 */
	public static function endpoint_token(): ?string {
		$encrypted = (string) self::get( 'endpoint_token_enc', '' );
		if ( '' === $encrypted ) {
			return null;
		}
		$crypto = new Crypto();
		return $crypto->decrypt( $encrypted );
	}

	/**
	 * Sanitize the settings form payload.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ): array {
		$clean = self::all();

		if ( isset( $input['provider'] ) ) {
			$clean['provider'] = sanitize_text_field( (string) $input['provider'] );
		}
		if ( isset( $input['endpoint_url'] ) ) {
			$clean['endpoint_url'] = self::sanitize_url( (string) $input['endpoint_url'] );
		}
		if ( isset( $input['endpoint_model'] ) ) {
			$model                   = sanitize_text_field( (string) $input['endpoint_model'] );
			$clean['endpoint_model'] = ( '' === $model ) ? '209m' : $model;
		}
		if ( isset( $input['endpoint_timeout'] ) ) {
			$clean['endpoint_timeout'] = self::sanitize_timeout( $input['endpoint_timeout'] );
		}

		$token = isset( $input['endpoint_token'] ) ? trim( (string) $input['endpoint_token'] ) : '';
		if ( '' !== $token ) {
			$crypto = new Crypto();
			if ( $crypto->has_method() ) {
				$clean['endpoint_token_enc'] = $crypto->encrypt( $token );
			}
		} elseif ( ! empty( $input['endpoint_clear_token'] ) ) {
			$clean['endpoint_token_enc'] = '';
		}

		if ( isset( $input['quantiles'] ) ) {
			$clean['quantiles'] = self::sanitize_quantiles( $input['quantiles'] );
		}
		if ( isset( $input['history_days'] ) ) {
			$clean['history_days'] = min( 1095, max( 30, absint( $input['history_days'] ) ) );
		}
		if ( isset( $input['horizon'] ) ) {
			$clean['horizon'] = min( 180, max( 1, absint( $input['horizon'] ) ) );
		}
		if ( isset( $input['delete_data_on_uninstall'] ) ) {
			$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
		}

		return $clean;
	}

	/**
	 * Normalize a quantile list (string or array) to a sorted, unique float list.
	 *
	 * @param mixed $raw Raw value.
	 * @return float[]
	 */
	public static function sanitize_quantiles( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', trim( $raw ) );
		}
		if ( ! is_array( $raw ) ) {
			return self::defaults()['quantiles'];
		}

		$levels = array();
		foreach ( $raw as $value ) {
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$level = round( (float) $value, 4 );
			if ( $level < 0.01 || $level > 0.99 ) {
				continue;
			}
			$levels[ Math::format_level( $level ) ] = $level;
		}

		if ( count( $levels ) < 3 ) {
			return self::defaults()['quantiles'];
		}

		$levels = array_values( $levels );
		sort( $levels );
		return $levels;
	}

	/**
	 * Clamp and cast a timeout value.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function sanitize_timeout( $value ): int {
		$seconds = absint( $value );
		return max( self::TIMEOUT_MIN, min( self::TIMEOUT_MAX, $seconds ) );
	}

	/**
	 * Allow only http/https URLs; strip a trailing slash.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function sanitize_url( string $url ): string {
		$url    = esc_url_raw( trim( $url ) );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		return rtrim( $url, '/' );
	}
}
