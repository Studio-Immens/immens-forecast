<?php
/**
 * Main plugin class.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast;

use ImmensForecast\Forecast\Engine;

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

		if ( wp_doing_ajax() ) {
			Admin\Ajax::register();
		}

		add_action( 'admin_menu', array( Admin\Page::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( Admin\Page::class, 'enqueue' ) );
		add_action( 'admin_post_immens_forecast_save_settings', array( Admin\Page::class, 'handle_save' ) );
		add_action( 'admin_post_immens_forecast_import_csv', array( Admin\Page::class, 'handle_import' ) );
		add_action( 'admin_post_immens_forecast_delete_dataset', array( Admin\Page::class, 'handle_delete_dataset' ) );

		add_filter(
			'immens_forecast_sources',
			static function ( array $sources ) {
				$sources[] = new Sources\WooSource();
				$sources[] = new Sources\ManualSource();
				return $sources;
			}
		);

		add_filter(
			'immens_forecast_providers',
			static function ( array $providers ) {
				$providers[] = new Forecast\Providers\BaselineProvider();
				$providers[] = new Forecast\Providers\EndpointProvider();
				return $providers;
			}
		);
	}

	/**
	 * Forecast engine.
	 *
	 * @return Engine
	 */
	public function engine(): Engine {
		return new Engine();
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
