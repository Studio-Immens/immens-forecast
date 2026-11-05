<?php
/**
 * In-memory $wpdb replacement for unit tests.
 *
 * @package ImmensForecast\Tests\Support
 */

namespace ImmensForecast\Tests\Support;

/**
 * Minimal $wpdb double: insert/update/delete/get_* with an internal store.
 */
final class FakeWpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Last insert id.
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * Tables reported as existing by SHOW TABLES queries.
	 *
	 * @var array
	 */
	public $tables = array();

	/**
	 * Stored rows keyed by id.
	 *
	 * @var array
	 */
	public $rows = array();

	/**
	 * Auto-increment counter.
	 *
	 * @var int
	 */
	private $auto = 0;

	/**
	 * Charset collate used by create_table().
	 *
	 * @return string
	 */
	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/**
	 * Substitute %i/%d/%s/%f placeholders (enough for the plugin queries).
	 *
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		$index = 0;
		return (string) preg_replace_callback(
			'/%[idsf]/',
			static function ( $match ) use ( &$index, $args ) {
				$value = isset( $args[ $index ] ) ? $args[ $index ] : '';
				++$index;
				return ( '%d' === $match[0] ) ? (string) (int) $value : (string) $value;
			},
			(string) $query
		);
	}

	/**
	 * Insert a row.
	 *
	 * @param string    $table  Table name.
	 * @param array     $data   Row data.
	 * @param array|null $format Formats.
	 * @return int
	 */
	public function insert( $table, $data, $format = null ) {
		$id               = ++$this->auto;
		$data['id']       = $id;
		$this->rows[ $id ] = $data;
		$this->insert_id   = $id;
		return 1;
	}

	/**
	 * Update a row by id.
	 *
	 * @param string     $table        Table name.
	 * @param array      $data         Row data.
	 * @param array      $where        WHERE clause.
	 * @param array|null $format       Formats.
	 * @param array|null $where_format Where formats.
	 * @return int|false
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$id = isset( $where['id'] ) ? (int) $where['id'] : 0;
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
		return 1;
	}

	/**
	 * Delete a row by id.
	 *
	 * @param string     $table        Table name.
	 * @param array      $where        WHERE clause.
	 * @param array|null $where_format Where formats.
	 * @return int|false
	 */
	public function delete( $table, $where, $where_format = null ) {
		$id = isset( $where['id'] ) ? (int) $where['id'] : 0;
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		unset( $this->rows[ $id ] );
		return 1;
	}

	/**
	 * COUNT(*) queries return the row count; SHOW TABLES queries return the
	 * matching table from the `tables` list; otherwise null.
	 *
	 * @param string $query Query.
	 * @return mixed
	 */
	public function get_var( $query ) {
		$query = (string) $query;
		// MySQL LIKE treats `\_` and `\%` as literals: mirror that for pattern matches.
		$query = str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $query );
		if ( false !== stripos( $query, 'show tables' ) ) {
			foreach ( $this->tables as $table ) {
				if ( false !== stripos( $query, $table ) ) {
					return $table;
				}
			}
			return null;
		}
		return ( false !== stripos( $query, 'count(' ) ) ? count( $this->rows ) : null;
	}

	/**
	 * Return all rows as objects.
	 *
	 * @param string $query Query.
	 * @return array
	 */
	public function get_results( $query ) {
		return array_map(
			static function ( $row ) {
				return (object) $row;
			},
			array_values( $this->rows )
		);
	}

	/**
	 * Return one row as an object, matched by `id = N` in the query.
	 *
	 * @param string $query Query.
	 * @return object|null
	 */
	public function get_row( $query ) {
		if ( ! preg_match( '/id\s*=\s*(\d+)/i', (string) $query, $match ) ) {
			return null;
		}
		$id = (int) $match[1];
		return isset( $this->rows[ $id ] ) ? (object) $this->rows[ $id ] : null;
	}
}
