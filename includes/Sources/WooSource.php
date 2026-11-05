<?php
/**
 * WooCommerce orders source.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates WooCommerce orders into a daily or weekly metric series.
 *
 * Prefers the WooCommerce analytics stats table (single aggregated query)
 * and falls back to wc_get_orders() in paginated monthly chunks.
 */
final class WooSource implements SourceInterface {

	/**
	 * Metrics exposed by this source.
	 */
	const METRICS = array( 'revenue', 'orders', 'items' );

	/**
	 * Order statuses that count as sales (stats-table format).
	 */
	const ORDER_STATUSES = array( 'wc-completed', 'wc-processing' );

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'woo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'WooCommerce orders', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Revenue, orders and items sold from your store.', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return function_exists( 'wc_get_orders' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_metrics(): array {
		return array(
			'revenue' => array(
				'label'         => __( 'Net revenue', 'immens-forecast' ),
				'granularities' => array( 'day', 'week' ),
			),
			'orders'  => array(
				'label'         => __( 'Orders', 'immens-forecast' ),
				'granularities' => array( 'day', 'week' ),
			),
			'items'   => array(
				'label'         => __( 'Items sold', 'immens-forecast' ),
				'granularities' => array( 'day', 'week' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $args Args (metric, granularity, history_start, horizon).
	 * @return array|\WP_Error
	 */
	public function get_series( array $args ) {
		$metric = isset( $args['metric'] ) ? strtolower( (string) $args['metric'] ) : '';
		if ( ! in_array( $metric, self::METRICS, true ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Unknown WooCommerce metric.', 'immens-forecast' ) );
		}

		$granularity = isset( $args['granularity'] ) ? strtolower( (string) $args['granularity'] ) : '';
		if ( ! in_array( $granularity, array( 'day', 'week' ), true ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Unsupported WooCommerce granularity.', 'immens-forecast' ) );
		}

		$horizon = isset( $args['horizon'] ) ? (int) $args['horizon'] : 30;
		$horizon = max( 1, min( 180, $horizon ) );

		$today         = current_time( 'Y-m-d' );
		$history_start = isset( $args['history_start'] ) ? (string) $args['history_start'] : '';
		if ( ! self::is_valid_date( $history_start ) || strcmp( $history_start, $today ) > 0 ) {
			$history_start = gmdate( 'Y-m-d', strtotime( $today . ' -364 days' ) );
		}

		$daily = $this->stats_daily( $history_start, $today );
		if ( null === $daily ) {
			if ( ! function_exists( 'wc_get_orders' ) ) {
				return new \WP_Error( 'immens_forecast_source_unavailable', __( 'WooCommerce is not available.', 'immens-forecast' ) );
			}
			$daily = $this->orders_daily( $history_start, $today );
		}

		$points = self::to_points( $daily, $granularity, $history_start, $today, $metric );

		return SeriesBuilder::build( $points, $granularity, $horizon, $metric );
	}

	/**
	 * Daily aggregates from the WooCommerce analytics stats table.
	 *
	 * Refunds are separate rows with a non-zero parent_id; their signs
	 * differed across WooCommerce versions, so they are normalized with
	 * ABS() in SQL and subtracted in PHP.
	 *
	 * @param string $start First day, site timezone (Y-m-d).
	 * @param string $end   Last day, site timezone (Y-m-d).
	 * @return array|null Day => aggregates, or null when the table is missing.
	 */
	private function stats_daily( string $start, string $end ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_order_stats';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', esc_like( $table ) ) ) !== $table ) {
			return null;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(date_created) AS bucket,
					SUM(CASE WHEN parent_id = 0 THEN 1 ELSE 0 END) AS orders_count,
					SUM(CASE WHEN parent_id = 0 THEN total_sales ELSE 0 END) AS gross,
					SUM(CASE WHEN parent_id = 0 THEN tax_total ELSE 0 END) AS tax,
					SUM(CASE WHEN parent_id = 0 THEN num_items_sold ELSE 0 END) AS items,
					SUM(CASE WHEN parent_id <> 0 THEN ABS(total_sales) ELSE 0 END) AS refund_gross,
					SUM(CASE WHEN parent_id <> 0 THEN ABS(tax_total) ELSE 0 END) AS refund_tax,
					SUM(CASE WHEN parent_id <> 0 THEN ABS(num_items_sold) ELSE 0 END) AS refund_items
				FROM %i
				WHERE date_created >= %s AND date_created <= %s AND status IN (%s, %s)
				GROUP BY DATE(date_created)',
				$table,
				$start . ' 00:00:00',
				$end . ' 23:59:59',
				self::ORDER_STATUSES[0],
				self::ORDER_STATUSES[1]
			)
		);

		if ( ! is_array( $rows ) ) {
			return null;
		}

		$prices_include_tax = function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax();

		$daily = array();
		foreach ( $rows as $row ) {
			$revenue = (float) $row->gross - (float) $row->refund_gross;
			if ( $prices_include_tax ) {
				$revenue -= (float) $row->tax - (float) $row->refund_tax;
			}
			$daily[ (string) $row->bucket ] = array(
				'orders'  => (int) $row->orders_count,
				'revenue' => $revenue,
				'items'   => (int) $row->items - (int) $row->refund_items,
			);
		}

		return $daily;
	}

	/**
	 * Daily aggregates via wc_get_orders(), month by month.
	 *
	 * Refunds are bucketed by their own date, matching the stats table.
	 *
	 * @param string $start First day (Y-m-d).
	 * @param string $end   Last day (Y-m-d).
	 * @return array Day => aggregates.
	 */
	private function orders_daily( string $start, string $end ): array {
		$daily = array();

		foreach ( self::month_chunks( $start, $end ) as $chunk ) {
			foreach ( self::fetch_orders( 'shop_order', $chunk[0], $chunk[1] ) as $order ) {
				$day = $order->get_date_created()->date( 'Y-m-d' );
				if ( ! isset( $daily[ $day ] ) ) {
					$daily[ $day ] = array(
						'orders'  => 0,
						'revenue' => 0.0,
						'items'   => 0,
					);
				}
				++$daily[ $day ]['orders'];
				$daily[ $day ]['revenue'] += self::net_total( $order );
				$daily[ $day ]['items']   += self::items_count( $order );
			}

			foreach ( self::fetch_orders( 'shop_order_refund', $chunk[0], $chunk[1] ) as $refund ) {
				$day = $refund->get_date_created()->date( 'Y-m-d' );
				if ( ! isset( $daily[ $day ] ) ) {
					$daily[ $day ] = array(
						'orders'  => 0,
						'revenue' => 0.0,
						'items'   => 0,
					);
				}
				$daily[ $day ]['revenue'] -= abs( self::net_total( $refund ) );
				$daily[ $day ]['items']   -= self::items_count( $refund );
			}
		}

		return $daily;
	}

	/**
	 * All orders of a type inside an inclusive day range, paginated.
	 *
	 * @param string $type  shop_order or shop_order_refund.
	 * @param string $start First day (Y-m-d).
	 * @param string $end   Last day (Y-m-d).
	 * @return array Order objects.
	 */
	private static function fetch_orders( string $type, string $start, string $end ): array {
		$orders = array();
		$page   = 1;

		do {
			$query_args = array(
				'type'         => $type,
				'date_created' => $start . '...' . $end,
				'limit'        => 100,
				'page'         => $page,
				'paginate'     => true,
			);
			if ( 'shop_order' === $type ) {
				$query_args['status'] = self::ORDER_STATUSES;
			}

			$result = wc_get_orders( $query_args );
			$batch  = isset( $result->orders ) ? (array) $result->orders : array();
			$pages  = isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;

			$orders = array_merge( $orders, $batch );
			++$page;
		} while ( $page <= $pages );

		return $orders;
	}

	/**
	 * Order total net of tax when the store displays prices tax-inclusive.
	 *
	 * @param object $order WC_Order-like object.
	 * @return float
	 */
	private static function net_total( $order ): float {
		$total = (float) $order->get_total();
		if ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ) {
			$total -= (float) $order->get_total_tax();
		}
		return $total;
	}

	/**
	 * Sum of line-item quantities in an order.
	 *
	 * @param object $order WC_Order-like object.
	 * @return int
	 */
	private static function items_count( $order ): int {
		$items = 0;
		foreach ( $order->get_items() as $item ) {
			$items += (int) $item->get_quantity();
		}
		return $items;
	}

	/**
	 * Turn daily aggregates into a contiguous series for one metric.
	 *
	 * Missing buckets are filled with 0.0: for counters and sums,
	 * "no rows" is data, not a gap. Week buckets start on ISO Mondays.
	 *
	 * @param array  $daily       Day => aggregates.
	 * @param string $granularity day or week.
	 * @param string $start       First day (Y-m-d).
	 * @param string $end         Last day (Y-m-d).
	 * @param string $metric      Metric id.
	 * @return array Points ( d, v ).
	 */
	private static function to_points( array $daily, string $granularity, string $start, string $end, string $metric ): array {
		if ( 'week' === $granularity ) {
			$start = self::week_start( $start );
			$end   = self::week_start( $end );
		}

		$totals = array();
		foreach ( $daily as $day => $values ) {
			$bucket = ( 'week' === $granularity ) ? self::week_start( (string) $day ) : (string) $day;
			if ( strcmp( $bucket, $start ) < 0 || strcmp( $bucket, $end ) > 0 ) {
				continue;
			}
			if ( ! isset( $totals[ $bucket ] ) ) {
				$totals[ $bucket ] = 0.0;
			}
			$totals[ $bucket ] += $values[ $metric ];
		}

		$interval = ( 'week' === $granularity ) ? '+7 days' : '+1 day';
		$points   = array();
		$day      = $start;
		while ( strcmp( $day, $end ) <= 0 ) {
			$points[] = array(
				'd' => $day,
				'v' => isset( $totals[ $day ] ) ? (float) $totals[ $day ] : 0.0,
			);
			$day      = gmdate( 'Y-m-d', strtotime( $day . ' ' . $interval ) );
		}

		return $points;
	}

	/**
	 * Monday of the ISO week containing a date.
	 *
	 * @param string $date Y-m-d.
	 * @return string Y-m-d.
	 */
	private static function week_start( string $date ): string {
		$timestamp = strtotime( $date . ' 12:00:00' );
		$dow       = (int) gmdate( 'N', $timestamp ); // 1 (Monday) … 7 (Sunday).
		return gmdate( 'Y-m-d', $timestamp - ( $dow - 1 ) * 86400 );
	}

	/**
	 * Inclusive monthly chunks covering a day range.
	 *
	 * @param string $start First day (Y-m-d).
	 * @param string $end   Last day (Y-m-d).
	 * @return array List of array( first, last ) day pairs.
	 */
	private static function month_chunks( string $start, string $end ): array {
		$chunks  = array();
		$current = $start;
		while ( strcmp( $current, $end ) <= 0 ) {
			$month_end = gmdate( 'Y-m-t', strtotime( $current . ' 12:00:00' ) );
			$chunk_end = ( strcmp( $month_end, $end ) > 0 ) ? $end : $month_end;
			$chunks[]  = array( $current, $chunk_end );
			$current   = gmdate( 'Y-m-d', strtotime( $chunk_end . ' +1 day' ) );
		}
		return $chunks;
	}

	/**
	 * Strict Y-m-d validation.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private static function is_valid_date( string $date ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			return false;
		}
		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}
}
