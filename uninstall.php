<?php
/**
 * Uninstall routine.
 *
 * @package ImmensForecast
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$immens_forecast_option   = 'immens_forecast_settings';
$immens_forecast_settings = get_option( $immens_forecast_option, array() );
$immens_forecast_settings = is_array( $immens_forecast_settings ) ? $immens_forecast_settings : array();

foreach ( array( '_transient_immens_forecast_', '_transient_timeout_immens_forecast_' ) as $immens_forecast_prefix ) {
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name.
			$wpdb->esc_like( $immens_forecast_prefix ) . '%'
		)
	);
}

if ( empty( $immens_forecast_settings['delete_data_on_uninstall'] ) ) {
	return;
}

$immens_forecast_table = $wpdb->prefix . 'immens_forecast_datasets';

$wpdb->query( "DROP TABLE IF EXISTS {$immens_forecast_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall cleanup with a prefixed table name.

delete_option( $immens_forecast_option );
delete_option( 'immens_forecast_db_version' );
delete_option( 'immens_forecast_import_error' );
