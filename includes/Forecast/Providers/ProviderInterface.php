<?php
/**
 * Forecast provider contract.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * A forecasting backend (local baseline, remote endpoint, Pro models).
 *
 * Capability keys: `max_horizon` (int), `max_series` (int), `max_context`
 * (int), `supports_multi_series` (bool), `supports_known_covariates` (bool),
 * `supports_past_covariates` (bool), `supports_categorical` (bool),
 * `quantile_levels` (float[]), `requires_remote` (bool).
 */
interface ProviderInterface {

	/**
	 * Stable provider id (e.g. `baseline`, `endpoint`).
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Short description shown in settings.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Declared capabilities (see interface docblock).
	 *
	 * @return array
	 */
	public function get_capabilities(): array;

	/**
	 * Whether the provider is usable right now.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Forecast one or more series.
	 *
	 * Input series follow the normalized format: `id`, `values`
	 * (`float|null[]`), `past_covariates`, `known_covariates` (length
	 * `n + horizon`) and optional categorical covariates.
	 *
	 * @param array $series  Normalized series list.
	 * @param int   $horizon Steps to forecast.
	 * @param array $options Provider options (model, quantiles, timeout…).
	 * @return array|\WP_Error
	 */
	public function forecast( array $series, int $horizon, array $options );

	/**
	 * Connectivity/health probe for "Test connection".
	 *
	 * @return array|\WP_Error
	 */
	public function health();
}
