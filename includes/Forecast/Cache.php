<?php
/**
 * Transient cache and per-user locks.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the transients API.
 */
final class Cache {

	const PREFIX      = 'immens_forecast_';
	const DEFAULT_TTL = 43200;
	const MIN_TTL     = 60;
	const LOCK_TTL    = 60;

	/**
	 * Deterministic cache key for a list of parts.
	 *
	 * @param array $parts Key parts.
	 * @return string
	 */
	public static function key( array $parts ): string {
		$normalized = array();
		foreach ( $parts as $part ) {
			if ( is_array( $part ) ) {
				$part = implode( ',', array_map( 'strval', $part ) );
			}
			$normalized[] = (string) $part;
		}
		return self::PREFIX . md5( implode( '|', $normalized ) );
	}

	/**
	 * Plugin version used in cache keys.
	 *
	 * @return string
	 */
	public static function version(): string {
		return defined( 'IMMENS_FORECAST_VERSION' ) ? (string) IMMENS_FORECAST_VERSION : 'dev';
	}

	/**
	 * Cache lifetime in seconds.
	 *
	 * @return int
	 */
	public static function ttl(): int {
		$ttl = (int) apply_filters( 'immens_forecast_cache_ttl', self::DEFAULT_TTL );
		return max( self::MIN_TTL, $ttl );
	}

	/**
	 * Cached value or null.
	 *
	 * @param string $key Cache key.
	 * @return array|null
	 */
	public static function get( string $key ): ?array {
		$value = get_transient( $key );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Store a value with the configured TTL.
	 *
	 * @param string $key   Cache key.
	 * @param array  $value Payload.
	 * @return void
	 */
	public static function set( string $key, array $value ): void {
		set_transient( $key, $value, self::ttl() );
	}

	/**
	 * Drop a cached value.
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	public static function forget( string $key ): void {
		delete_transient( $key );
	}

	/**
	 * Transient key of the per-user lock.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function lock_key( int $user_id ): string {
		return self::PREFIX . 'lock_' . $user_id;
	}

	/**
	 * Acquire the per-user lock.
	 *
	 * The lock is an option (autoload disabled) so that `add_option()`, which
	 * returns false when the row already exists, provides an atomic
	 * compare-and-set: two concurrent requests can never both win. Transients
	 * cannot do this because `set_transient()` always overwrites.
	 *
	 * @param int $user_id User id.
	 * @param int $ttl     Lock lifetime.
	 * @return bool True when the caller now owns the lock.
	 */
	public static function acquire_lock( int $user_id, int $ttl = self::LOCK_TTL ): bool {
		$key     = self::lock_key( $user_id );
		$expires = time() + max( 1, $ttl );

		$current = get_option( $key, false );
		if ( is_numeric( $current ) ) {
			if ( (int) $current > time() ) {
				return false;
			}
			delete_option( $key );
		}

		return (bool) add_option( $key, $expires, '', 'no' );
	}

	/**
	 * Release the per-user lock.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function release_lock( int $user_id ): void {
		delete_option( self::lock_key( $user_id ) );
	}
}
