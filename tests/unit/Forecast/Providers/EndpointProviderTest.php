<?php
/**
 * Endpoint provider tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast\Providers
 */

namespace ImmensForecast\Tests\Unit\Forecast\Providers;

use Brain\Monkey\Functions;
use ImmensForecast\Admin\Settings;
use ImmensForecast\Forecast\Providers\EndpointProvider;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * HTTP contract, mapping and error translation.
 */
final class EndpointProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Settings::reset_cache();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
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
	}

	protected function tearDown(): void {
		Settings::reset_cache();
		parent::tearDown();
	}

	/**
	 * Store settings for the provider.
	 *
	 * @param array $settings Settings subset.
	 * @return void
	 */
	private function configure( array $settings ): void {
		Functions\when( 'get_option' )->justReturn( $settings );
		Settings::reset_cache();
	}

	public function test_is_available_requires_url(): void {
		$provider = new EndpointProvider();

		$this->configure( array() );
		self::assertFalse( $provider->is_available() );

		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		self::assertTrue( $provider->is_available() );
	}

	public function test_capabilities_require_remote(): void {
		$capabilities = ( new EndpointProvider() )->get_capabilities();

		self::assertTrue( $capabilities['requires_remote'] );
		self::assertSame( 1024, $capabilities['max_horizon'] );
		self::assertCount( 99, $capabilities['quantile_levels'] );
	}

	public function test_forecast_without_url_errors(): void {
		$this->configure( array() );

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 3, array() );

		self::assertSame( 'immens_forecast_provider_unavailable', $result->get_error_code() );
	}

	public function test_forecast_success_maps_payload(): void {
		$this->configure(
			array(
				'endpoint_url'   => 'https://forecast.example.com',
				'endpoint_model' => '209m',
			)
		);
		$payload = array(
			'model'           => array(
				'alias'  => '209m',
				'hub_id' => 'mistralai/Timestral-209M',
			),
			'horizon'         => 2,
			'quantile_levels' => array( 0.1, 0.5, 0.9 ),
			'series'          => array(
				array(
					'id'           => 'revenue',
					'context_used' => 30,
					'quantiles'    => array(
						'0.1' => array( 1.0, 2.0 ),
						'0.5' => array( 10.0, 20.0 ),
						'0.9' => array( 19.0, 38.0 ),
					),
				),
			),
		);
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( $payload ),
			)
		);

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0, 2.0 ) ) ), 2, array() );

		self::assertSame( '209m', $result['model'] );
		self::assertSame( 2, $result['horizon'] );
		self::assertSame( 'revenue', $result['series'][0]['id'] );
		self::assertSame( 30, $result['series'][0]['context_used'] );
		// Median is derived from the 0.5 quantile when missing in the response.
		self::assertEquals( array( 10.0, 20.0 ), $result['series'][0]['median'] );
		self::assertEquals( array( 19.0, 38.0 ), $result['series'][0]['quantiles']['0.9'] );
	}

	public function test_forecast_unauthorized_error(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '',
			)
		);

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 2, array() );

		self::assertSame( 'immens_forecast_endpoint_unauthorized', $result->get_error_code() );
	}

	public function test_forecast_http_error(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => 'boom',
			)
		);

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 2, array() );

		self::assertSame( 'immens_forecast_endpoint_http', $result->get_error_code() );
		self::assertSame( array( 'status' => 500 ), $result->get_error_data() );
	}

	public function test_forecast_bad_response(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'not-json',
			)
		);

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 2, array() );

		self::assertSame( 'immens_forecast_endpoint_bad_response', $result->get_error_code() );
	}

	public function test_forecast_timeout_error(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 60001 milliseconds' ) );

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 2, array() );

		self::assertSame( 'immens_forecast_endpoint_timeout', $result->get_error_code() );
	}

	public function test_forecast_unreachable_error(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ) );

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 2, array() );

		self::assertSame( 'immens_forecast_endpoint_unreachable', $result->get_error_code() );
	}

	public function test_forecast_sends_json_body_and_headers(): void {
		$this->configure(
			array(
				'endpoint_url'     => 'https://forecast.example.com/',
				'endpoint_model'   => '28m',
				'endpoint_timeout' => 45,
			)
		);
		$captured = array();
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array( 'series' => array() ) ),
				);
			}
		);

		( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 2, array() );

		self::assertSame( 'https://forecast.example.com/v1/forecast', $captured['url'] );
		self::assertSame( 45, $captured['args']['timeout'] );
		self::assertTrue( $captured['args']['sslverify'] );
		self::assertArrayNotHasKey( 'Authorization', $captured['args']['headers'] );
		self::assertSame( 'application/json', $captured['args']['headers']['Content-Type'] );

		$body = json_decode( $captured['args']['body'], true );
		self::assertSame( '28m', $body['model'] );
		self::assertSame( 2, $body['horizon'] );
		self::assertSame( array( 0.1, 0.5, 0.9 ), $body['quantiles'] );
	}

	public function test_health_success(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'status' => 'ok' ) ),
			)
		);

		$result = ( new EndpointProvider() )->health();

		self::assertSame( 'ok', $result['status'] );
		self::assertSame( 200, $result['http'] );
	}

	public function test_health_http_error(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 503 ),
				'body'     => '',
			)
		);

		$result = ( new EndpointProvider() )->health();

		self::assertSame( 'immens_forecast_endpoint_http', $result->get_error_code() );
	}
}
