<?php
/**
 * Minimal WP_Error polyfill for unit tests.
 *
 * @package ImmensForecast\Tests
 */

if ( class_exists( 'WP_Error' ) ) {
	return;
}

// phpcs:disable
class WP_Error {

	private $errors     = array();
	private $error_data = array();

	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' !== $code ) {
			$this->errors[ $code ][] = (string) $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}

	public function get_error_code() {
		$codes = array_keys( $this->errors );
		return $codes ? $codes[0] : '';
	}

	public function get_error_message( $code = '' ) {
		$code = $code ? $code : $this->get_error_code();
		return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
	}

	public function get_error_data( $code = '' ) {
		$code = $code ? $code : $this->get_error_code();
		return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
	}

	public function has_errors() {
		return ! empty( $this->errors );
	}
}
// phpcs:enable
