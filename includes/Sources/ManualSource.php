<?php
/**
 * Source backed by user datasets (CSV / pasted values).
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Sources;

use ImmensForecast\Storage\Datasets;

defined( 'ABSPATH' ) || exit;

/**
 * Manual dataset source.
 */
final class ManualSource implements SourceInterface {

	const METRIC = 'value';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'manual';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Manual dataset', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'A dataset you saved from CSV or pasted values.', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return Datasets::count() > 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_metrics(): array {
		return array(
			self::METRIC => array(
				'label'         => __( 'Dataset value', 'immens-forecast' ),
				'granularities' => Datasets::GRANULARITIES,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $args Args (dataset_id, granularity, history_start, horizon).
	 * @return array|\WP_Error
	 */
	public function get_series( array $args ) {
		$dataset_id = isset( $args['dataset_id'] ) ? absint( $args['dataset_id'] ) : 0;
		$dataset    = Datasets::get( $dataset_id );
		if ( null === $dataset ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Dataset not found.', 'immens-forecast' ) );
		}

		$granularity = isset( $args['granularity'] ) ? strtolower( (string) $args['granularity'] ) : '';
		if ( ! in_array( $granularity, Datasets::GRANULARITIES, true ) ) {
			$granularity = $dataset['granularity'];
		}

		$history_start = isset( $args['history_start'] ) ? (string) $args['history_start'] : '';
		$horizon       = isset( $args['horizon'] ) ? (int) $args['horizon'] : 30;
		$horizon       = max( 1, min( 180, $horizon ) );

		$points = array();
		foreach ( $dataset['points'] as $point ) {
			if ( '' !== $history_start && strcmp( $point['d'], $history_start ) < 0 ) {
				continue;
			}
			$points[] = $point;
		}

		return SeriesBuilder::build( $points, $granularity, $horizon, self::METRIC );
	}
}
