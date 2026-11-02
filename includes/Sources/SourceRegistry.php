<?php
/**
 * Registry of available data sources.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Holds sources keyed by id, built from the `immens_forecast_sources` filter.
 */
final class SourceRegistry {

	/**
	 * Sources keyed by id.
	 *
	 * @var SourceInterface[]
	 */
	private $sources = array();

	/**
	 * Constructor.
	 *
	 * @param SourceInterface[] $sources Initial sources.
	 */
	public function __construct( array $sources = array() ) {
		foreach ( $sources as $source ) {
			$this->add( $source );
		}
	}

	/**
	 * Register a source (last one wins on id collision).
	 *
	 * @param SourceInterface $source Source.
	 * @return void
	 */
	public function add( SourceInterface $source ): void {
		$this->sources[ $source->get_id() ] = $source;
	}

	/**
	 * Get a source by id.
	 *
	 * @param string $id Source id.
	 * @return SourceInterface|null
	 */
	public function get( string $id ): ?SourceInterface {
		return isset( $this->sources[ $id ] ) ? $this->sources[ $id ] : null;
	}

	/**
	 * Whether a source id is registered.
	 *
	 * @param string $id Source id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->sources[ $id ] );
	}

	/**
	 * All sources keyed by id.
	 *
	 * @return SourceInterface[]
	 */
	public function all(): array {
		return $this->sources;
	}

	/**
	 * Registered source ids.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->sources );
	}

	/**
	 * Only sources that are currently available.
	 *
	 * @return SourceInterface[]
	 */
	public function available(): array {
		$out = array();
		foreach ( $this->sources as $id => $source ) {
			if ( $source->is_available() ) {
				$out[ $id ] = $source;
			}
		}
		return $out;
	}

	/**
	 * Build a registry from the public filter (free + Pro + third parties).
	 *
	 * @return SourceRegistry
	 */
	public static function build(): SourceRegistry {
		$registry = new self();
		$filtered = apply_filters( 'immens_forecast_sources', array() );
		foreach ( (array) $filtered as $source ) {
			if ( $source instanceof SourceInterface ) {
				$registry->add( $source );
			}
		}
		return $registry;
	}
}
