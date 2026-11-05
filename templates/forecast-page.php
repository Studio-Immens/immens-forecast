<?php
/**
 * Forecast screen.
 *
 * @var array $args Template arguments.
 * @package ImmensForecast
 */

defined( 'ABSPATH' ) || exit;

$defaults = isset( $args['defaults'] ) ? (array) $args['defaults'] : array();
$meta     = array(
	'sources'   => isset( $args['sources'] ) ? (array) $args['sources'] : array(),
	'providers' => isset( $args['providers'] ) ? (array) $args['providers'] : array(),
	'defaults'  => $defaults,
);
?>
<div class="wrap immens-forecast-wrap">
	<h1><?php echo esc_html__( 'Forecast', 'immens-forecast' ); ?></h1>

	<?php if ( ! empty( $args['imported'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Dataset imported.', 'immens-forecast' ); ?></p></div>
	<?php endif; ?>

	<?php if ( empty( $args['endpoint_configured'] ) ) : ?>
		<div class="notice notice-info"><p>
			<?php echo esc_html__( 'No external endpoint is configured: forecasts run locally with the baseline model.', 'immens-forecast' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=immens-forecast-settings' ) ); ?>"><?php echo esc_html__( 'Configure an endpoint', 'immens-forecast' ); ?></a>
		</p></div>
	<?php endif; ?>

	<script type="application/json" id="immens-forecast-meta"><?php echo wp_json_encode( $meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON data block. ?></script>

	<div class="immens-forecast-layout">
		<form id="immens-forecast-form" class="immens-forecast-form">
			<div class="immens-forecast-field">
				<label for="immens-forecast-source"><?php echo esc_html__( 'Source', 'immens-forecast' ); ?></label>
				<select id="immens-forecast-source" name="source">
					<?php foreach ( (array) $args['sources'] as $source_id => $source ) : ?>
						<option value="<?php echo esc_attr( (string) $source_id ); ?>"><?php echo esc_html( (string) $source['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="immens-forecast-field">
				<label for="immens-forecast-metric"><?php echo esc_html__( 'Metric', 'immens-forecast' ); ?></label>
				<select id="immens-forecast-metric" name="metric"></select>
			</div>
			<div class="immens-forecast-field" id="immens-forecast-manual-field" hidden>
				<label for="immens-forecast-dataset"><?php echo esc_html__( 'Dataset', 'immens-forecast' ); ?></label>
				<select id="immens-forecast-dataset" name="dataset_id">
					<option value="0"><?php echo esc_html__( 'Select a dataset', 'immens-forecast' ); ?></option>
					<?php foreach ( (array) $args['datasets'] as $dataset_id => $dataset ) : ?>
						<option value="<?php echo esc_attr( (string) $dataset_id ); ?>"><?php echo esc_html( $dataset['name'] . ' (' . $dataset['granularity'] . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="immens-forecast-field">
				<label for="immens-forecast-granularity"><?php echo esc_html__( 'Granularity', 'immens-forecast' ); ?></label>
				<select id="immens-forecast-granularity" name="granularity">
					<option value="day"><?php echo esc_html__( 'Daily', 'immens-forecast' ); ?></option>
					<option value="week"><?php echo esc_html__( 'Weekly', 'immens-forecast' ); ?></option>
				</select>
			</div>
			<div class="immens-forecast-field">
				<label for="immens-forecast-history"><?php echo esc_html__( 'History (days)', 'immens-forecast' ); ?></label>
				<input id="immens-forecast-history" name="history_days" type="number" min="7" max="3650" value="<?php echo esc_attr( (string) $defaults['history_days'] ); ?>">
			</div>
			<div class="immens-forecast-field">
				<label for="immens-forecast-horizon"><?php echo esc_html__( 'Horizon (periods)', 'immens-forecast' ); ?></label>
				<input id="immens-forecast-horizon" name="horizon" type="number" min="1" max="180" value="<?php echo esc_attr( (string) $defaults['horizon'] ); ?>">
			</div>
			<div class="immens-forecast-field">
				<label for="immens-forecast-provider"><?php echo esc_html__( 'Model', 'immens-forecast' ); ?></label>
				<select id="immens-forecast-provider" name="provider">
					<?php foreach ( (array) $args['providers'] as $provider_id => $provider ) : ?>
						<option value="<?php echo esc_attr( (string) $provider_id ); ?>" <?php selected( (string) $defaults['provider'] === (string) $provider_id ); ?>>
							<?php echo esc_html( (string) $provider['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="immens-forecast-field">
				<label for="immens-forecast-model"><?php echo esc_html__( 'Endpoint model', 'immens-forecast' ); ?></label>
				<input id="immens-forecast-model" name="model" type="text" placeholder="209m">
			</div>
			<label class="immens-forecast-checkbox">
				<input id="immens-forecast-refresh" name="refresh" type="checkbox" value="1">
				<?php echo esc_html__( 'Ignore the cache', 'immens-forecast' ); ?>
			</label>
			<button type="submit" class="button button-primary"><?php echo esc_html__( 'Run forecast', 'immens-forecast' ); ?></button>
		</form>

		<div class="immens-forecast-results">
			<div id="immens-forecast-error" class="notice notice-error" hidden><p></p></div>
			<div id="immens-forecast-fallback" class="notice notice-warning" hidden><p></p></div>
			<div class="immens-forecast-kpis" id="immens-forecast-kpis"></div>
			<div class="immens-forecast-chart-box">
				<canvas id="immens-forecast-chart" width="720" height="320"></canvas>
			</div>
			<div id="immens-forecast-table" class="immens-forecast-table"></div>
		</div>
	</div>
</div>
