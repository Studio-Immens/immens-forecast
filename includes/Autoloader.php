<?php
/**
 * Lightweight autoloader for the ImmensForecast namespace.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast;

defined( 'ABSPATH' ) || exit;

/**
 * Maps ImmensForecast\Foo\Bar to <base_dir>/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @param string $prefix   Namespace prefix, e.g. "ImmensForecast\".
	 * @param string $base_dir Classes root absolute path.
	 * @return void
	 */
	public static function register( string $prefix, string $base_dir ): void {
		spl_autoload_register(
			static function ( $class_name ) use ( $prefix, $base_dir ) {
				if ( 0 !== strpos( $class_name, $prefix ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( $prefix ) );
				$file     = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR
					. str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		);
	}
}
