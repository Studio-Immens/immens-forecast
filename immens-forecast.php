<?php
/**
 * Plugin Name:       Immens Forecast – Sales & Time Series Forecasting
 * Plugin URI:        https://studioimmens.com/plugins/immens-forecast
 * Description:       Forecast sales and time series (WooCommerce or custom datasets) with an offline baseline model or any external forecasting endpoint you connect.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Studio Immens
 * Author URI:        https://studioimmens.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       immens-forecast
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   10.9
 *
 * @package ImmensForecast
 */

defined( 'ABSPATH' ) || exit;

define( 'IMMENS_FORECAST_VERSION', '0.1.0' );
define( 'IMMENS_FORECAST_FILE', __FILE__ );
define( 'IMMENS_FORECAST_PATH', plugin_dir_path( __FILE__ ) );
define( 'IMMENS_FORECAST_URL', plugin_dir_url( __FILE__ ) );

require_once IMMENS_FORECAST_PATH . 'includes/Autoloader.php';
ImmensForecast\Autoloader::register( 'ImmensForecast\\', IMMENS_FORECAST_PATH . 'includes' );

register_activation_hook( __FILE__, array( 'ImmensForecast\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ImmensForecast\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		ImmensForecast\Plugin::instance()->boot();
	}
);
