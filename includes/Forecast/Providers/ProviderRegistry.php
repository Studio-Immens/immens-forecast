<?php
/**
 * Registry of forecast providers.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Holds providers keyed by id, built from the `immens_forecast_providers` filter.
 */
final class ProviderRegistry {

	/**
	 * Providers keyed by id.
	 *
	 * @var ProviderInterface[]
	 */
	private $providers = array();

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface[] $providers Initial providers.
	 */
	public function __construct( array $providers = array() ) {
		foreach ( $providers as $provider ) {
			$this->add( $provider );
		}
	}

	/**
	 * Register a provider (last one wins on id collision).
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return void
	 */
	public function add( ProviderInterface $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Get a provider by id.
	 *
	 * @param string $id Provider id.
	 * @return ProviderInterface|null
	 */
	public function get( string $id ): ?ProviderInterface {
		return isset( $this->providers[ $id ] ) ? $this->providers[ $id ] : null;
	}

	/**
	 * Whether a provider id is registered.
	 *
	 * @param string $id Provider id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->providers[ $id ] );
	}

	/**
	 * All providers keyed by id.
	 *
	 * @return ProviderInterface[]
	 */
	public function all(): array {
		return $this->providers;
	}

	/**
	 * Registered provider ids.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->providers );
	}

	/**
	 * Only providers that are currently available.
	 *
	 * @return ProviderInterface[]
	 */
	public function available(): array {
		$out = array();
		foreach ( $this->providers as $id => $provider ) {
			if ( $provider->is_available() ) {
				$out[ $id ] = $provider;
			}
		}
		return $out;
	}

	/**
	 * Build a registry from the public filter (free + Pro + third parties).
	 *
	 * @return ProviderRegistry
	 */
	public static function build(): ProviderRegistry {
		$registry = new self();
		$filtered = apply_filters( 'immens_forecast_providers', array() );
		foreach ( (array) $filtered as $provider ) {
			if ( $provider instanceof ProviderInterface ) {
				$registry->add( $provider );
			}
		}
		return $registry;
	}
}
