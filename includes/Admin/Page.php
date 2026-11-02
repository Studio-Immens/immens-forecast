<?php
/**
 * Admin pages and assets.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Admin;

use ImmensForecast\Forecast\Engine;
use ImmensForecast\Forecast\Providers\ProviderInterface;
use ImmensForecast\Sources\CsvParser;
use ImmensForecast\Sources\SourceInterface;
use ImmensForecast\Storage\Datasets;

defined( 'ABSPATH' ) || exit;

/**
 * Forecast and settings screens.
 */
final class Page {

	/**
	 * Register the admin menu and its submenus.
	 *
	 * @return void
	 */
	public static function register(): void {
		$capability = Settings::capability();

		add_menu_page(
			__( 'Forecast', 'immens-forecast' ),
			__( 'Forecast', 'immens-forecast' ),
			$capability,
			'immens-forecast',
			array( __CLASS__, 'render_forecast' ),
			'dashicons-chart-line',
			30
		);

		add_submenu_page(
			'immens-forecast',
			__( 'Forecast', 'immens-forecast' ),
			__( 'Forecast', 'immens-forecast' ),
			$capability,
			'immens-forecast',
			array( __CLASS__, 'render_forecast' )
		);

		add_submenu_page(
			'immens-forecast',
			__( 'Settings', 'immens-forecast' ),
			__( 'Settings', 'immens-forecast' ),
			$capability,
			'immens-forecast-settings',
			array( __CLASS__, 'render_settings' )
		);

		$extra = apply_filters( 'immens_forecast_admin_menu', array() );
		if ( ! is_array( $extra ) ) {
			return;
		}

		foreach ( $extra as $item ) {
			if ( ! is_array( $item ) || empty( $item['slug'] ) || empty( $item['title'] ) ) {
				continue;
			}
			$callback = isset( $item['callback'] ) && is_callable( $item['callback'] ) ? $item['callback'] : '__return_null';
			add_submenu_page(
				'immens-forecast',
				(string) $item['title'],
				(string) $item['title'],
				$capability,
				(string) $item['slug'],
				$callback
			);
		}
	}

	/**
	 * Enqueue the admin assets on the plugin screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'immens-forecast' ) ) {
			return;
		}

		wp_enqueue_style(
			'immens-forecast-admin',
			IMMENS_FORECAST_URL . 'assets/css/admin.css',
			array(),
			IMMENS_FORECAST_VERSION
		);

		wp_enqueue_script(
			'immens-forecast-chart',
			IMMENS_FORECAST_URL . 'assets/vendor/chart.umd.min.js',
			array(),
			'3.9.1',
			true
		);

		wp_enqueue_script(
			'immens-forecast-admin',
			IMMENS_FORECAST_URL . 'assets/js/admin.js',
			array( 'immens-forecast-chart' ),
			IMMENS_FORECAST_VERSION,
			true
		);

		wp_localize_script(
			'immens-forecast-admin',
			'immensForecast',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( Ajax::NONCE ),
				'pageUrl'     => admin_url( 'admin.php?page=immens-forecast' ),
				'settingsUrl' => admin_url( 'admin.php?page=immens-forecast-settings' ),
				'i18n'        => array(
					'error'        => __( 'The forecast could not be generated.', 'immens-forecast' ),
					'fallback'     => __( 'Local baseline (limited data)', 'immens-forecast' ),
					'testing'      => __( 'Testing...', 'immens-forecast' ),
					'loading'      => __( 'Loading...', 'immens-forecast' ),
					'testOk'       => __( 'Connection OK.', 'immens-forecast' ),
					'testFailed'   => __( 'Connection failed.', 'immens-forecast' ),
					'modelsLoaded' => __( 'Models loaded.', 'immens-forecast' ),
					'noModels'     => __( 'No models returned by the endpoint.', 'immens-forecast' ),
				),
			)
		);

		wp_set_script_translations( 'immens-forecast-admin', 'immens-forecast' );
	}

	/**
	 * Render the forecast screen.
	 *
	 * @return void
	 */
	public static function render_forecast(): void {
		self::guard_render();

		self::render(
			'forecast-page.php',
			array(
				'sources'             => self::describe_sources(),
				'providers'           => self::describe_providers(),
				'datasets'            => self::describe_datasets(),
				'endpoint_configured' => Settings::is_endpoint_configured(),
				'imported'            => (int) filter_input( INPUT_GET, 'imported', FILTER_SANITIZE_NUMBER_INT ),
				'defaults'            => array(
					'provider'     => (string) Settings::get( 'provider', 'baseline' ),
					'horizon'      => (int) Settings::get( 'horizon', 30 ),
					'history_days' => (int) Settings::get( 'history_days', Engine::DEFAULT_HISTORY ),
					'quantiles'    => (array) Settings::get( 'quantiles', array( 0.1, 0.5, 0.9 ) ),
				),
			)
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public static function render_settings(): void {
		self::guard_render();

		$settings = Settings::all();

		self::render(
			'settings-page.php',
			array(
				'settings'      => $settings,
				'providers'     => self::describe_providers(),
				'datasets'      => self::describe_datasets(),
				'token_set'     => '' !== (string) Settings::endpoint_token(),
				'updated'       => (bool) filter_input( INPUT_GET, 'updated', FILTER_SANITIZE_NUMBER_INT ),
				'imported'      => (int) filter_input( INPUT_GET, 'imported', FILTER_SANITIZE_NUMBER_INT ),
				'import_failed' => (bool) filter_input( INPUT_GET, 'import_failed', FILTER_SANITIZE_NUMBER_INT ),
			)
		);
	}

	/**
	 * Persist the submitted settings.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to edit these settings.', 'immens-forecast' ), 403 );
		}

		check_admin_referer( 'immens_forecast_save_settings' );

		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer().

		Settings::update( Settings::sanitize( (array) $input ) );

		wp_safe_redirect( admin_url( 'admin.php?page=immens-forecast-settings&updated=1' ) );
		exit;
	}

	/**
	 * Resolve a template path, allowing theme/plugin overrides.
	 *
	 * @param string $template Template file name.
	 * @return string
	 */
	public static function template_path( string $template ): string {
		$default = IMMENS_FORECAST_PATH . 'templates/' . $template;
		$path    = (string) apply_filters( 'immens_forecast_template_path', $default, $template );

		return ( '' !== $path && file_exists( $path ) ) ? $path : $default;
	}

	/**
	 * Render a plugin template with the given arguments.
	 *
	 * @param string $template Template file name.
	 * @param array  $args     Template arguments exposed as $args.
	 * @return void
	 */
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Used by include.
	private static function render( string $template, array $args ): void {
		$path = self::template_path( $template );
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		include $path;
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Capability guard for the screens.
	 *
	 * @return void
	 */
	private static function guard_render(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'immens-forecast' ), 403 );
		}
	}

	/**
	 * Describe the registered sources for the UI.
	 *
	 * @return array
	 */
	private static function describe_sources(): array {
		$sources = array();

		foreach ( (array) apply_filters( 'immens_forecast_sources', array() ) as $source ) {
			if ( ! $source instanceof SourceInterface ) {
				continue;
			}

			$metrics = array();
			foreach ( (array) $source->get_metrics() as $id => $metric ) {
				$metrics[ sanitize_key( (string) $id ) ] = self::metric_label( $metric );
			}

			$sources[ $source->get_id() ] = array(
				'label'       => $source->get_label(),
				'description' => $source->get_description(),
				'available'   => $source->is_available(),
				'metrics'     => $metrics,
			);
		}

		return $sources;
	}

	/**
	 * Resolve a human label from a source metric definition.
	 *
	 * @param mixed $metric Metric definition array or a plain label.
	 * @return string
	 */
	private static function metric_label( $metric ): string {
		if ( is_array( $metric ) && isset( $metric['label'] ) ) {
			return (string) $metric['label'];
		}

		return is_scalar( $metric ) ? (string) $metric : '';
	}

	/**
	 * Describe the registered providers for the UI.
	 *
	 * @return array
	 */
	private static function describe_providers(): array {
		$providers = array();

		foreach ( (array) apply_filters( 'immens_forecast_providers', array() ) as $provider ) {
			if ( ! $provider instanceof ProviderInterface ) {
				continue;
			}

			$capabilities = $provider->get_capabilities();

			$providers[ $provider->get_id() ] = array(
				'label'       => $provider->get_label(),
				'description' => $provider->get_description(),
				'available'   => $provider->is_available(),
				'remote'      => is_array( $capabilities ) && ! empty( $capabilities['remote'] ),
			);
		}

		return $providers;
	}

	/**
	 * Describe the stored manual datasets.
	 *
	 * @return array
	 */
	private static function describe_datasets(): array {
		$datasets = array();

		foreach ( (array) Datasets::all() as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}

			$datasets[ (int) $row['id'] ] = array(
				'name'        => isset( $row['name'] ) ? (string) $row['name'] : '',
				'granularity' => isset( $row['granularity'] ) ? (string) $row['granularity'] : 'day',
			);
		}

		return $datasets;
	}

	/**
	 * Import a CSV dataset.
	 *
	 * @return void
	 */
	public static function handle_import(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to import data.', 'immens-forecast' ), 403 );
		}

		check_admin_referer( 'immens_forecast_import_csv' );

		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer().

		$name        = isset( $input['dataset_name'] ) ? sanitize_text_field( (string) $input['dataset_name'] ) : '';
		$granularity = isset( $input['dataset_granularity'] ) ? sanitize_key( (string) $input['dataset_granularity'] ) : 'day';
		$raw         = isset( $input['dataset_csv'] ) ? (string) $input['dataset_csv'] : '';

		$points = CsvParser::parse( $raw, $granularity );
		if ( is_wp_error( $points ) ) {
			self::redirect_settings( 'import_failed=1' );
		}

		$result = Datasets::create( $name, $granularity, $points );
		if ( is_wp_error( $result ) ) {
			self::redirect_settings( 'import_failed=1' );
		}

		self::redirect_settings( 'imported=' . (int) $result );
	}

	/**
	 * Delete a stored dataset.
	 *
	 * @return void
	 */
	public static function handle_delete_dataset(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to delete data.', 'immens-forecast' ), 403 );
		}

		check_admin_referer( 'immens_forecast_delete_dataset' );

		$id = (int) filter_input( INPUT_GET, 'dataset', FILTER_SANITIZE_NUMBER_INT );
		if ( $id > 0 ) {
			Datasets::delete( $id );
		}

		self::redirect_settings( 'deleted=1' );
	}

	/**
	 * Redirect back to the settings screen.
	 *
	 * @param string $query Extra query string.
	 * @return void
	 */
	private static function redirect_settings( string $query ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=immens-forecast-settings&' . $query ) );
		exit;
	}
}
