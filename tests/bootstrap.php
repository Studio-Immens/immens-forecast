<?php
/**
 * PHPUnit bootstrap for unit tests (no WordPress runtime).
 *
 * @package ImmensForecast\Tests
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! class_exists( 'WP_Error' ) ) {
	require_once __DIR__ . '/stubs/class-wp-error.php';
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Minimal is_wp_error for unit tests.
	 *
	 * @param mixed $thing Value to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/fake-wp/' );
defined( 'IMMENS_FORECAST_VERSION' ) || define( 'IMMENS_FORECAST_VERSION', '0.1.0-test' );
defined( 'IMMENS_FORECAST_FILE' ) || define( 'IMMENS_FORECAST_FILE', dirname( __DIR__ ) . '/immens-forecast.php' );
defined( 'IMMENS_FORECAST_PATH' ) || define( 'IMMENS_FORECAST_PATH', dirname( __DIR__ ) . '/' );
defined( 'IMMENS_FORECAST_URL' ) || define( 'IMMENS_FORECAST_URL', 'https://example.test/wp-content/plugins/immens-forecast/' );
defined( 'AUTH_KEY' ) || define( 'AUTH_KEY', 'test-auth-key' );
defined( 'AUTH_SALT' ) || define( 'AUTH_SALT', 'test-auth-salt' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );

require_once IMMENS_FORECAST_PATH . 'includes/Autoloader.php';
\ImmensForecast\Autoloader::register( 'ImmensForecast\\', IMMENS_FORECAST_PATH . 'includes' );
