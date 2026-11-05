<?php
/**
 * WooSource tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

use Brain\Monkey\Functions;
use ImmensForecast\Sources\WooSource;
use ImmensForecast\Tests\Support\FakeWpdb;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Stats path, fallback path and their agreement.
 */
final class WooSourceTest extends TestCase {

	/**
	 * Fake database.
	 *
	 * @var FakeWpdb
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_like' )->alias(
			static function ( $text ) {
				return addcslashes( (string) $text, '_%\\' );
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-11-03' );
		Functions\when( 'wc_prices_include_tax' )->justReturn( false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Two days of stats-table aggregates: one order each day, refunds on day 2.
	 */
	private function seed_stats_rows(): void {
		$this->wpdb->tables = array( 'wp_wc_order_stats' );
		$this->wpdb->rows   = array(
			1 => array(
				'bucket'       => '2026-11-01',
				'orders_count' => 1,
				'gross'        => 100.0,
				'tax'          => 22.0,
				'items'        => 2,
				'refund_gross' => 0.0,
				'refund_tax'   => 0.0,
				'refund_items' => 0,
			),
			2 => array(
				'bucket'       => '2026-11-02',
				'orders_count' => 1,
				'gross'        => 50.0,
				'tax'          => 11.0,
				'items'        => 1,
				'refund_gross' => 30.0,
				'refund_tax'   => 6.6,
				'refund_items' => 1,
			),
		);
	}

	/**
	 * wc_get_orders() double: paginated batches split by order type.
	 *
	 * @param array $orders  shop_order objects.
	 * @param array $refunds shop_order_refund objects.
	 * @return callable
	 */
	private static function source_orders( array $orders, array $refunds ): callable {
		return static function ( $args ) use ( $orders, $refunds ) {
			return (object) array(
				'orders'        => ( 'shop_order_refund' === $args['type'] ) ? $refunds : $orders,
				'max_num_pages' => 1,
				'total'         => count( $orders ),
			);
		};
	}

	public function test_contract_and_metrics(): void {
		$source = new WooSource();

		self::assertSame( 'woo', $source->get_id() );
		self::assertNotSame( '', $source->get_label() );
		self::assertNotSame( '', $source->get_description() );

		$metrics = $source->get_metrics();
		self::assertSame( array( 'revenue', 'orders', 'items' ), array_keys( $metrics ) );
		foreach ( $metrics as $metric ) {
			self::assertSame( array( 'day', 'week' ), $metric['granularities'] );
			self::assertNotSame( '', $metric['label'] );
		}
	}

	/**
	 * `wc_get_orders` stays undefined until something stubs it; the throwing
	 * placeholder Brain\Monkey defines would keep function_exists() true for
	 * the rest of the process, so this test must run isolated.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_is_available_false_without_woocommerce(): void {
		self::assertFalse( ( new WooSource() )->is_available() );
	}

	public function test_is_available_true_with_woocommerce(): void {
		Functions\when( 'wc_get_orders' )->justReturn( array() );

		self::assertTrue( ( new WooSource() )->is_available() );
	}

	public function test_unsupported_metric_returns_error(): void {
		$error = ( new WooSource() )->get_series( array( 'metric' => 'profit', 'granularity' => 'day' ) );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'immens_forecast_unsupported_params', $error->get_error_code() );
	}

	public function test_unsupported_granularity_returns_error(): void {
		$error = ( new WooSource() )->get_series( array( 'metric' => 'revenue', 'granularity' => 'month' ) );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'immens_forecast_unsupported_params', $error->get_error_code() );
	}

	public function test_stats_path_builds_contiguous_daily_series(): void {
		$this->seed_stats_rows();

		$payload = ( new WooSource() )->get_series(
			array(
				'metric'        => 'orders',
				'granularity'   => 'day',
				'history_start' => '2026-11-01',
				'horizon'       => 2,
			)
		);

		self::assertSame( array( '2026-11-01', '2026-11-02', '2026-11-03' ), $payload['dates'] );
		self::assertSame( array( 1.0, 1.0, 0.0 ), $payload['values'] );
		self::assertSame( array( '2026-11-04', '2026-11-05' ), $payload['future_dates'] );
	}

	public function test_stats_lookup_escapes_like_wildcards(): void {
		$this->seed_stats_rows();
		$escaped = array();
		Functions\when( 'esc_like' )->alias(
			static function ( $text ) use ( &$escaped ) {
				$escaped[] = (string) $text;
				return addcslashes( (string) $text, '_%\\' );
			}
		);

		$payload = ( new WooSource() )->get_series(
			array(
				'metric'        => 'orders',
				'granularity'   => 'day',
				'history_start' => '2026-11-01',
				'horizon'       => 1,
			)
		);

		self::assertSame( array( 'wp_wc_order_stats' ), $escaped );
		self::assertSame( array( '2026-11-01', '2026-11-02', '2026-11-03' ), $payload['dates'] );
	}

	public function test_week_granularity_buckets_from_iso_monday(): void {
		$this->seed_stats_rows();

		$payload = ( new WooSource() )->get_series(
			array(
				'metric'        => 'items',
				'granularity'   => 'week',
				'history_start' => '2026-11-01',
				'horizon'       => 1,
			)
		);

		// 2026-11-01 is a Sunday: its ISO week starts Monday 2026-10-26.
		self::assertSame( array( '2026-10-26', '2026-11-02' ), $payload['dates'] );
		self::assertSame( array( 2.0, 0.0 ), $payload['values'] );
		self::assertSame( array( '2026-11-09' ), $payload['future_dates'] );
	}

	public function test_revenue_subs_tax_when_prices_include_tax(): void {
		$this->seed_stats_rows();
		Functions\when( 'wc_prices_include_tax' )->justReturn( true );

		$payload = ( new WooSource() )->get_series(
			array(
				'metric'        => 'revenue',
				'granularity'   => 'day',
				'history_start' => '2026-11-01',
				'horizon'       => 1,
			)
		);

		// Day 1: 100 - 22. Day 2: (50 - 30) - (11 - 6.6).
		self::assertSame( array( 78.0, 15.6, 0.0 ), $payload['values'] );
	}

	public function test_fallback_path_uses_wc_get_orders(): void {
		Functions\when( 'wc_get_orders' )->alias(
			self::source_orders(
				array( new FakeWooOrder( '2026-11-01 09:00:00', 100.0, 22.0, array( 2 ) ) ),
				array( new FakeWooOrder( '2026-11-02 10:00:00', 30.0, 6.6, array( 1 ) ) )
			)
		);

		$source = new WooSource();
		$args   = array(
			'granularity'   => 'day',
			'history_start' => '2026-11-01',
			'horizon'       => 1,
		);

		$revenue = $source->get_series( array_merge( $args, array( 'metric' => 'revenue' ) ) );
		self::assertSame( array( '2026-11-01', '2026-11-02', '2026-11-03' ), $revenue['dates'] );
		// The refund subtracts on its own date; prices exclude tax by default here.
		self::assertSame( array( 100.0, -30.0, 0.0 ), $revenue['values'] );

		$items = $source->get_series( array_merge( $args, array( 'metric' => 'items' ) ) );
		self::assertSame( array( 2.0, -1.0, 0.0 ), $items['values'] );
	}

	public function test_both_paths_agree_on_the_same_fixture(): void {
		$this->seed_stats_rows();
		Functions\when( 'wc_prices_include_tax' )->justReturn( true );

		$args = array(
			'metric'        => 'revenue',
			'granularity'   => 'day',
			'history_start' => '2026-11-01',
			'horizon'       => 1,
		);

		$from_stats = ( new WooSource() )->get_series( $args );

		// Same numbers through the orders API: drop the stats table.
		$this->wpdb->tables = array();
		Functions\when( 'wc_get_orders' )->alias(
			self::source_orders(
				array(
					new FakeWooOrder( '2026-11-01 09:00:00', 100.0, 22.0, array( 2 ) ),
					new FakeWooOrder( '2026-11-02 08:00:00', 50.0, 11.0, array( 1 ) ),
				),
				array( new FakeWooOrder( '2026-11-02 10:00:00', 30.0, 6.6, array( 1 ) ) )
			)
		);

		$from_orders = ( new WooSource() )->get_series( $args );

		self::assertSame( $from_stats['dates'], $from_orders['dates'] );
		// The stats path subtracts tax from aggregates, the orders path from
		// per-order nets: the results agree to ~15 significant digits but are
		// not bit-identical, so compare with a delta far tighter than any real
		// semantic divergence (wrong date, status or refund handling).
		self::assertEqualsWithDelta( $from_stats['values'], $from_orders['values'], 1e-9 );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_series_errors_when_woocommerce_missing(): void {
		$error = ( new WooSource() )->get_series( array( 'metric' => 'revenue', 'granularity' => 'day' ) );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'immens_forecast_source_unavailable', $error->get_error_code() );
	}
}
