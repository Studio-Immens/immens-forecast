<?php
/**
 * Main plugin class.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton orchestration class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Forecast engine (wired in boot(), from Task 12).
	 *
	 * @var Forecast\Engine|null
	 */
	private $engine = null;

	/**
	 * Get the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor: work happens in boot().
	 */
	private function __construct() {}

	/**
	 * Boot the plugin on plugins_loaded.
	 *
	 * @return void
	 */
	public function boot(): void {
		load_plugin_textdomain( 'immens-forecast', false, dirname( plugin_basename( IMMENS_FORECAST_FILE ) ) . '/languages' );
	}

	/**
	 * Forecast engine accessor (full wiring lands in Task 12).
	 *
	 * @return Forecast\Engine|null
	 */
	public function engine() {
		return $this->engine;
	}

	/**
	 * Activation: default options + datasets table.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( null === get_option( Admin\Settings::OPTION, null ) ) {
			add_option( Admin\Settings::OPTION, Admin\Settings::defaults() );
		}
		Storage\Datasets::create_table();
	}

	/**
	 * Deactivation: nothing to do (no cron in the free plugin).
	 *
	 * @return void
	 */
	public static function deactivate(): void {}
}
