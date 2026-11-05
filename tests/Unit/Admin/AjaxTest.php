<?php
/**
 * AJAX endpoint tests.
 *
 * @package ImmensForecast\Tests\Unit\Admin
 */

namespace ImmensForecast\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use ImmensForecast\Admin\Ajax;
use ImmensForecast\Admin\Settings;
use ImmensForecast\Forecast\Providers\ProviderInterface;
use ImmensForecast\Sources\SourceInterface;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Sentinel thrown by the wp_send_json_* stubs.
 */
final class AjaxResponse extends \Exception {

	/** @var string */
	public $type;

	/** @var mixed */
	public $payload;

	/** @var int */
	public $status;

	/**
	 * Constructor.
	 *
	 * @param string $type    success|error.
	 * @param mixed  $payload Payload.
	 * @param int    $status  HTTP status.
	 */
	public function __construct( string $type, $payload, int $status ) {
		parent::__construct( $type );
		$this->type    = $type;
		$this->payload = $payload;
		$this->status  = $status;
	}
}

/**
 * Guards, sanitization and response envelopes.
 */
final class AjaxTest extends TestCase {

	/**
	 * Capture wp_send_json_* calls as exceptions.
	 *
	 * @return void
	 */
	private function capture(): void {
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null, $status = 200 ) {
				throw new AjaxResponse( 'success', $data, (int) $status );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = 200 ) {
				throw new AjaxResponse( 'error', $data, (int) $status );
			}
		);
	}

	/**
	 * Invoke a handler and return the captured response.
	 *
	 * @param callable $handler Handler.
	 * @return AjaxResponse
	 */
	private function invoke( callable $handler ): AjaxResponse {
		try {
			$handler();
		} catch ( AjaxResponse $response ) {
			return $response;
		}
		self::fail( 'No JSON response was sent.' );
	}

	/**
	 * Source double.
	 *
	 * @return SourceInterface
	 */
	private function source_double() {
		return new class() implements SourceInterface {
			public function get_id(): string {
				return 'fake';
			}

			public function get_label(): string {
				return 'Fake';
			}

			public function get_description(): string {
				return '';
			}

			public function is_available(): bool {
				return true;
			}

			public function get_metrics(): array {
				return array( 'revenue' => array( 'label' => 'Revenue', 'granularities' => array( 'day' ) ) );
			}

			public function get_series( array $args ) {
				return array(
					'id'               => 'revenue',
					'dates'            => array( '2025-01-01', '2025-01-02' ),
					'values'           => array( 10.0, 20.0 ),
					'future_dates'     => array( '2025-01-03' ),
					'past_covariates'  => array( 'dow' => array( 3, 4 ) ),
					'known_covariates' => array( 'dow' => array( 3, 4, 5 ) ),
				);
			}
		};
	}

	/**
	 * Provider double.
	 *
	 * @return ProviderInterface
	 */
	private function provider_double() {
		return new class() implements ProviderInterface {
			public function get_id(): string {
				return 'fake';
			}

			public function get_label(): string {
				return 'Fake';
			}

			public function get_description(): string {
				return '';
			}

			public function get_capabilities(): array {
				return array( 'max_horizon' => 180 );
			}

			public function is_available(): bool {
				return true;
			}

			public function forecast( array $series, int $horizon, array $options ) {
				return array(
					'model'           => 'fake-model',
					'horizon'         => 1,
					'quantile_levels' => array( 0.1, 0.5, 0.9 ),
					'series'          => array(
						array(
							'id'           => 'revenue',
							'context_used' => 2,
							'median'       => array( 30.0 ),
							'quantiles'    => array(
								'0.1' => array( 25.0 ),
								'0.5' => array( 30.0 ),
								'0.9' => array( 35.0 ),
							),
						),
					),
				);
			}

			public function health() {
				return array( 'status' => 'ok' );
			}
		};
	}

	/**
	 * Register the source/provider doubles through the filters.
	 *
	 * @return void
	 */
	private function register_doubles(): void {
		$source    = $this->source_double();
		$provider  = $this->provider_double();
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( $source, $provider ) {
				if ( 'immens_forecast_sources' === $tag ) {
					return array( $source );
				}
				if ( 'immens_forecast_providers' === $tag ) {
					return array( $provider, $provider );
				}
				return $value;
			}
		);
	}

	protected function setUp(): void {
		parent::setUp();
		Settings::reset_cache();
		$_POST = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $value ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
			}
		);
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

	public function test_register_hooks_three_actions(): void {
		Functions\expect( 'add_action' )->times( 3 )->andReturn( true );

		Ajax::register();

		// The Mockery expectation above is the assertion for this test.
		$this->addToAssertionCount( 1 );
	}

	public function test_collect_params_whitelists_and_clamps(): void {
		$params = Ajax::collect_params(
			array(
				'source'       => 'Woo!',
				'metric'       => 'Revenue',
				'granularity'  => 'year',
				'history_days' => '99999',
				'horizon'      => '0',
				'quantiles'    => array( '0.1', 'junk', '1.5' ),
				'dataset_id'   => '7',
				'refresh'      => '1',
				'evil'         => 'x',
			)
		);

		self::assertSame( 'woo', $params['source'] );
		self::assertSame( 'revenue', $params['metric'] );
		self::assertSame( 'day', $params['granularity'] );
		self::assertSame( 3650, $params['history_days'] );
		self::assertSame( 1, $params['horizon'] );
		self::assertSame( array( 0.1 ), $params['quantiles'] );
		self::assertSame( 7, $params['dataset_id'] );
		self::assertTrue( $params['refresh'] );
		self::assertArrayNotHasKey( 'evil', $params );
	}

	public function test_run_denies_without_capability(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->capture();

		$response = $this->invoke( array( Ajax::class, 'run' ) );

		self::assertSame( 'error', $response->type );
		self::assertSame( 403, $response->status );
		self::assertSame( 'immens_forecast_forbidden', $response->payload['code'] );
	}

	public function test_run_sends_engine_error(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->capture();
		$_POST = array(
			'source' => 'missing',
			'metric' => 'revenue',
		);

		$response = $this->invoke( array( Ajax::class, 'run' ) );

		self::assertSame( 'error', $response->type );
		self::assertSame( 400, $response->status );
		self::assertSame( 'immens_forecast_source_unavailable', $response->payload['code'] );
	}

	public function test_run_sends_success_payload(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->register_doubles();
		$this->capture();
		$_POST = array(
			'source'   => 'fake',
			'metric'   => 'revenue',
			'provider' => 'fake',
			'horizon'  => '1',
		);

		$response = $this->invoke( array( Ajax::class, 'run' ) );

		self::assertSame( 'success', $response->type );
		self::assertSame( 'fake', $response->payload['provider'] );
		self::assertArrayHasKey( 'kpis', $response->payload );
	}

	public function test_test_endpoint_persists_settings_and_returns_health(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$updated = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$updated ) {
				$updated = array( $key, $value );
				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"status":"ok"}',
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return isset( $response['body'] ) ? (string) $response['body'] : '';
			}
		);
		$this->capture();
		$_POST = array(
			'endpoint_url'     => 'https://forecast.example.com',
			'endpoint_model'   => '28m',
			'endpoint_timeout' => '45',
			'endpoint_token'   => '',
		);

		$response = $this->invoke( array( Ajax::class, 'test_endpoint' ) );

		self::assertSame( 'success', $response->type );
		self::assertSame( 'ok', $response->payload['status'] );
		self::assertSame( 'immens_forecast_settings', $updated[0] );
		self::assertSame( 'https://forecast.example.com', $updated[1]['endpoint_url'] );
		self::assertSame( '28m', $updated[1]['endpoint_model'] );
		self::assertSame( 45, $updated[1]['endpoint_timeout'] );
	}

	public function test_load_models_returns_aliases(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"models":[{"alias":"28m","hub_id":"a/28m"},{"alias":"209m"}]}',
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return isset( $response['body'] ) ? (string) $response['body'] : '';
			}
		);
		$this->capture();
		$_POST = array( 'endpoint_url' => 'https://forecast.example.com' );

		$response = $this->invoke( array( Ajax::class, 'load_models' ) );

		self::assertSame( 'success', $response->type );
		self::assertCount( 2, $response->payload['models'] );
		self::assertSame( '209m', $response->payload['models'][1]['alias'] );
	}

	public function test_load_models_maps_transport_error(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
		$this->capture();
		$_POST = array( 'endpoint_url' => 'https://forecast.example.com' );

		$response = $this->invoke( array( Ajax::class, 'load_models' ) );

		self::assertSame( 'error', $response->type );
		self::assertSame( 'immens_forecast_endpoint_timeout', $response->payload['code'] );
		self::assertSame( 400, $response->status );
	}
}
