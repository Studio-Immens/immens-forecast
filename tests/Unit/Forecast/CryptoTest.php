<?php
/**
 * Crypto round-trip and tamper tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast
 */

namespace ImmensForecast\Tests\Unit\Forecast;

use ImmensForecast\Forecast\Crypto;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Token encryption tests.
 */
final class CryptoTest extends TestCase {

	public function test_openssl_round_trip(): void {
		$crypto = new Crypto( array( 'openssl' ) );
		$cipher = $crypto->encrypt( 'my-secret-token' );

		self::assertStringStartsWith( 'a1:', $cipher );
		self::assertSame( 'my-secret-token', $crypto->decrypt( $cipher ) );
	}

	public function test_sodium_round_trip_when_available(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			self::markTestSkipped( 'sodium not available' );
		}
		$crypto = new Crypto( array( 'sodium' ) );
		$cipher = $crypto->encrypt( 'my-secret-token' );

		self::assertStringStartsWith( 's1:', $cipher );
		self::assertSame( 'my-secret-token', $crypto->decrypt( $cipher ) );
	}

	public function test_round_trips_are_not_deterministic(): void {
		$crypto = new Crypto( array( 'openssl' ) );
		self::assertNotSame( $crypto->encrypt( 'x' ), $crypto->encrypt( 'x' ) );
	}

	public function test_tampered_ciphertext_returns_null(): void {
		$crypto   = new Crypto( array( 'openssl' ) );
		$cipher   = $crypto->encrypt( 'my-secret-token' );
		$raw      = base64_decode( substr( $cipher, 3 ) );
		$raw[20]  = chr( ord( $raw[20] ) ^ 0xFF );
		$tampered = 'a1:' . base64_encode( $raw );

		self::assertNull( $crypto->decrypt( $tampered ) );
	}

	public function test_garbage_inputs_return_null(): void {
		$crypto = new Crypto( array( 'openssl' ) );
		self::assertNull( $crypto->decrypt( '' ) );
		self::assertNull( $crypto->decrypt( 'nope' ) );
		self::assertNull( $crypto->decrypt( 'x9:abcdef' ) );
		self::assertNull( $crypto->decrypt( 'a1:!!!not-base64!!!' ) );
	}

	public function test_empty_string_round_trip(): void {
		$crypto = new Crypto( array( 'openssl' ) );
		self::assertSame( '', $crypto->decrypt( $crypto->encrypt( '' ) ) );
	}
}
