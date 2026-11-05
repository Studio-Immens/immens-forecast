<?php
/**
 * Datasets custom table: schema, CRUD and validation.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Storage layer for user datasets (CSV / pasted series).
 */
final class Datasets {

	const TABLE             = 'immens_forecast_datasets';
	const DB_VERSION        = '1.0';
	const DB_VERSION_OPTION = 'immens_forecast_db_version';
	const MAX_DATASETS      = 20;
	const MAX_POINTS        = 5000;
	const NAME_MAX_LENGTH   = 190;
	const GRANULARITIES     = array( 'day', 'week' );

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * CREATE TABLE statement (dbDelta-compatible).
	 *
	 * @param string $charset_collate Charset/collate clause.
	 * @return string
	 */
	public static function schema_sql( string $charset_collate ): string {
		$table = self::table_name();
		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			granularity VARCHAR(10) NOT NULL DEFAULT 'day',
			points LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id)
		) {$charset_collate};";
	}

	/**
	 * Create/upgrade the table and store the schema version.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( ! file_exists( $upgrade ) ) {
				return;
			}
			require_once $upgrade;
		}

		$charset = ( is_object( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) ) ? $wpdb->get_charset_collate() : '';
		dbDelta( self::schema_sql( $charset ) );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Number of datasets.
	 *
	 * @return int
	 */
	public static function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table_name() ) );
	}

	/**
	 * All datasets without their points.
	 *
	 * @return array
	 */
	public static function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, name, granularity, points, created_at, updated_at FROM %i ORDER BY name ASC', self::table_name() )
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::hydrate( $row, false );
		}
		return $out;
	}

	/**
	 * One dataset including decoded points, or null.
	 *
	 * @param int $id Dataset id.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_name(), $id ) );
		return $row ? self::hydrate( $row, true ) : null;
	}

	/**
	 * Create a dataset.
	 *
	 * @param string $name        Dataset name.
	 * @param string $granularity day|week.
	 * @param array  $points      Point list.
	 * @return int|\WP_Error New id or error.
	 */
	public static function create( string $name, string $granularity, array $points ) {
		if ( self::count() >= self::MAX_DATASETS ) {
			return new \WP_Error(
				'immens_forecast_too_many_datasets',
				sprintf(
					/* translators: %d: maximum number of datasets. */
					__( 'You have reached the maximum number of datasets (%d).', 'immens-forecast' ),
					self::MAX_DATASETS
				)
			);
		}

		$invalid = self::validate_points( $points );
		if ( $invalid instanceof \WP_Error ) {
			return $invalid;
		}

		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = array(
			'name'        => self::sanitize_name( $name ),
			'granularity' => self::sanitize_granularity( $granularity ),
			'points'      => self::encode_points( $points ),
			'created_at'  => $now,
			'updated_at'  => $now,
		);

		$result = $wpdb->insert( self::table_name(), $data, array( '%s', '%s', '%s', '%s', '%s' ) );
		if ( false === $result ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'The dataset could not be saved.', 'immens-forecast' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a dataset.
	 *
	 * @param int   $id     Dataset id.
	 * @param array $fields Fields: name, granularity, points.
	 * @return true|\WP_Error
	 */
	public static function update( int $id, array $fields ) {
		if ( null === self::get( $id ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Dataset not found.', 'immens-forecast' ) );
		}

		$data = array();
		if ( isset( $fields['name'] ) ) {
			$data['name'] = self::sanitize_name( (string) $fields['name'] );
		}
		if ( isset( $fields['granularity'] ) ) {
			$data['granularity'] = self::sanitize_granularity( (string) $fields['granularity'] );
		}
		if ( isset( $fields['points'] ) && is_array( $fields['points'] ) ) {
			$invalid = self::validate_points( $fields['points'] );
			if ( $invalid instanceof \WP_Error ) {
				return $invalid;
			}
			$data['points'] = self::encode_points( $fields['points'] );
		}
		if ( empty( $data ) ) {
			return true;
		}

		$data['updated_at'] = current_time( 'mysql' );

		global $wpdb;
		$result = $wpdb->update( self::table_name(), $data, array( 'id' => $id ), null, array( '%d' ) );
		if ( false === $result ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'The dataset could not be updated.', 'immens-forecast' ) );
		}
		return true;
	}

	/**
	 * Delete a dataset.
	 *
	 * @param int $id Dataset id.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Validate a decoded point list.
	 *
	 * @param array $points Point list.
	 * @return \WP_Error|null
	 */
	public static function validate_points( array $points ): ?\WP_Error {
		if ( empty( $points ) ) {
			return new \WP_Error( 'immens_forecast_csv_empty', __( 'The dataset is empty.', 'immens-forecast' ) );
		}
		if ( count( $points ) > self::MAX_POINTS ) {
			return new \WP_Error(
				'immens_forecast_too_many_points',
				sprintf(
					/* translators: %d: maximum number of points. */
					__( 'Too many points: the maximum is %d.', 'immens-forecast' ),
					self::MAX_POINTS
				)
			);
		}

		$index = 0;
		foreach ( $points as $point ) {
			++$index;
			if ( ! is_array( $point ) || ! isset( $point['d'] ) ) {
				/* translators: %d: row number. */
				return new \WP_Error( 'immens_forecast_csv_invalid_value', sprintf( __( 'Invalid row %d.', 'immens-forecast' ), $index ) );
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $point['d'] ) ) {
				/* translators: %d: row number. */
				return new \WP_Error( 'immens_forecast_csv_invalid_date', sprintf( __( 'Invalid date on row %d.', 'immens-forecast' ), $index ) );
			}
			if ( isset( $point['v'] ) && null !== $point['v'] && ! is_numeric( $point['v'] ) ) {
				/* translators: %d: row number. */
				return new \WP_Error( 'immens_forecast_csv_invalid_value', sprintf( __( 'Invalid value on row %d.', 'immens-forecast' ), $index ) );
			}
		}
		return null;
	}

	/**
	 * Encode points to the stored JSON shape.
	 *
	 * @param array $points Point list.
	 * @return string
	 */
	public static function encode_points( array $points ): string {
		$out = array();
		foreach ( $points as $point ) {
			if ( ! is_array( $point ) || ! isset( $point['d'] ) ) {
				continue;
			}
			$out[] = array(
				'd' => (string) $point['d'],
				'v' => ( isset( $point['v'] ) && is_numeric( $point['v'] ) ) ? (float) $point['v'] : null,
			);
		}
		$json = wp_json_encode( $out );
		return ( false === $json ) ? '[]' : $json;
	}

	/**
	 * Decode stored JSON into a point list.
	 *
	 * @param string $json Stored JSON.
	 * @return array
	 */
	public static function decode_points( string $json ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$out = array();
		foreach ( $data as $point ) {
			if ( ! is_array( $point ) || ! isset( $point['d'] ) ) {
				continue;
			}
			$out[] = array(
				'd' => (string) $point['d'],
				'v' => ( isset( $point['v'] ) && is_numeric( $point['v'] ) ) ? (float) $point['v'] : null,
			);
		}
		return $out;
	}

	/**
	 * Map a DB row to the API shape.
	 *
	 * @param object $row         DB row.
	 * @param bool   $with_points Include decoded points.
	 * @return array
	 */
	private static function hydrate( $row, bool $with_points ): array {
		$raw    = isset( $row->points ) ? (string) $row->points : '';
		$points = self::decode_points( $raw );

		$data = array(
			'id'          => (int) $row->id,
			'name'        => (string) $row->name,
			'granularity' => (string) $row->granularity,
			'point_count' => count( $points ),
			'created_at'  => (string) $row->created_at,
			'updated_at'  => (string) $row->updated_at,
		);
		if ( $with_points ) {
			$data['points'] = $points;
		}
		return $data;
	}

	/**
	 * Sanitize a dataset name.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function sanitize_name( string $name ): string {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			$name = 'Dataset';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::NAME_MAX_LENGTH ) : substr( $name, 0, self::NAME_MAX_LENGTH );
	}

	/**
	 * Sanitize a granularity value.
	 *
	 * @param string $granularity Raw value.
	 * @return string
	 */
	private static function sanitize_granularity( string $granularity ): string {
		$granularity = strtolower( trim( $granularity ) );
		return in_array( $granularity, self::GRANULARITIES, true ) ? $granularity : 'day';
	}
}
