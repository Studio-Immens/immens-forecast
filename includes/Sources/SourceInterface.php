<?php
/**
 * Contract every data source must implement.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * A source turns a metric + window into a normalized series payload.
 */
interface SourceInterface {

	/**
	 * Unique id, e.g. "woo", "manual".
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Short description.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Whether the source can be used right now.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Metrics exposed by the source.
	 *
	 * @return array metric_id => array( 'label' => string, 'granularities' => string[] )
	 */
	public function get_metrics(): array;

	/**
	 * Build the series payload.
	 *
	 * @param array $args metric, granularity, history_start, horizon, source-specific keys.
	 * @return array|\WP_Error
	 */
	public function get_series( array $args );
}
