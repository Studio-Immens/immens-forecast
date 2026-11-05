<?php
/**
 * Stub provider for registry tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast\Providers
 */

namespace ImmensForecast\Tests\Unit\Forecast\Providers;

use ImmensForecast\Forecast\Providers\ProviderInterface;

/**
 * Stub provider.
 */
final class StubProvider implements ProviderInterface {

	/**
	 * Provider id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Availability flag.
	 *
	 * @var bool
	 */
	private $available;

	/**
	 * Constructor.
	 *
	 * @param string $id        Provider id.
	 * @param bool   $available Availability.
	 */
	public function __construct( string $id = 'stub', bool $available = true ) {
		$this->id        = $id;
		$this->available = $available;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return 'Stub provider';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return 'Stub provider for tests';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capabilities(): array {
		return array(
			'max_horizon'               => 1024,
			'max_series'                => 1,
			'max_context'               => 32768,
			'supports_multi_series'     => false,
			'supports_known_covariates' => true,
			'supports_past_covariates'  => true,
			'supports_categorical'      => false,
			'quantile_levels'           => array( 0.1, 0.5, 0.9 ),
			'requires_remote'           => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $series  Series.
	 * @param int   $horizon Horizon.
	 * @param array $options Options.
	 * @return array
	 */
	public function forecast( array $series, int $horizon, array $options ) {
		return array(
			'series'  => $series,
			'horizon' => $horizon,
			'options' => $options,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function health() {
		return array( 'status' => 'ok' );
	}
}
