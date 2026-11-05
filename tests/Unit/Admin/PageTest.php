<?php
/**
 * Admin page tests.
 *
 * @package ImmensForecast\Tests\Unit\Admin
 */

namespace ImmensForecast\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use ImmensForecast\Admin\Page;
use ImmensForecast\Admin\Settings;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Sentinel for redirect/die exits.
 */
final class AdminExit extends \Exception {

	/** @var string */
	public $destination;

	/**
	 * Constructor.
	 *
	 * @param string $destination Target URL or message.
	 */
	public function __construct( string $destination ) {
		parent::__construct( $destination );
		$this->destination = $destination;
	}
}

/**
 * Menu, assets, templates and settings saving.
 */
final class PageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Settings::reset_cache();
		$_POST = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return (int) abs( (int) $value );
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		Settings::reset_cache();
		parent::tearDown();
	}

	public function test_register_adds_menu_and_submenus(): void {
		Functions\expect( 'add_menu_page' )->once()->andReturn( 'toplevel_page_immens-forecast' );
		Functions\expect( 'add_submenu_page' )->twice()->andReturn( 'immens-forecast_page_x' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		Page::register();

		$this->addToAssertionCount( 1 );
	}

	public function test_register_appends_filtered_submenus(): void {
		Functions\expect( 'add_menu_page' )->once()->andReturn( 'toplevel_page_immens-forecast' );
		Functions\expect( 'add_submenu_page' )->times( 3 )->andReturn( 'immens-forecast_page_x' );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'immens_forecast_admin_menu' === $tag ) {
					return array(
						array(
							'title'    => 'Forecast Pro',
							'slug'     => 'immens-forecast-pro',
							'callback' => '__return_null',
						),
					);
				}
				return $value;
			}
		);

		Page::register();

		$this->addToAssertionCount( 1 );
	}

	public function test_enqueue_skips_unrelated_hooks(): void {
		Page::enqueue( 'edit.php' );

		$this->addToAssertionCount( 1 );
	}

	public function test_enqueue_registers_assets_and_localizes(): void {
		$localized = array();
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'immens-forecast-admin', IMMENS_FORECAST_URL . 'assets/css/admin.css', array(), IMMENS_FORECAST_VERSION )->andReturn( true );
		Functions\expect( 'wp_enqueue_script' )->twice();
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $data ) use ( &$localized ) {
				$localized = array(
					'handle' => $handle,
					'name'   => $name,
					'data'   => $data,
				);
				return true;
			}
		);
		Functions\expect( 'wp_set_script_translations' )->once()->with( 'immens-forecast-admin', 'immens-forecast' )->andReturn( true );

		Page::enqueue( 'toplevel_page_immens-forecast' );

		self::assertSame( 'immens-forecast-admin', $localized['handle'] );
		self::assertSame( 'immensForecast', $localized['name'] );
		self::assertSame( 'nonce123', $localized['data']['nonce'] );
		self::assertSame( 'https://example.test/wp-admin/admin-ajax.php', $localized['data']['ajaxUrl'] );
		self::assertArrayHasKey( 'i18n', $localized['data'] );
	}

	public function test_template_path_is_filterable_with_fallback(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		self::assertSame( IMMENS_FORECAST_PATH . 'templates/forecast-page.php', Page::template_path( 'forecast-page.php' ) );

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'immens_forecast_template_path' === $tag ) {
					return '/does/not/exist/forecast-page.php';
				}
				return $value;
			}
		);

		self::assertSame( IMMENS_FORECAST_PATH . 'templates/forecast-page.php', Page::template_path( 'forecast-page.php' ) );
	}

	public function test_handle_save_persists_sanitized_settings(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\expect( 'check_admin_referer' )->once()->with( 'immens_forecast_save_settings' );
		$updated = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$updated ) {
				$updated = array( $key, $value );
				return true;
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) {
				throw new AdminExit( (string) $url );
			}
		);
		$_POST = array(
			'provider'     => 'endpoint',
			'endpoint_url' => 'https://forecast.example.com',
		);

		try {
			Page::handle_save();
		} catch ( AdminExit $exit ) {
			self::assertStringContainsString( 'page=immens-forecast-settings', $exit->destination );
			self::assertSame( 'immens_forecast_settings', $updated[0] );
			self::assertSame( 'endpoint', $updated[1]['provider'] );
			self::assertSame( 'https://forecast.example.com', $updated[1]['endpoint_url'] );
			return;
		}
		self::fail( 'Expected a redirect.' );
	}

	public function test_handle_save_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\expect( 'check_admin_referer' )->never();
		Functions\when( 'wp_die' )->alias(
			static function ( $message = '', $title = '', $args = array() ) {
				throw new AdminExit( (string) $message );
			}
		);

		try {
			Page::handle_save();
		} catch ( AdminExit $exit ) {
			self::assertNotSame( '', $exit->destination );
			return;
		}
		self::fail( 'Expected wp_die().' );
	}
}
