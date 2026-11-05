<?php
/**
 * Engine orchestration tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast
 */

namespace ImmensForecast\Tests\Unit\Forecast;

use Brain\Monkey\Functions;
use ImmensForecast\Admin\Settings;
use ImmensForecast\Forecast\Engine;
use ImmensForecast\Forecast\Providers\ProviderInterface;
use ImmensForecast\Sources\SourceInterface;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Validation, caching, locking and fallback.
 */
final class EngineTest extends TestCase {

	/**
	 * Build a source double.
	 *
	 * @param array|\WP_Error $result Value returned by get_series().
	 * @return SourceInterface
	 */
	private function make_source( $result ) {
		return new class( $result ) implements SourceInterface {
			private $result;

			public function __construct( $result ) {
				$this->result = $result;
			}

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
				return $this->result;
			}
		};
	}

	/**
	 * Build a provider double counting the calls.
	 *
	 * @param string          $id      Provider id.
	 * @param array|\WP_Error $payload Value returned by forecast().
	 * @param \stdClass       $calls   Call counter.
	 * @return ProviderInterface
	 */
	private function make_provider( string $id, $payload, \stdClass $calls ) {
		return new class( $id, $payload, $calls ) implements ProviderInterface {
			private $id;
			private $payload;
			private $calls;

			public function __construct( $id, $payload, $calls ) {
				$this->id      = $id;
				$this->payload = $payload;
				$this->calls   = $calls;
			}

			public function get_id(): string {
				return $this->id;
			}

			public function get_label(): string {
				return $this->id;
			}

			public function get_description(): string {
				return '';
			}

			public function get_capabilities(): array {
				return array( 'max_horizon' => 180, 'max_series' => 20 );
			}

			public function is_available(): bool {
				return true;
			}

			public function forecast( array $series, int $horizon, array $options ) {
				$this->calls->n++;
				return $this->payload;
			}

			public function health() {
				return array( 'status' => 'ok' );
			}
		};
	}

	/**
	 * Register source and providers through the public filters.
	 *
	 * @param SourceInterface|null $source    Source double.
	 * @param array                $providers Providers keyed by id.
	 * @return void
	 */
	private function register( $source, array $providers ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( $source, $providers ) {
				if ( 'immens_forecast_sources' === $tag ) {
					return ( null === $source ) ? array() : array( $source );
				}
				if ( 'immens_forecast_providers' === $tag ) {
					return $providers;
				}
				return $value;
			}
		);
	}

	/**
	 * Series payload fixture.
	 *
	 * @param int $points History points.
	 * @return array
	 */
	private function series_payload( int $points = 20 ): array {
		$dates  = array();
		$values = array();
		for ( $i = 0; $i < $points; $i++ ) {
			$dates[]  = gmdate( 'Y-m-d', strtotime( '2025-01-01 +' . $i . ' days' ) );
			$values[] = 10.0 + $i;
		}

		return array(
			'id'               => 'revenue',
			'metric'           => 'revenue',
			'granularity'      => 'day',
			'dates'            => $dates,
			'values'           => $values,
			'future_dates'     => array( '2025-01-21', '2025-01-22' ),
			'past_covariates'  => array( 'dow' => array_fill( 0, $points, 3 ) ),
			'known_covariates' => array( 'dow' => array_fill( 0, $points + 2, 3 ) ),
		);
	}

	/**
	 * Provider payload fixture.
	 *
	 * @return array
	 */
	private function provider_payload(): array {
		return array(
			'model'           => 'fake-model',
			'horizon'         => 2,
			'quantile_levels' => array( 0.1, 0.5, 0.9 ),
			'series'          => array(
				array(
					'id'           => 'revenue',
					'context_used' => 20,
					'median'       => array( 40.0, 41.0 ),
					'quantiles'    => array(
						'0.1' => array( 30.0, 31.0 ),
						'0.5' => array( 40.0, 41.0 ),
						'0.9' => array( 50.0, 51.0 ),
					),
				),
			),
		);
	}

	/**
	 * Params fixture.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private function params( array $overrides = array() ): array {
		return array_merge(
			array(
				'source'       => 'fake',
				'metric'       => 'revenue',
				'granularity'  => 'day',
				'history_days' => 365,
				'horizon'      => 2,
				'provider'     => 'fake',
				'model'        => 'fake-model',
				'quantiles'    => array( 0.1, 0.5, 0.9 ),
				'refresh'      => false,
			),
			$overrides
		);
	}

	protected function setUp(): void {
		parent::setUp();
		Settings::reset_cache();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias(
			static function ( $value ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
			}
		);
	}

	public function test_run_rejects_unknown_source(): void {
		$calls = new \stdClass();
		$calls->n = 0;
		$this->register( null, array() );

		$result = Engine::run( $this->params( array( 'source' => 'missing' ) ) );

		self::assertSame( 'immens_forecast_source_unavailable', $result->get_error_code() );
	}

	public function test_run_rejects_unknown_metric(): void {
		$calls = new \stdClass();
		$calls->n = 0;
		$this->register( $this->make_source( $this->series_payload() ), array() );

		$result = Engine::run( $this->params( array( 'metric' => 'orders' ) ) );

		self::assertSame( 'immens_forecast_unsupported_params', $result->get_error_code() );
	}

	public function test_run_returns_meta_and_kpis(): void {
		$calls     = new \stdClass();
		$calls->n  = 0;
		$baseline  = new \stdClass();
		$baseline->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array(
				'fake'     => $this->make_provider( 'fake', $this->provider_payload(), $calls ),
				'baseline' => $this->make_provider( 'baseline', $this->provider_payload(), $baseline ),
			)
		);

		$result = Engine::run( $this->params() );

		self::assertSame( 'fake', $result['provider'] );
		self::assertSame( 'fake-model', $result['model'] );
		self::assertFalse( $result['cached'] );
		self::assertFalse( $result['fallback'] );
		self::assertCount( 2, $result['dates'] );
		self::assertArrayHasKey( 'forecast_total', $result['kpis'] );
		self::assertSame( 1, $calls->n );
	}

	public function test_run_uses_cache_hit(): void {
		$calls    = new \stdClass();
		$calls->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array( 'fake' => $this->make_provider( 'fake', $this->provider_payload(), $calls ) )
		);
		Functions\when( 'get_transient' )->justReturn( array( 'provider' => 'fake', 'dates' => array() ) );

		$result = Engine::run( $this->params() );

		self::assertTrue( $result['cached'] );
		self::assertSame( 0, $calls->n );
	}

	public function test_run_refresh_bypasses_cache(): void {
		$calls    = new \stdClass();
		$calls->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array( 'fake' => $this->make_provider( 'fake', $this->provider_payload(), $calls ) )
		);
		Functions\when( 'get_transient' )->justReturn( array( 'provider' => 'fake' ) );

		$result = Engine::run( $this->params( array( 'refresh' => true ) ) );

		self::assertFalse( $result['cached'] );
		self::assertSame( 1, $calls->n );
	}

	public function test_run_falls_back_on_provider_error(): void {
		$calls    = new \stdClass();
		$calls->n = 0;
		$baseline = new \stdClass();
		$baseline->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array(
				'fake'     => $this->make_provider( 'fake', new \WP_Error( 'immens_forecast_endpoint_timeout', 'The endpoint timed out.' ), $calls ),
				'baseline' => $this->make_provider( 'baseline', $this->provider_payload(), $baseline ),
			)
		);

		$result = Engine::run( $this->params() );

		self::assertSame( 'baseline', $result['provider'] );
		self::assertTrue( $result['fallback'] );
		self::assertSame( 'immens_forecast_endpoint_timeout', $result['fallback_code'] );
		self::assertSame( 'The endpoint timed out.', $result['fallback_reason'] );
		self::assertSame( 1, $calls->n );
		self::assertSame( 1, $baseline->n );
	}

	public function test_run_maps_429_to_rate_limited(): void {
		$calls = new \stdClass();
		$calls->n = 0;
		$baseline = new \stdClass();
		$baseline->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array(
				'fake'     => $this->make_provider( 'fake', new \WP_Error( 'immens_forecast_endpoint_http', 'Too many requests.', array( 'status' => 429 ) ), $calls ),
				'baseline' => $this->make_provider( 'baseline', $this->provider_payload(), $baseline ),
			)
		);

		$result = Engine::run( $this->params() );

		self::assertSame( 'immens_forecast_rate_limited', $result['fallback_code'] );
		self::assertTrue( $result['fallback'] );
	}

	public function test_run_returns_locked_when_lock_is_held(): void {
		$calls = new \stdClass();
		$calls->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array( 'fake' => $this->make_provider( 'fake', $this->provider_payload(), $calls ) )
		);
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return ( false !== strpos( (string) $key, 'lock' ) ) ? time() + 30 : array();
			}
		);

		$result = Engine::run( $this->params(), 7 );

		self::assertSame( 'immens_forecast_locked', $result->get_error_code() );
		self::assertSame( 0, $calls->n );
	}

	public function test_run_returns_original_error_when_fallback_fails(): void {
		$calls = new \stdClass();
		$calls->n = 0;
		$baseline = new \stdClass();
		$baseline->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array(
				'fake'     => $this->make_provider( 'fake', new \WP_Error( 'immens_forecast_endpoint_http', 'Too many requests.', array( 'status' => 429, 'retry_after' => 42 ) ), $calls ),
				'baseline' => $this->make_provider( 'baseline', new \WP_Error( 'immens_forecast_insufficient_data', 'Not enough data.' ), $baseline ),
			)
		);

		$result = Engine::run( $this->params() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'immens_forecast_rate_limited', $result->get_error_code() );
		self::assertSame( 'Rate limit reached. Retry in 42 seconds.', $result->get_error_message() );
		self::assertSame( array( 'status' => 429, 'retry_after' => 42 ), $result->get_error_data() );
		self::assertSame( 1, $calls->n );
		self::assertSame( 1, $baseline->n );
	}

	public function test_run_returns_a_plain_error_when_fallback_fails_without_retry_after(): void {
		$calls = new \stdClass();
		$calls->n = 0;
		$baseline = new \stdClass();
		$baseline->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array(
				'fake'     => $this->make_provider( 'fake', new \WP_Error( 'immens_forecast_endpoint_http', 'Too many requests.', array( 'status' => 429 ) ), $calls ),
				'baseline' => $this->make_provider( 'baseline', new \WP_Error( 'immens_forecast_insufficient_data', 'Not enough data.' ), $baseline ),
			)
		);

		$result = Engine::run( $this->params() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'immens_forecast_rate_limited', $result->get_error_code() );
		self::assertSame( 'Too many requests.', $result->get_error_message() );
		self::assertSame( array( 'status' => 429 ), $result->get_error_data() );
	}

	public function test_run_returns_rate_limited_when_baseline_is_missing(): void {
		$calls = new \stdClass();
		$calls->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array( 'fake' => $this->make_provider( 'fake', new \WP_Error( 'immens_forecast_endpoint_http', 'Too many requests.', array( 'status' => 429 ) ), $calls ) )
		);

		$result = Engine::run( $this->params() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'immens_forecast_rate_limited', $result->get_error_code() );
		self::assertSame( array( 'status' => 429 ), $result->get_error_data() );
	}
}
