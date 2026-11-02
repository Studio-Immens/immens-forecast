<?php
/**
 * Settings screen.
 *
 * @var array $args Template arguments.
 * @package ImmensForecast
 */

defined( 'ABSPATH' ) || exit;

$settings = isset( $args['settings'] ) ? (array) $args['settings'] : array();
?>
<div class="wrap immens-forecast-wrap">
	<h1><?php echo esc_html__( 'Forecast settings', 'immens-forecast' ); ?></h1>

	<?php if ( ! empty( $args['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'immens-forecast' ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! empty( $args['imported'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Dataset imported.', 'immens-forecast' ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! empty( $args['import_failed'] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'The CSV could not be imported. Check the format and try again.', 'immens-forecast' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="immens_forecast_save_settings">
		<?php wp_nonce_field( 'immens_forecast_save_settings' ); ?>

		<h2><?php echo esc_html__( 'Forecast', 'immens-forecast' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="immens-forecast-setting-provider"><?php echo esc_html__( 'Default provider', 'immens-forecast' ); ?></label></th>
				<td>
					<select id="immens-forecast-setting-provider" name="provider">
						<?php foreach ( (array) $args['providers'] as $provider_id => $provider ) : ?>
							<option value="<?php echo esc_attr( (string) $provider_id ); ?>" <?php selected( (string) $settings['provider'] === (string) $provider_id ); ?>>
								<?php echo esc_html( (string) $provider['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="immens-forecast-setting-horizon"><?php echo esc_html__( 'Default horizon (periods)', 'immens-forecast' ); ?></label></th>
				<td><input id="immens-forecast-setting-horizon" name="horizon" type="number" min="1" max="180" value="<?php echo esc_attr( (string) $settings['horizon'] ); ?>"></td>
			</tr>
		</table>

		<h2><?php echo esc_html__( 'External endpoint', 'immens-forecast' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Any service exposing the timestral-api /forecast, /health and /models routes.', 'immens-forecast' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="immens-forecast-setting-url"><?php echo esc_html__( 'Endpoint URL', 'immens-forecast' ); ?></label></th>
				<td><input id="immens-forecast-setting-url" name="endpoint_url" type="url" class="regular-text" value="<?php echo esc_attr( (string) $settings['endpoint_url'] ); ?>" placeholder="https://forecast.example.com"></td>
			</tr>
			<tr>
				<th scope="row"><label for="immens-forecast-setting-token"><?php echo esc_html__( 'Bearer token', 'immens-forecast' ); ?></label></th>
				<td>
					<input id="immens-forecast-setting-token" name="endpoint_token" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $args['token_set'] ) ? '********' : '' ); ?>">
					<p class="description"><?php echo esc_html__( 'Leave empty to keep the stored token.', 'immens-forecast' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="immens-forecast-setting-model"><?php echo esc_html__( 'Default model', 'immens-forecast' ); ?></label></th>
				<td><input id="immens-forecast-setting-model" name="endpoint_model" type="text" value="<?php echo esc_attr( (string) $settings['endpoint_model'] ); ?>" placeholder="209m"></td>
			</tr>
			<tr>
				<th scope="row"><label for="immens-forecast-setting-timeout"><?php echo esc_html__( 'Timeout (seconds)', 'immens-forecast' ); ?></label></th>
				<td><input id="immens-forecast-setting-timeout" name="endpoint_timeout" type="number" min="5" max="300" value="<?php echo esc_attr( (string) $settings['endpoint_timeout'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Connection check', 'immens-forecast' ); ?></th>
				<td>
					<button type="button" class="button" id="immens-forecast-test"><?php echo esc_html__( 'Test connection', 'immens-forecast' ); ?></button>
					<button type="button" class="button" id="immens-forecast-models"><?php echo esc_html__( 'Load models', 'immens-forecast' ); ?></button>
					<span id="immens-forecast-endpoint-status" class="immens-forecast-status" aria-live="polite"></span>
				</td>
			</tr>
		</table>

		<h2><?php echo esc_html__( 'Data', 'immens-forecast' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Uninstall', 'immens-forecast' ); ?></th>
				<td>
					<input type="hidden" name="delete_data_on_uninstall" value="0">
					<label for="immens-forecast-setting-delete">
						<input id="immens-forecast-setting-delete" name="delete_data_on_uninstall" type="checkbox" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?>>
						<?php echo esc_html__( 'Delete all forecast data when the plugin is uninstalled.', 'immens-forecast' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save settings', 'immens-forecast' ) ); ?>
	</form>

	<h2><?php echo esc_html__( 'Manual data (CSV)', 'immens-forecast' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="immens-forecast-import">
		<input type="hidden" name="action" value="immens_forecast_import_csv">
		<?php wp_nonce_field( 'immens_forecast_import_csv' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="immens-forecast-dataset-name"><?php echo esc_html__( 'Name', 'immens-forecast' ); ?></label></th>
				<td><input id="immens-forecast-dataset-name" name="dataset_name" type="text" class="regular-text" required></td>
			</tr>
			<tr>
				<th scope="row"><label for="immens-forecast-dataset-granularity"><?php echo esc_html__( 'Granularity', 'immens-forecast' ); ?></label></th>
				<td>
					<select id="immens-forecast-dataset-granularity" name="dataset_granularity">
						<option value="day"><?php echo esc_html__( 'Daily', 'immens-forecast' ); ?></option>
						<option value="week"><?php echo esc_html__( 'Weekly', 'immens-forecast' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="immens-forecast-dataset-csv"><?php echo esc_html__( 'CSV', 'immens-forecast' ); ?></label></th>
				<td>
					<textarea id="immens-forecast-dataset-csv" name="dataset_csv" rows="6" class="large-text code" placeholder="2026-01-01,120&#10;2026-01-02,131"></textarea>
					<p class="description"><?php echo esc_html__( 'One dated value per line, or one value per line (dates are inferred backwards).', 'immens-forecast' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Import dataset', 'immens-forecast' ) ); ?>
	</form>

	<?php if ( ! empty( $args['datasets'] ) ) : ?>
		<h3><?php echo esc_html__( 'Stored datasets', 'immens-forecast' ); ?></h3>
		<table class="widefat striped immens-forecast-datasets">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Name', 'immens-forecast' ); ?></th>
					<th><?php echo esc_html__( 'Granularity', 'immens-forecast' ); ?></th>
					<th><?php echo esc_html__( 'Actions', 'immens-forecast' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( (array) $args['datasets'] as $dataset_id => $dataset ) : ?>
					<tr>
						<td><?php echo esc_html( $dataset['name'] ); ?></td>
						<td><?php echo esc_html( $dataset['granularity'] ); ?></td>
						<td>
							<a class="submitdelete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=immens_forecast_delete_dataset&dataset=' . (int) $dataset_id ), 'immens_forecast_delete_dataset' ) ); ?>">
								<?php echo esc_html__( 'Delete', 'immens-forecast' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
