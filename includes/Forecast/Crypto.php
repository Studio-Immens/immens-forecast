<?php
/**
 * Symmetric encryption for the endpoint token (encryption at rest).
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts/decrypts short strings using sodium or OpenSSL fallback.
 */
final class Crypto {

	/**
	 * Cipher methods in priority order.
	 *
	 * @var string[]
	 */
	private $methods;

	/**
	 * Constructor.
	 *
	 * @param string[]|null $methods Force methods (tests). Null = auto-detect.
	 */
	public function __construct( ?array $methods = null ) {
		if ( null === $methods ) {
			$methods = array();
			if ( function_exists( 'sodium_crypto_secretbox' ) ) {
				$methods[] = 'sodium';
			}
			if ( function_exists( 'openssl_encrypt' ) ) {
				$methods[] = 'openssl';
			}
		}
		$this->methods = $methods;
	}

	/**
	 * Whether at least one cipher backend is available.
	 *
	 * @return bool
	 */
	public function has_method(): bool {
		return ! empty( $this->methods );
	}

	/**
	 * Encrypt a plaintext string, prefixed with the method tag.
	 *
	 * @param string $plain Plaintext.
	 * @return string
	 * @throws \RuntimeException When no backend is available.
	 */
	public function encrypt( string $plain ): string {
		foreach ( $this->methods as $method ) {
			if ( 'sodium' === $method ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes ciphertext, not obfuscation.
				return 's1:' . base64_encode( $this->sodium_encrypt( $plain ) );
			}
			if ( 'openssl' === $method ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes ciphertext, not obfuscation.
				return 'a1:' . base64_encode( $this->openssl_encrypt( $plain ) );
			}
		}
		throw new \RuntimeException( 'No encryption backend available.' );
	}

	/**
	 * Decrypt a ciphertext produced by encrypt(). Null on any failure.
	 *
	 * @param string $cipher Ciphertext with method tag.
	 * @return string|null
	 */
	public function decrypt( string $cipher ): ?string {
		if ( strlen( $cipher ) < 4 || ':' !== $cipher[2] ) {
			return null;
		}
		$tag = substr( $cipher, 0, 2 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes ciphertext, not obfuscation.
		$raw = base64_decode( substr( $cipher, 3 ), true );
		if ( false === $raw ) {
			return null;
		}
		try {
			if ( 's1' === $tag ) {
				return $this->sodium_decrypt( $raw );
			}
			if ( 'a1' === $tag ) {
				return $this->openssl_decrypt( $raw );
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Derive the encryption key from the site salts.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function key(): string {
		$auth_key  = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
		$auth_salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : '';
		return hash( 'sha256', $auth_key . '|' . $auth_salt . '|immens-forecast', true );
	}

	/**
	 * Sodium encrypt (nonce|box).
	 *
	 * @param string $plain Plaintext.
	 * @return string
	 */
	private function sodium_encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() );
	}

	/**
	 * Sodium decrypt.
	 *
	 * @param string $raw Raw bytes.
	 * @return string|null
	 */
	private function sodium_decrypt( string $raw ): ?string {
		$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( strlen( $raw ) <= $nonce_len ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, $nonce_len ),
			substr( $raw, 0, $nonce_len ),
			self::key()
		);
		return ( false === $plain ) ? null : $plain;
	}

	/**
	 * OpenSSL encrypt (iv|cipher|hmac).
	 *
	 * @param string $plain Plaintext.
	 * @return string
	 */
	private function openssl_encrypt( string $plain ): string {
		$key = self::key();
		$iv  = random_bytes( 16 );
		$enc = $iv . openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return $enc . hash_hmac( 'sha256', $enc, $key, true );
	}

	/**
	 * OpenSSL decrypt with HMAC verification.
	 *
	 * @param string $raw Raw bytes.
	 * @return string|null
	 */
	private function openssl_decrypt( string $raw ): ?string {
		if ( strlen( $raw ) < 49 ) {
			return null;
		}
		$enc = substr( $raw, 0, -32 );
		$mac = substr( $raw, -32 );
		$key = self::key();
		if ( ! hash_equals( hash_hmac( 'sha256', $enc, $key, true ), $mac ) ) {
			return null;
		}
		$iv    = substr( $enc, 0, 16 );
		$plain = openssl_decrypt( substr( $enc, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return ( false === $plain ) ? null : $plain;
	}
}
