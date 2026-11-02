# Immens Forecast — F1 (free plugin) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the complete F1 free WordPress plugin `immens-forecast` (GPL-2.0-or-later): offline baseline forecasting, BYO endpoint provider, WooCommerce + CSV datasets, admin UI with Chart.js, tests, w.org-ready packaging.

**Architecture:** Sources (data in) → Engine (orchestration, cache, fallback) → Providers (data out: local Baseline or remote Endpoint HTTP contract). Everything is registered through public filters so the future Pro plugin extends the free plugin without touching its code.

**Tech Stack:** PHP 7.4+ (dev runs on 8.2), WordPress 6.4+ (dev site: WP 7.1.2 + WooCommerce 10.9.4), PHPUnit 9.6 + Brain\Monkey + Mockery, PHPCS (WordPress-Core/Extra + PHPCompatibilityWP), Chart.js 3.9.1 (bundled), wp-cli (i18n), Playwright MCP (E2E).

**Spec:** `docs/superpowers/specs/2026-11-01-immens-forecast-design.md`

## Global Constraints

- **PHP 7.4 compatible code**: no union types, no constructor promotion, no `match`, no `str_contains`/`str_starts_with`, no nullsafe `?->`, no named arguments. Typed properties and `?type` nullable are OK.
- Prefixes: options/hooks `immens_forecast_`, namespace `ImmensForecast\`, constants `IMMENS_FORECAST_*`. Text domain `immens-forecast`. All PHP files start with `defined( 'ABSPATH' ) || exit;`.
- WordPress coding standards (tabs, Yoda conditions, spaces inside parens). Escape on output, sanitize on input, `$wpdb->prepare()` on all queries.
- Capability `manage_options` via filter `immens_forecast_capability`; AJAX nonce action `immens_forecast`, POST field `nonce`.
- Error codes (exact strings): `immens_forecast_insufficient_data`, `immens_forecast_provider_unavailable`, `immens_forecast_unsupported_params`, `immens_forecast_endpoint_unreachable`, `immens_forecast_endpoint_timeout`, `immens_forecast_endpoint_unauthorized`, `immens_forecast_endpoint_http`, `immens_forecast_endpoint_bad_response`, `immens_forecast_rate_limited`, `immens_forecast_locked`, `immens_forecast_csv_invalid_date`, `immens_forecast_csv_invalid_value`, `immens_forecast_csv_mixed_lines`, `immens_forecast_csv_empty`, `immens_forecast_too_many_points`, `immens_forecast_too_many_datasets`, `immens_forecast_source_unavailable`.
- Chart.js is bundled locally (no CDN). Quantile string keys always via `Math::format_level()` (`0.10 → "0.1"`, `0.05 → "0.05"`).
- **Windows toolchain (verified)**: no PHP/composer on PATH. Use the wrapper `tools\php-cli.cmd` (created in Task 1) backed by:
  - PHP: `C:\Users\mauro\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe`
  - Ext dir: `C:\Users\mauro\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\ext`
  - wp-cli: `C:\Users\mauro\wp-cli.phar` (already present). Composer: `tools\composer.phar` (downloaded in Task 1, gitignored).
- Run all commands from the repo root `C:\Users\mauro\Local Sites\local\app\public\wp-content\plugins\immens-forecast` (git repo initialized, `main`; spec committed as `137c995`).
- Local dev site for E2E: `http://localhost:10004`, wp-admin user `nat` / password `nat`. DB `127.0.0.1:10006`, user `root`, pass `root`, db `local`, prefix `wp_`.
- Test commands (memorize):
  - Unit tests: `tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist`
  - PHPCS: `tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist`
  - PHP lint one file: `tools\php-cli.cmd -l <file>`

---

### Task 1: Toolchain, repo scaffolding, plugin bootstrap

**Files:**
- Create: `tools/php-cli.ini`, `tools/php-cli.cmd`, `.gitattributes`, `.gitignore`, `.distignore`, `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist`, `LICENSE`, `immens-forecast.php`, `includes/Autoloader.php`, `includes/Plugin.php`, `tests/bootstrap.php`, `tests/stubs/class-wp-error.php`, `tests/unit/TestCase.php`, `tests/unit/PluginTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: constants `IMMENS_FORECAST_VERSION|FILE|PATH|URL`; `ImmensForecast\Autoloader::register( string $prefix, string $base_dir ): void`; `ImmensForecast\Plugin::instance(): Plugin`, `Plugin::boot(): void`, `Plugin::activate(): void`, `Plugin::deactivate(): void`, `Plugin::engine()` (returns null until Task 12 wires it).

- [ ] **Step 1: Create the CLI wrapper files**

`tools/php-cli.ini`:
```ini
; Local-only CLI php.ini for immens-forecast development (gitignored).
extension_dir="C:\Users\mauro\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\ext"
extension=openssl
extension=mbstring
extension=zip
extension=curl
extension=sodium
extension=fileinfo
extension=mysqli
extension=pdo_mysql
memory_limit=512M
```

`tools/php-cli.cmd`:
```bat
@echo off
"C:\Users\mauro\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe" -c "%~dp0php-cli.ini" %*
```

- [ ] **Step 2: Verify the wrapper and extensions**

Run:
```powershell
tools\php-cli.cmd -v
tools\php-cli.cmd -r "var_dump(extension_loaded('openssl'), extension_loaded('mbstring'), extension_loaded('zip'), extension_loaded('curl'), extension_loaded('sodium'), extension_loaded('fileinfo'), extension_loaded('mysqli'));"
```
Expected: PHP 8.2.29 banner + seven `true`. If `mysqli`/`pdo_mysql` DLLs are missing in the ext dir, drop those two lines from the ini and note it (only needed for the E2E DB tooling).

- [ ] **Step 3: Download composer.phar and verify**

```powershell
curl.exe -sSL https://getcomposer.org/download/latest-stable/composer.phar -o tools\composer.phar
tools\php-cli.cmd tools\composer.phar --version
```
Expected: `Composer version 2.x.y ...`.

- [ ] **Step 4: Write scaffold config files**

`.gitattributes`:
```
* text=auto eol=lf
*.cmd text eol=crlf
*.bat text eol=crlf
```

`.gitignore`:
```
/vendor/
/tools/
/node_modules/
/build/
.phpunit.result.cache
```

`.distignore`:
```
.git
.superpowers
.github
.gitignore
.gitattributes
.distignore
composer.json
composer.lock
phpcs.xml.dist
phpunit.xml.dist
tests
docs
tools
vendor
node_modules
build
CHANGELOG.md
```

`composer.json`:
```json
{
    "name": "studio-immens/immens-forecast",
    "description": "Sales & time series forecasting for WordPress: offline baseline model or any external forecasting endpoint.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": { "php": ">=7.4" },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "brain/monkey": "^6.1",
        "mockery/mockery": "^1.6",
        "squizlabs/php_codesniffer": "^3.10",
        "wp-coding-standards/wpcs": "^3.1",
        "phpcompatibility/phpcompatibility-wp": "^2.1",
        "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
    },
    "config": {
        "platform": { "php": "7.4.33" },
        "allow-plugins": { "dealerdirect/phpcodesniffer-composer-installer": true }
    },
    "autoload-dev": { "psr-4": { "ImmensForecast\\Tests\\": "tests/" } },
    "scripts": {
        "test": "phpunit --configuration phpunit.xml.dist",
        "lint": "phpcs --standard=phpcs.xml.dist"
    }
}
```

`phpcs.xml.dist`:
```xml
<?xml version="1.0"?>
<ruleset name="Immens Forecast">
	<description>Coding standards for Immens Forecast.</description>
	<file>./immens-forecast.php</file>
	<file>./includes</file>
	<file>./templates</file>
	<file>./uninstall.php</file>
	<exclude-pattern>*/vendor/*</exclude-pattern>
	<exclude-pattern>*/tests/*</exclude-pattern>
	<arg name="extensions" value="php"/>
	<arg name="colors"/>
	<arg value="p"/>
	<rule ref="WordPress-Core"/>
	<rule ref="WordPress-Extra"/>
	<rule ref="PHPCompatibilityWP"/>
	<config name="testVersion" value="7.4-"/>
	<config name="minimum_supported_wp_version" value="6.4"/>
	<rule ref="WordPress.WP.I18n">
		<properties>
			<property name="text_domain" type="array" value="immens-forecast"/>
		</properties>
	</rule>
</ruleset>
```

`phpunit.xml.dist`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
		bootstrap="tests/bootstrap.php"
		colors="true"
		failOnWarning="true">
	<testsuites>
		<testsuite name="unit">
			<directory>tests/unit</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

- [ ] **Step 5: Fetch the GPL license text**

```powershell
curl.exe -sSL https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt -o LICENSE
(Get-Item LICENSE).Length
```
Expected: file size > 15000 bytes.

- [ ] **Step 6: Write bootstrap, autoloader and Plugin class**

`immens-forecast.php`:
```php
<?php
/**
 * Plugin Name:       Immens Forecast – Sales & Time Series Forecasting
 * Plugin URI:        https://studioimmens.com/plugins/immens-forecast
 * Description:       Forecast sales and time series (WooCommerce or custom datasets) with an offline baseline model or any external forecasting endpoint you connect.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Studio Immens
 * Author URI:        https://studioimmens.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       immens-forecast
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   10.9
 *
 * @package ImmensForecast
 */

defined( 'ABSPATH' ) || exit;

define( 'IMMENS_FORECAST_VERSION', '0.1.0' );
define( 'IMMENS_FORECAST_FILE', __FILE__ );
define( 'IMMENS_FORECAST_PATH', plugin_dir_path( __FILE__ ) );
define( 'IMMENS_FORECAST_URL', plugin_dir_url( __FILE__ ) );

require_once IMMENS_FORECAST_PATH . 'includes/Autoloader.php';
ImmensForecast\Autoloader::register( 'ImmensForecast\\', IMMENS_FORECAST_PATH . 'includes' );

register_activation_hook( __FILE__, array( 'ImmensForecast\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ImmensForecast\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		ImmensForecast\Plugin::instance()->boot();
	}
);
```

`includes/Autoloader.php`:
```php
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
			static function ( $class ) use ( $prefix, $base_dir ) {
				if ( 0 !== strpos( $class, $prefix ) ) {
					return;
				}
				$relative = substr( $class, strlen( $prefix ) );
				$file     = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR
					. str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		);
	}
}
```

`includes/Plugin.php`:
```php
<?php
/**
 * Main plugin class.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton orchestration class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Forecast engine (wired in boot(), from Task 12).
	 *
	 * @var Forecast\Engine|null
	 */
	private $engine = null;

	/**
	 * Get the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor: work happens in boot().
	 */
	private function __construct() {}

	/**
	 * Boot the plugin on plugins_loaded.
	 *
	 * @return void
	 */
	public function boot(): void {
		load_plugin_textdomain( 'immens-forecast', false, dirname( plugin_basename( IMMENS_FORECAST_FILE ) ) . '/languages' );
	}

	/**
	 * Forecast engine accessor (full wiring lands in Task 12).
	 *
	 * @return Forecast\Engine|null
	 */
	public function engine() {
		return $this->engine;
	}

	/**
	 * Activation: default options + datasets table.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( null === get_option( Admin\Settings::OPTION, null ) ) {
			add_option( Admin\Settings::OPTION, Admin\Settings::defaults() );
		}
		Storage\Datasets::create_table();
	}

	/**
	 * Deactivation: nothing to do (no cron in the free plugin).
	 *
	 * @return void
	 */
	public static function deactivate(): void {}
}
```

- [ ] **Step 7: Write the test harness**

`tests/bootstrap.php`:
```php
<?php
/**
 * PHPUnit bootstrap for unit tests (no WordPress runtime).
 *
 * @package ImmensForecast\Tests
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! class_exists( 'WP_Error' ) ) {
	require_once __DIR__ . '/stubs/class-wp-error.php';
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Minimal is_wp_error for unit tests.
	 *
	 * @param mixed $thing Value to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/fake-wp/' );
defined( 'IMMENS_FORECAST_VERSION' ) || define( 'IMMENS_FORECAST_VERSION', '0.1.0-test' );
defined( 'IMMENS_FORECAST_FILE' ) || define( 'IMMENS_FORECAST_FILE', dirname( __DIR__ ) . '/immens-forecast.php' );
defined( 'IMMENS_FORECAST_PATH' ) || define( 'IMMENS_FORECAST_PATH', dirname( __DIR__ ) . '/' );
defined( 'IMMENS_FORECAST_URL' ) || define( 'IMMENS_FORECAST_URL', 'https://example.test/wp-content/plugins/immens-forecast/' );
defined( 'AUTH_KEY' ) || define( 'AUTH_KEY', 'test-auth-key' );
defined( 'AUTH_SALT' ) || define( 'AUTH_SALT', 'test-auth-salt' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );

require_once IMMENS_FORECAST_PATH . 'includes/Autoloader.php';
\ImmensForecast\Autoloader::register( 'ImmensForecast\\', IMMENS_FORECAST_PATH . 'includes' );
```

`tests/stubs/class-wp-error.php`:
```php
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
```

`tests/unit/TestCase.php`:
```php
<?php
/**
 * Base test case with Brain Monkey set up.
 *
 * @package ImmensForecast\Tests\Unit
 */

namespace ImmensForecast\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Brain Monkey base test case.
 */
abstract class TestCase extends PhpUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
```

`tests/unit/PluginTest.php`:
```php
<?php
/**
 * Bootstrap smoke tests.
 *
 * @package ImmensForecast\Tests\Unit
 */

namespace ImmensForecast\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Verify constants, autoloader and singleton.
 */
final class PluginTest extends TestCase {

	public function test_constants_are_defined(): void {
		self::assertSame( '0.1.0-test', IMMENS_FORECAST_VERSION );
		self::assertNotEmpty( IMMENS_FORECAST_PATH );
	}

	public function test_singleton_identity(): void {
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );

		$one = \ImmensForecast\Plugin::instance();
		$two = \ImmensForecast\Plugin::instance();
		self::assertSame( $one, $two );
	}

	public function test_autoloader_is_registered(): void {
		self::assertTrue( class_exists( 'ImmensForecast\\Plugin' ) );
	}
}
```

- [ ] **Step 8: Install dev dependencies**

```powershell
tools\php-cli.cmd tools\composer.phar install --no-interaction
```
Expected: `vendor/` created without blocking errors. If `phpcompatibility/phpcompatibility-wp` cannot resolve on platform 7.4, remove that one package from `composer.json` and re-run (keep everything else).

- [ ] **Step 9: Run the unit tests**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist
```
Expected: `OK (3 tests, 3 assertions)`.

- [ ] **Step 10: Run PHPCS**

```powershell
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
```
Expected: `0 errors`. Fix reported issues before committing.

- [ ] **Step 11: Commit**

```powershell
git add -A
git commit -m "chore: scaffold plugin bootstrap, test harness and coding standards"
```

---

### Task 2: Crypto (token encryption at rest)

**Files:**
- Create: `includes/Forecast/Crypto.php`, `tests/unit/Forecast/CryptoTest.php`

**Interfaces:**
- Consumes: `AUTH_KEY`, `AUTH_SALT` constants.
- Produces: `ImmensForecast\Forecast\Crypto::__construct( ?array $methods = null )`; `encrypt( string $plain ): string` (tags `s1:` sodium / `a1:` openssl); `decrypt( string $cipher ): ?string` (null on any failure); `has_method(): bool`.

- [ ] **Step 1: Write the failing tests**

`tests/unit/Forecast/CryptoTest.php`:
```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter CryptoTest
```
Expected: FAIL — `Class "ImmensForecast\Forecast\Crypto" not found`.

- [ ] **Step 3: Implement Crypto**

`includes/Forecast/Crypto.php`:
```php
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
				return 's1:' . base64_encode( $this->sodium_encrypt( $plain ) );
			}
			if ( 'openssl' === $method ) {
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
```

- [ ] **Step 4: Run tests to verify they pass**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter CryptoTest
```
Expected: `OK (6 tests)` (sodium test may skip).

- [ ] **Step 5: PHPCS + commit**

```powershell
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(forecast): add Crypto for endpoint token encryption at rest"
```

### Task 3: Math and Series helpers (pure, no WordPress)

**Files:**
- Create: `includes/Forecast/Math.php`, `includes/Forecast/Series.php`, `tests/unit/Forecast/MathTest.php`, `tests/unit/Forecast/SeriesTest.php`

**Interfaces:**
- Consumes: nothing (pure static helpers).
- Produces:
  - `ImmensForecast\Forecast\Math::finite( array ): float[]`, `median( array ): float`, `mean( array ): float`, `mad( array, ?float ): float`, `quantile( array, float ): float` (linear interpolation on unsorted input), `clamp( float, float, float ): float`, `format_level( float ): string` (`0.10 => "0.1"`), `round( array, int ): array`
  - `ImmensForecast\Forecast\Series::MIN_POINTS = 16`, `MIN_FINITE = 4`; `normalize( array ): array`, `values( array ): array`, `finite_count( array ): int`, `is_non_negative( array ): bool`, `hash( array ): string`, `insufficient_error( array ): ?\WP_Error`

- [ ] **Step 1: Write the failing Math tests**

`tests/unit/Forecast/MathTest.php`:
```php
<?php
/**
 * Math helper tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast
 */

namespace ImmensForecast\Tests\Unit\Forecast;

use ImmensForecast\Forecast\Math;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Median, MAD, quantile, formatting.
 */
final class MathTest extends TestCase {

	public function test_median_odd_and_even(): void {
		self::assertSame( 2.0, Math::median( array( 3, 1, 2 ) ) );
		self::assertSame( 2.5, Math::median( array( 4, 1, 3, 2 ) ) );
	}

	public function test_finite_ignores_null_and_nan_and_strings(): void {
		self::assertSame( array( 1.0, 2.0 ), Math::finite( array( 1, null, 2, 'x', NAN ) ) );
		self::assertSame( 0.0, Math::median( array( null, 'a' ) ) );
	}

	public function test_quantile_linear_interpolation(): void {
		$values = array( 1, 2, 3, 4, 5 );
		self::assertSame( 1.0, Math::quantile( $values, 0.0 ) );
		self::assertSame( 1.4, round( Math::quantile( $values, 0.1 ), 4 ) );
		self::assertSame( 3.0, Math::quantile( $values, 0.5 ) );
		self::assertSame( 5.0, Math::quantile( $values, 1.0 ) );
	}

	public function test_mad_around_median(): void {
		self::assertSame( 1.0, Math::mad( array( 1, 2, 3, 4, 5 ) ) );
	}

	public function test_format_level_matches_output_contract(): void {
		self::assertSame( '0.1', Math::format_level( 0.1 ) );
		self::assertSame( '0.5', Math::format_level( 0.5 ) );
		self::assertSame( '0.9', Math::format_level( 0.9 ) );
		self::assertSame( '0.05', Math::format_level( 0.05 ) );
		self::assertSame( '0.01', Math::format_level( 0.01 ) );
		self::assertSame( '0.99', Math::format_level( 0.99 ) );
	}

	public function test_clamp_and_round(): void {
		self::assertSame( 5.0, Math::clamp( 1.0, 5.0, 10.0 ) );
		self::assertSame( 10.0, Math::clamp( 99.0, 5.0, 10.0 ) );
		self::assertSame( array( 1.23, null ), Math::round( array( 1.234, 'x' ), 2 ) );
	}
}
```

- [ ] **Step 2: Run Math tests to verify they fail**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter MathTest
```
Expected: FAIL — `Class "ImmensForecast\Forecast\Math" not found`.

- [ ] **Step 3: Implement Math**

`includes/Forecast/Math.php`:
```php
<?php
/**
 * Pure numeric helpers (no WordPress dependency).
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast;

defined( 'ABSPATH' ) || exit;

/**
 * Statistical helpers shared by providers and the engine.
 */
final class Math {

	/**
	 * Keep only finite numeric values, cast to float.
	 *
	 * @param array $values Raw values.
	 * @return float[]
	 */
	public static function finite( array $values ): array {
		$out = array();
		foreach ( $values as $value ) {
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$float = (float) $value;
			if ( is_finite( $float ) ) {
				$out[] = $float;
			}
		}
		return $out;
	}

	/**
	 * Median of the finite values (0.0 when empty).
	 *
	 * @param array $values Values.
	 * @return float
	 */
	public static function median( array $values ): float {
		$values = self::finite( $values );
		$count  = count( $values );
		if ( 0 === $count ) {
			return 0.0;
		}
		sort( $values );
		$mid = intdiv( $count, 2 );
		if ( 0 === $count % 2 ) {
			return ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
		}
		return $values[ $mid ];
	}

	/**
	 * Arithmetic mean of the finite values.
	 *
	 * @param array $values Values.
	 * @return float
	 */
	public static function mean( array $values ): float {
		$values = self::finite( $values );
		$count  = count( $values );
		return ( 0 === $count ) ? 0.0 : array_sum( $values ) / $count;
	}

	/**
	 * Median absolute deviation.
	 *
	 * @param array      $values Values.
	 * @param float|null $median Pre-computed median (optional).
	 * @return float
	 */
	public static function mad( array $values, ?float $median = null ): float {
		$values = self::finite( $values );
		if ( empty( $values ) ) {
			return 0.0;
		}
		$median = ( null === $median ) ? self::median( $values ) : $median;
		$devs   = array();
		foreach ( $values as $value ) {
			$devs[] = abs( $value - $median );
		}
		return self::median( $devs );
	}

	/**
	 * Linear-interpolation quantile of an unsorted array.
	 *
	 * @param array $values Values.
	 * @param float $level  Level in [0,1].
	 * @return float
	 */
	public static function quantile( array $values, float $level ): float {
		$values = self::finite( $values );
		$count  = count( $values );
		if ( 0 === $count ) {
			return 0.0;
		}
		sort( $values );
		if ( 1 === $count ) {
			return $values[0];
		}
		$level = max( 0.0, min( 1.0, $level ) );
		$pos   = $level * ( $count - 1 );
		$low   = (int) floor( $pos );
		$high  = (int) ceil( $pos );
		if ( $low === $high ) {
			return $values[ $low ];
		}
		$fraction = $pos - $low;
		return $values[ $low ] + ( $values[ $high ] - $values[ $low ] ) * $fraction;
	}

	/**
	 * Clamp a value to an inclusive range.
	 *
	 * @param float $value Value.
	 * @param float $min   Minimum.
	 * @param float $max   Maximum.
	 * @return float
	 */
	public static function clamp( float $value, float $min, float $max ): float {
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Canonical quantile string key used in result arrays.
	 *
	 * @param float $level Level in [0,1].
	 * @return string
	 */
	public static function format_level( float $level ): string {
		$formatted = number_format( round( $level, 4 ), 4, '.', '' );
		$formatted = rtrim( rtrim( $formatted, '0' ), '.' );
		return ( '' === $formatted ) ? '0' : $formatted;
	}

	/**
	 * Round an array of values (non-numeric entries become null).
	 *
	 * @param array $values    Values.
	 * @param int   $precision Decimals.
	 * @return array
	 */
	public static function round( array $values, int $precision = 2 ): array {
		$out = array();
		foreach ( $values as $value ) {
			$out[] = is_numeric( $value ) ? round( (float) $value, $precision ) : null;
		}
		return $out;
	}
}
```

- [ ] **Step 4: Run Math tests to verify they pass**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter MathTest
```
Expected: `OK (6 tests)`.

- [ ] **Step 5: Write the failing Series tests**

`tests/unit/Forecast/SeriesTest.php`:
```php
<?php
/**
 * Series normalization and validation tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast
 */

namespace ImmensForecast\Tests\Unit\Forecast;

use Brain\Monkey\Functions;
use ImmensForecast\Forecast\Series;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Series helper tests.
 */
final class SeriesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->returnArg();
	}

	public function test_normalize_casts_values_and_nullables(): void {
		$series = Series::normalize(
			array(
				'id'     => 'revenue',
				'values' => array( '1.5', 2, null, 'x' ),
			)
		);

		self::assertSame( 'revenue', $series['id'] );
		self::assertSame( array( 1.5, 2.0, null, null ), $series['values'] );
		self::assertNull( $series['past_covariates'] );
		self::assertNull( $series['known_covariates'] );
	}

	public function test_default_id_when_missing(): void {
		self::assertSame( 'series', Series::normalize( array( 'values' => array( 1 ) ) )['id'] );
	}

	public function test_finite_count_and_non_negative(): void {
		$series = array( 'values' => array( 1, 2, null, 'x', 0 ) );
		self::assertSame( 3, Series::finite_count( $series ) );
		self::assertTrue( Series::is_non_negative( $series ) );
		self::assertFalse( Series::is_non_negative( array( 'values' => array( 1, -1 ) ) ) );
	}

	public function test_hash_is_stable_and_sensitive(): void {
		$a = array( 'values' => array( 1, 2, 3 ) );
		$b = array( 'values' => array( 1, 2, 3 ) );
		$c = array( 'values' => array( 1, 2, 4 ) );
		self::assertSame( Series::hash( $a ), Series::hash( $b ) );
		self::assertNotSame( Series::hash( $a ), Series::hash( $c ) );
	}

	public function test_insufficient_error_returns_null_when_enough(): void {
		self::assertNull( Series::insufficient_error( array( 'values' => array_fill( 0, 16, 5 ) ) ) );
	}

	public function test_insufficient_error_reports_counts(): void {
		$error = Series::insufficient_error( array( 'values' => array( 1, 2, 3 ) ) );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'immens_forecast_insufficient_data', $error->get_error_code() );
		self::assertSame( array( 'points' => 3, 'finite' => 3 ), $error->get_error_data( 'immens_forecast_insufficient_data' ) );
	}
}
```

- [ ] **Step 6: Implement Series**

`includes/Forecast/Series.php`:
```php
<?php
/**
 * Series normalization and minimum-history validation.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical series helpers.
 */
final class Series {

	const MIN_POINTS = 16;
	const MIN_FINITE = 4;

	/**
	 * Canonicalize a series array (float values, null for missing).
	 *
	 * @param array $series Raw series.
	 * @return array
	 */
	public static function normalize( array $series ): array {
		$values = self::raw_values( $series );
		$clean  = array();
		foreach ( $values as $value ) {
			$clean[] = is_numeric( $value ) ? (float) $value : null;
		}

		$covariates = array(
			'past_covariates',
			'known_covariates',
			'categorical_past_covariates',
			'categorical_known_covariates',
		);

		$normalized = array(
			'id'     => isset( $series['id'] ) ? (string) $series['id'] : 'series',
			'values' => $clean,
		);
		foreach ( $covariates as $key ) {
			$normalized[ $key ] = ( isset( $series[ $key ] ) && is_array( $series[ $key ] ) ) ? $series[ $key ] : null;
		}
		return $normalized;
	}

	/**
	 * Raw values array of a series (empty when missing).
	 *
	 * @param array $series Series.
	 * @return array
	 */
	public static function values( array $series ): array {
		return self::raw_values( $series );
	}

	/**
	 * Count of finite values.
	 *
	 * @param array $series Series.
	 * @return int
	 */
	public static function finite_count( array $series ): int {
		return count( Math::finite( self::raw_values( $series ) ) );
	}

	/**
	 * Whether the series has no negative finite values.
	 *
	 * @param array $series Series.
	 * @return bool
	 */
	public static function is_non_negative( array $series ): bool {
		foreach ( Math::finite( self::raw_values( $series ) ) as $value ) {
			if ( $value < 0 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Hash of the values, for cache-key invalidation.
	 *
	 * @param array $series Series.
	 * @return string
	 */
	public static function hash( array $series ): string {
		return md5( (string) wp_json_encode( self::raw_values( $series ) ) );
	}

	/**
	 * Validation error when the series has too little history, null otherwise.
	 *
	 * @param array $series Series.
	 * @return \WP_Error|null
	 */
	public static function insufficient_error( array $series ): ?\WP_Error {
		$points = count( self::raw_values( $series ) );
		$finite = self::finite_count( $series );
		if ( $points >= self::MIN_POINTS && $finite >= self::MIN_FINITE ) {
			return null;
		}
		$message = sprintf(
			/* translators: 1: minimum points, 2: points present, 3: minimum values, 4: values present. */
			__( 'Not enough history: at least %1$d data points (%2$d present) and %3$d valid values (%4$d present) are required.', 'immens-forecast' ),
			self::MIN_POINTS,
			$points,
			self::MIN_FINITE,
			$finite
		);
		return new \WP_Error(
			'immens_forecast_insufficient_data',
			$message,
			array(
				'points' => $points,
				'finite' => $finite,
			)
		);
	}

	/**
	 * Read the raw values array from a series (no casting).
	 *
	 * @param array $series Series.
	 * @return array
	 */
	private static function raw_values( array $series ): array {
		return ( isset( $series['values'] ) && is_array( $series['values'] ) ) ? $series['values'] : array();
	}
}
```

- [ ] **Step 7: Run Series tests, then PHPCS and commit**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter SeriesTest
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(forecast): add Math and Series pure helpers"
```
Expected: `OK (6 tests)` then `0 errors`.

---

### Task 4: Settings (single option, sanitized, encrypted token)

**Files:**
- Create: `includes/Admin/Settings.php`, `tests/unit/Admin/SettingsTest.php`

**Interfaces:**
- Consumes: `Forecast\Crypto` (Task 2), `Forecast\Math::format_level()` (Task 3).
- Produces:
  - `ImmensForecast\Admin\Settings::OPTION = 'immens_forecast_settings'`, `TIMEOUT_MIN = 5`, `TIMEOUT_MAX = 300`
  - `defaults(): array` (keys: `provider`, `endpoint_url`, `endpoint_token_enc`, `endpoint_model`, `endpoint_timeout`, `quantiles`, `history_days`, `horizon`, `delete_data_on_uninstall`)
  - `all(): array`, `get( string, $default = null )`, `update( array ): array`, `reset_cache(): void`
  - `capability(): string`, `is_endpoint_configured(): bool`, `endpoint_token(): ?string`
  - `sanitize( array ): array`, `sanitize_quantiles( $raw ): array`

- [ ] **Step 1: Write the failing Settings tests**

`tests/unit/Admin/SettingsTest.php`:
```php
<?php
/**
 * Settings tests.
 *
 * @package ImmensForecast\Tests\Unit\Admin
 */

namespace ImmensForecast\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use ImmensForecast\Admin\Settings;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Settings accessor and sanitizer.
 */
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Settings::reset_cache();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return (int) abs( (int) $value );
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
	}

	public function test_defaults_have_expected_keys(): void {
		$defaults = Settings::defaults();
		foreach ( array( 'provider', 'endpoint_url', 'endpoint_token_enc', 'endpoint_model', 'endpoint_timeout', 'quantiles', 'history_days', 'horizon', 'delete_data_on_uninstall' ) as $key ) {
			self::assertArrayHasKey( $key, $defaults );
		}
		self::assertSame( array( 0.1, 0.5, 0.9 ), $defaults['quantiles'] );
	}

	public function test_all_merges_stored_over_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array( 'endpoint_model' => '28m' ) );
		Settings::reset_cache();
		self::assertSame( '28m', Settings::get( 'endpoint_model' ) );
		self::assertSame( 'baseline', Settings::get( 'provider' ) );
		self::assertSame( 'fallback', Settings::get( 'missing', 'fallback' ) );
	}

	public function test_update_merges_and_persists(): void {
		Functions\expect( 'update_option' )->once()->with( Settings::OPTION, \Mockery::type( 'array' ) )->andReturn( true );
		$result = Settings::update( array( 'horizon' => 14 ) );
		self::assertSame( 14, $result['horizon'] );
	}

	public function test_capability_uses_filter_value(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		self::assertSame( 'manage_options', Settings::capability() );
	}

	public function test_sanitize_clamps_timeout_and_history(): void {
		$clean = Settings::sanitize( array( 'endpoint_timeout' => 1, 'history_days' => 99999, 'horizon' => 9999 ) );
		self::assertSame( 5, $clean['endpoint_timeout'] );
		self::assertSame( 1095, $clean['history_days'] );
		self::assertSame( 180, $clean['horizon'] );

		$clean = Settings::sanitize( array( 'endpoint_timeout' => 9999 ) );
		self::assertSame( 300, $clean['endpoint_timeout'] );
	}

	public function test_sanitize_endpoint_url_rejects_non_http_and_trims(): void {
		self::assertSame( '', Settings::sanitize( array( 'endpoint_url' => 'ftp://example.test' ) )['endpoint_url'] );
		self::assertSame( 'https://api.example.test', Settings::sanitize( array( 'endpoint_url' => 'https://api.example.test/' ) )['endpoint_url'] );
	}

	public function test_sanitize_encrypts_new_token_and_keeps_existing_on_blank(): void {
		$clean = Settings::sanitize( array( 'endpoint_token' => 'secret-token' ) );
		self::assertNotSame( 'secret-token', $clean['endpoint_token_enc'] );
		self::assertMatchesRegularExpression( '/^[as]1:/', $clean['endpoint_token_enc'] );

		Functions\when( 'get_option' )->justReturn( array( 'endpoint_token_enc' => 'a1:keepme' ) );
		Settings::reset_cache();
		self::assertSame( 'a1:keepme', Settings::sanitize( array( 'endpoint_token' => '' ) )['endpoint_token_enc'] );

		self::assertSame( '', Settings::sanitize( array( 'endpoint_clear_token' => '1' ) )['endpoint_token_enc'] );
	}

	public function test_sanitize_quantiles_parses_dedupes_and_falls_back(): void {
		self::assertSame( array( 0.1, 0.5, 0.9 ), Settings::sanitize_quantiles( '0.1, 0.5, 0.9' ) );
		self::assertSame( array( 0.1, 0.5, 0.9 ), Settings::sanitize_quantiles( array( 0.9, 0.1, 0.5, 0.5 ) ) );
		self::assertSame( array( 0.1, 0.5, 0.9 ), Settings::sanitize_quantiles( array( 'x' ) ) );
		self::assertSame( array( 0.1, 0.5, 0.9 ), Settings::sanitize_quantiles( array( 2 ) ) );
	}

	public function test_endpoint_helpers(): void {
		Functions\when( 'get_option' )->justReturn( array( 'endpoint_url' => 'https://api.example.test' ) );
		Settings::reset_cache();
		self::assertTrue( Settings::is_endpoint_configured() );

		$crypto = new \ImmensForecast\Forecast\Crypto( array( 'openssl' ) );
		Functions\when( 'get_option' )->justReturn( array( 'endpoint_token_enc' => $crypto->encrypt( 'tok' ) ) );
		Settings::reset_cache();
		self::assertSame( 'tok', Settings::endpoint_token() );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter SettingsTest
```
Expected: FAIL — `Class "ImmensForecast\Admin\Settings" not found`.

- [ ] **Step 3: Implement Settings**

`includes/Admin/Settings.php`:
```php
<?php
/**
 * Plugin settings accessor and sanitizer.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Admin;

use ImmensForecast\Forecast\Crypto;
use ImmensForecast\Forecast\Math;

defined( 'ABSPATH' ) || exit;

/**
 * Static accessor for the single `immens_forecast_settings` option.
 */
final class Settings {

	const OPTION      = 'immens_forecast_settings';
	const TIMEOUT_MIN = 5;
	const TIMEOUT_MAX = 300;

	/**
	 * In-request cache of the merged option.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'provider'                 => 'baseline',
			'endpoint_url'             => '',
			'endpoint_token_enc'       => '',
			'endpoint_model'           => '209m',
			'endpoint_timeout'         => 60,
			'quantiles'                => array( 0.1, 0.5, 0.9 ),
			'history_days'             => 365,
			'horizon'                  => 30,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			self::$cache = array_merge( self::defaults(), $stored );
		}
		return self::$cache;
	}

	/**
	 * Single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist a patch merged over current settings.
	 *
	 * @param array $patch Setting subset.
	 * @return array Merged settings.
	 */
	public static function update( array $patch ): array {
		$merged = array_merge( self::all(), $patch );
		update_option( self::OPTION, $merged );
		self::$cache = $merged;
		return $merged;
	}

	/**
	 * Clear the in-request cache (tests, after external option writes).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * Capability required to use the plugin.
	 *
	 * @return string
	 */
	public static function capability(): string {
		$capability = apply_filters( 'immens_forecast_capability', 'manage_options' );
		return ( is_string( $capability ) && '' !== $capability ) ? $capability : 'manage_options';
	}

	/**
	 * Whether an endpoint URL is configured.
	 *
	 * @return bool
	 */
	public static function is_endpoint_configured(): bool {
		return '' !== (string) self::get( 'endpoint_url', '' );
	}

	/**
	 * Decrypted endpoint token, or null.
	 *
	 * @return string|null
	 */
	public static function endpoint_token(): ?string {
		$encrypted = (string) self::get( 'endpoint_token_enc', '' );
		if ( '' === $encrypted ) {
			return null;
		}
		$crypto = new Crypto();
		return $crypto->decrypt( $encrypted );
	}

	/**
	 * Sanitize the settings form payload.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ): array {
		$clean = self::all();

		if ( isset( $input['provider'] ) ) {
			$clean['provider'] = sanitize_text_field( (string) $input['provider'] );
		}
		if ( isset( $input['endpoint_url'] ) ) {
			$clean['endpoint_url'] = self::sanitize_url( (string) $input['endpoint_url'] );
		}
		if ( isset( $input['endpoint_model'] ) ) {
			$model                   = sanitize_text_field( (string) $input['endpoint_model'] );
			$clean['endpoint_model'] = ( '' === $model ) ? '209m' : $model;
		}
		if ( isset( $input['endpoint_timeout'] ) ) {
			$clean['endpoint_timeout'] = self::sanitize_timeout( $input['endpoint_timeout'] );
		}

		$token = isset( $input['endpoint_token'] ) ? trim( (string) $input['endpoint_token'] ) : '';
		if ( '' !== $token ) {
			$crypto = new Crypto();
			if ( $crypto->has_method() ) {
				$clean['endpoint_token_enc'] = $crypto->encrypt( $token );
			}
		} elseif ( ! empty( $input['endpoint_clear_token'] ) ) {
			$clean['endpoint_token_enc'] = '';
		}

		if ( isset( $input['quantiles'] ) ) {
			$clean['quantiles'] = self::sanitize_quantiles( $input['quantiles'] );
		}
		if ( isset( $input['history_days'] ) ) {
			$clean['history_days'] = min( 1095, max( 30, absint( $input['history_days'] ) ) );
		}
		if ( isset( $input['horizon'] ) ) {
			$clean['horizon'] = min( 180, max( 1, absint( $input['horizon'] ) ) );
		}
		if ( isset( $input['delete_data_on_uninstall'] ) ) {
			$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
		}

		return $clean;
	}

	/**
	 * Normalize a quantile list (string or array) to a sorted, unique float list.
	 *
	 * @param mixed $raw Raw value.
	 * @return float[]
	 */
	public static function sanitize_quantiles( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', trim( $raw ) );
		}
		if ( ! is_array( $raw ) ) {
			return self::defaults()['quantiles'];
		}

		$levels = array();
		foreach ( $raw as $value ) {
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$level = round( (float) $value, 4 );
			if ( $level < 0.01 || $level > 0.99 ) {
				continue;
			}
			$levels[ Math::format_level( $level ) ] = $level;
		}

		if ( count( $levels ) < 3 ) {
			return self::defaults()['quantiles'];
		}

		$levels = array_values( $levels );
		sort( $levels );
		return $levels;
	}

	/**
	 * Clamp and cast a timeout value.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function sanitize_timeout( $value ): int {
		$seconds = absint( $value );
		return max( self::TIMEOUT_MIN, min( self::TIMEOUT_MAX, $seconds ) );
	}

	/**
	 * Allow only http/https URLs; strip a trailing slash.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function sanitize_url( string $url ): string {
		$url    = esc_url_raw( trim( $url ) );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		return rtrim( $url, '/' );
	}
}
```

- [ ] **Step 4: Run tests, PHPCS and commit**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter SettingsTest
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(admin): add Settings option with sanitizer and encrypted token helpers"
```
Expected: `OK (9 tests)` then `0 errors` (`absint` may be an undefined-function warning under PHPCS → it is a WP function, allowed).

### Task 5: Datasets storage (custom table, CRUD, validation)

**Files:**
- Create: `includes/Storage/Datasets.php`
- Create: `tests/Support/FakeWpdb.php` (reusable in-memory `$wpdb` double)
- Create: `tests/unit/Storage/DatasetsTest.php`

**Interfaces:**
- Consumes: `Forecast\Math` (Task 3) — not directly; WordPress `$wpdb`, `dbDelta`, `wp_json_encode`.
- Produces (class `ImmensForecast\Storage\Datasets`):
  - Constants `TABLE = 'immens_forecast_datasets'`, `DB_VERSION = '1.0'`, `DB_VERSION_OPTION = 'immens_forecast_db_version'`, `MAX_DATASETS = 20`, `MAX_POINTS = 5000`, `NAME_MAX_LENGTH = 190`, `GRANULARITIES = array( 'day', 'week' )`
  - `table_name(): string`, `schema_sql( string $charset_collate ): string`, `create_table(): void`
  - `count(): int`, `all(): array`, `get( int $id ): ?array`
  - `create( string $name, string $granularity, array $points )` → `int|\WP_Error`
  - `update( int $id, array $fields )` → `true|\WP_Error`, `delete( int $id ): bool`
  - `validate_points( array $points ): ?\WP_Error`, `encode_points( array ): string`, `decode_points( string ): array`
  - Point shape (shared with Task 7 CSV parser): `array( 'd' => 'YYYY-MM-DD', 'v' => float|null )`

- [ ] **Step 1: Write the reusable `$wpdb` test double**

`tests/Support/FakeWpdb.php`:
```php
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
```

- [ ] **Step 2: Write the failing Datasets tests**

`tests/unit/Storage/DatasetsTest.php`:
```php
<?php
/**
 * Datasets storage tests.
 *
 * @package ImmensForecast\Tests\Unit\Storage
 */

namespace ImmensForecast\Tests\Unit\Storage;

use Brain\Monkey\Functions;
use ImmensForecast\Storage\Datasets;
use ImmensForecast\Tests\Support\FakeWpdb;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Datasets CRUD and validation.
 */
final class DatasetsTest extends TestCase {

	/**
	 * Fake database.
	 *
	 * @var FakeWpdb
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_time' )->justReturn( '2026-11-01 10:00:00' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'dbDelta' )->justReturn( array() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Two valid points.
	 *
	 * @return array
	 */
	private function points(): array {
		return array(
			array( 'd' => '2026-01-01', 'v' => 10 ),
			array( 'd' => '2026-01-02', 'v' => null ),
		);
	}

	public function test_table_name_uses_prefix(): void {
		self::assertSame( 'wp_immens_forecast_datasets', Datasets::table_name() );
	}

	public function test_schema_sql_has_required_columns(): void {
		$sql = Datasets::schema_sql( 'CHARSET' );
		foreach ( array( 'id', 'name', 'granularity', 'points', 'created_at', 'updated_at', 'PRIMARY KEY' ) as $needle ) {
			self::assertStringContainsString( $needle, $sql );
		}
	}

	public function test_create_table_calls_dbdelta_and_stores_version(): void {
		Functions\expect( 'dbDelta' )->once();
		Functions\expect( 'update_option' )->once()->with( Datasets::DB_VERSION_OPTION, Datasets::DB_VERSION );
		Datasets::create_table();
	}

	public function test_validate_points(): void {
		self::assertNull( Datasets::validate_points( $this->points() ) );

		self::assertSame( 'immens_forecast_csv_empty', Datasets::validate_points( array() )->get_error_code() );

		$too_many = array_fill( 0, Datasets::MAX_POINTS + 1, array( 'd' => '2026-01-01', 'v' => 1 ) );
		self::assertSame( 'immens_forecast_too_many_points', Datasets::validate_points( $too_many )->get_error_code() );

		self::assertSame( 'immens_forecast_csv_invalid_date', Datasets::validate_points( array( array( 'd' => '01/02/2026', 'v' => 1 ) ) )->get_error_code() );
		self::assertSame( 'immens_forecast_csv_invalid_value', Datasets::validate_points( array( array( 'd' => '2026-01-01', 'v' => 'x' ) ) )->get_error_code() );
	}

	public function test_encode_decode_round_trip(): void {
		$encoded = Datasets::encode_points( $this->points() );
		self::assertSame( '[{"d":"2026-01-01","v":10},{"d":"2026-01-02","v":null}]', $encoded );
		self::assertSame( array( array( 'd' => '2026-01-01', 'v' => 10.0 ), array( 'd' => '2026-01-02', 'v' => null ) ), Datasets::decode_points( $encoded ) );
		self::assertSame( array(), Datasets::decode_points( 'not-json' ) );
	}

	public function test_create_inserts_and_returns_id(): void {
		$id = Datasets::create( 'Web sales', 'day', $this->points() );
		self::assertSame( 1, $id );
		self::assertSame( 'Web sales', $this->wpdb->rows[1]['name'] );
		self::assertSame( 'day', $this->wpdb->rows[1]['granularity'] );
		self::assertStringContainsString( '2026-01-01', $this->wpdb->rows[1]['points'] );
	}

	public function test_create_rejects_when_limit_reached(): void {
		for ( $i = 0; $i < Datasets::MAX_DATASETS; $i++ ) {
			Datasets::create( 'ds-' . $i, 'day', $this->points() );
		}
		$error = Datasets::create( 'overflow', 'day', $this->points() );
		self::assertSame( 'immens_forecast_too_many_datasets', $error->get_error_code() );
	}

	public function test_get_decodes_points_and_null_when_missing(): void {
		$id  = Datasets::create( 'Web sales', 'week', $this->points() );
		$row = Datasets::get( $id );
		self::assertSame( 'week', $row['granularity'] );
		self::assertSame( 2, $row['point_count'] );
		self::assertSame( '2026-01-02', $row['points'][1]['d'] );
		self::assertNull( Datasets::get( 999 ) );
	}

	public function test_all_and_delete(): void {
		$id = Datasets::create( 'Web sales', 'day', $this->points() );
		Datasets::create( 'Store', 'day', $this->points() );

		$all = Datasets::all();
		self::assertCount( 2, $all );
		self::assertSame( 2, $all[0]['point_count'] );
		self::assertArrayNotHasKey( 'points', $all[0] );

		self::assertTrue( Datasets::delete( $id ) );
		self::assertCount( 1, Datasets::all() );
		self::assertFalse( Datasets::delete( 999 ) );
	}

	public function test_update_validates_and_persists(): void {
		$id = Datasets::create( 'Web sales', 'day', $this->points() );

		self::assertTrue( Datasets::update( $id, array( 'name' => 'Renamed', 'granularity' => 'bogus' ) ) );
		$row = Datasets::get( $id );
		self::assertSame( 'Renamed', $row['name'] );
		self::assertSame( 'day', $row['granularity'] );

		$error = Datasets::update( $id, array( 'points' => array() ) );
		self::assertSame( 'immens_forecast_csv_empty', $error->get_error_code() );

		self::assertSame( 'immens_forecast_unsupported_params', Datasets::update( 999, array( 'name' => 'x' ) )->get_error_code() );
	}
}
```

- [ ] **Step 3: Run tests to verify they fail**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter DatasetsTest
```
Expected: FAIL — `Class "ImmensForecast\Storage\Datasets" not found`.

- [ ] **Step 4: Implement Datasets**

`includes/Storage/Datasets.php`:
```php
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
```

- [ ] **Step 5: Run tests, PHPCS and commit**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter DatasetsTest
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(storage): add Datasets custom table with CRUD and validation"
```
Expected: `OK (10 tests)` then `0 errors`.

### Task 6: Source contract and registry

**Files:**
- Create: `includes/Sources/SourceInterface.php`, `includes/Sources/SourceRegistry.php`
- Create: `tests/unit/Sources/StubSource.php`, `tests/unit/Sources/SourceRegistryTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `ImmensForecast\Sources\SourceInterface`: `get_id(): string`, `get_label(): string`, `get_description(): string`, `is_available(): bool`, `get_metrics(): array`, `get_series( array $args )` (returns payload array or `\WP_Error`; no return type — PHP 7.4).
  - `get_metrics()` shape: `metric_id => array( 'label' => string, 'granularities' => string[] )`.
  - `get_series( array $args )` input: `metric`, `granularity`, `history_start` (`YYYY-MM-DD`), `horizon` (int), plus source-specific keys (e.g. `dataset_id`). Output payload: `id`, `metric`, `granularity`, `dates` (`Y-m-d[]`, length n), `values` (`float|null[]`, length n), `future_dates` (`Y-m-d[]`, length horizon), `past_covariates`, `known_covariates`.
  - `ImmensForecast\Sources\SourceRegistry`: `add( SourceInterface ): void`, `get( string ): ?SourceInterface`, `has( string ): bool`, `all(): array` (id => source), `ids(): array`, `available(): array`, static `build(): SourceRegistry` (applies `immens_forecast_sources` filter, keeps only `SourceInterface` instances).
  - `Tests\Unit\Sources\StubSource` — configurable test double.

- [ ] **Step 1: Write the interface**

`includes/Sources/SourceInterface.php`:
```php
<?php
/**
 * Contract every data source must implement.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * A source turns a metric + window into a normalized series payload.
 */
interface SourceInterface {

	/**
	 * Unique id, e.g. "woo", "manual".
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Short description.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Whether the source can be used right now.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Metrics exposed by the source.
	 *
	 * @return array metric_id => array( 'label' => string, 'granularities' => string[] )
	 */
	public function get_metrics(): array;

	/**
	 * Build the series payload.
	 *
	 * @param array $args metric, granularity, history_start, horizon, source-specific keys.
	 * @return array|\WP_Error
	 */
	public function get_series( array $args );
}
```

- [ ] **Step 2: Write the test double and failing registry tests**

`tests/unit/Sources/StubSource.php`:
```php
<?php
/**
 * Configurable source double for unit tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

use ImmensForecast\Sources\SourceInterface;

/**
 * Stub source.
 */
final class StubSource implements SourceInterface {

	/**
	 * Source id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Availability flag.
	 *
	 * @var bool
	 */
	private $available;

	/**
	 * Value returned by get_series().
	 *
	 * @var mixed
	 */
	private $series;

	/**
	 * Number of get_series() calls.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * Last args passed to get_series().
	 *
	 * @var array
	 */
	public $last_args = array();

	/**
	 * Constructor.
	 *
	 * @param string $id        Source id.
	 * @param bool   $available Availability.
	 * @param mixed  $series    get_series() return value.
	 */
	public function __construct( string $id = 'stub', bool $available = true, $series = array() ) {
		$this->id        = $id;
		$this->available = $available;
		$this->series    = $series;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return 'Stub';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return 'Stub source';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_metrics(): array {
		return array(
			'revenue' => array(
				'label'         => 'Revenue',
				'granularities' => array( 'day', 'week' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public function get_series( array $args ) {
		++$this->calls;
		$this->last_args = $args;
		return $this->series;
	}
}
```

`tests/unit/Sources/SourceRegistryTest.php`:
```php
<?php
/**
 * Source registry tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

use Brain\Monkey\Functions;
use ImmensForecast\Sources\SourceRegistry;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Registration, lookup and filtering.
 */
final class SourceRegistryTest extends TestCase {

	public function test_add_get_has_ids(): void {
		$woo   = new StubSource( 'woo' );
		$manual = new StubSource( 'manual' );
		$registry = new SourceRegistry( array( $woo, $manual ) );

		self::assertTrue( $registry->has( 'woo' ) );
		self::assertSame( $woo, $registry->get( 'woo' ) );
		self::assertNull( $registry->get( 'nope' ) );
		self::assertSame( array( 'woo', 'manual' ), $registry->ids() );
		self::assertCount( 2, $registry->all() );
	}

	public function test_duplicate_id_overwrites(): void {
		$registry = new SourceRegistry();
		$registry->add( new StubSource( 'woo' ) );
		$replacement = new StubSource( 'woo' );
		$registry->add( $replacement );

		self::assertSame( $replacement, $registry->get( 'woo' ) );
		self::assertCount( 1, $registry->all() );
	}

	public function test_available_filters_unavailable(): void {
		$registry = new SourceRegistry( array( new StubSource( 'woo', true ), new StubSource( 'manual', false ) ) );
		self::assertSame( array( 'woo' ), array_keys( $registry->available() ) );
	}

	public function test_build_ignores_non_sources_from_filter(): void {
		Functions\when( 'apply_filters' )->justReturn( array( new StubSource( 'woo' ), 'garbage', 42 ) );
		$registry = SourceRegistry::build();
		self::assertSame( array( 'woo' ), $registry->ids() );
	}
}
```

- [ ] **Step 3: Run tests to verify they fail**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter SourceRegistryTest
```
Expected: FAIL — `Class "ImmensForecast\Sources\SourceRegistry" not found`.

- [ ] **Step 4: Implement SourceRegistry**

`includes/Sources/SourceRegistry.php`:
```php
<?php
/**
 * Registry of available data sources.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Holds sources keyed by id, built from the `immens_forecast_sources` filter.
 */
final class SourceRegistry {

	/**
	 * Sources keyed by id.
	 *
	 * @var SourceInterface[]
	 */
	private $sources = array();

	/**
	 * Constructor.
	 *
	 * @param SourceInterface[] $sources Initial sources.
	 */
	public function __construct( array $sources = array() ) {
		foreach ( $sources as $source ) {
			$this->add( $source );
		}
	}

	/**
	 * Register a source (last one wins on id collision).
	 *
	 * @param SourceInterface $source Source.
	 * @return void
	 */
	public function add( SourceInterface $source ): void {
		$this->sources[ $source->get_id() ] = $source;
	}

	/**
	 * Get a source by id.
	 *
	 * @param string $id Source id.
	 * @return SourceInterface|null
	 */
	public function get( string $id ): ?SourceInterface {
		return isset( $this->sources[ $id ] ) ? $this->sources[ $id ] : null;
	}

	/**
	 * Whether a source id is registered.
	 *
	 * @param string $id Source id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->sources[ $id ] );
	}

	/**
	 * All sources keyed by id.
	 *
	 * @return SourceInterface[]
	 */
	public function all(): array {
		return $this->sources;
	}

	/**
	 * Registered source ids.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->sources );
	}

	/**
	 * Only sources that are currently available.
	 *
	 * @return SourceInterface[]
	 */
	public function available(): array {
		$out = array();
		foreach ( $this->sources as $id => $source ) {
			if ( $source->is_available() ) {
				$out[ $id ] = $source;
			}
		}
		return $out;
	}

	/**
	 * Build a registry from the public filter (free + Pro + third parties).
	 *
	 * @return SourceRegistry
	 */
	public static function build(): SourceRegistry {
		$registry = new self();
		$filtered = apply_filters( 'immens_forecast_sources', array() );
		foreach ( (array) $filtered as $source ) {
			if ( $source instanceof SourceInterface ) {
				$registry->add( $source );
			}
		}
		return $registry;
	}
}
```

- [ ] **Step 5: Run tests, PHPCS and commit**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter SourceRegistryTest
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(sources): add SourceInterface and SourceRegistry"
```
Expected: `OK (4 tests)` then `0 errors`.

### Task 7: SeriesBuilder, CSV parser and ManualSource

**Files:**
- Create: `includes/Sources/SeriesBuilder.php`, `includes/Sources/CsvParser.php`, `includes/Sources/ManualSource.php`
- Create: `tests/unit/Sources/SeriesBuilderTest.php`, `tests/unit/Sources/CsvParserTest.php`, `tests/unit/Sources/ManualSourceTest.php`

**Interfaces:**
- Consumes: `Sources\SourceInterface` (Task 6), `Storage\Datasets` (Task 5).
- Produces:
  - `ImmensForecast\Sources\SeriesBuilder::build( array $points, string $granularity, int $horizon, string $id = 'series' ): array` → source payload (see Task 6); `future_dates( string $last_date, string $granularity, int $horizon ): array`.
  - `ImmensForecast\Sources\CsvParser::MAX_POINTS = 5000`, `parse( string $raw, string $granularity = 'day', ?string $end_date = null )` → point list or `\WP_Error`, `parse_date( string $token ): ?string`.
  - `ImmensForecast\Sources\ManualSource` (id `manual`, metric `value`): uses `$args['dataset_id']`; null tokens accepted in CSV: `nan`, `na`, `n/a`, `-`, `null`, `none` (case-insensitive).

- [ ] **Step 1: Write the failing SeriesBuilder tests**

`tests/unit/Sources/SeriesBuilderTest.php`:
```php
<?php
/**
 * SeriesBuilder tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

use ImmensForecast\Sources\SeriesBuilder;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Payload shape and future dates.
 */
final class SeriesBuilderTest extends TestCase {

	/**
	 * Three daily points ending 2026-01-03.
	 *
	 * @return array
	 */
	private function points(): array {
		return array(
			array( 'd' => '2026-01-01', 'v' => 10.0 ),
			array( 'd' => '2026-01-02', 'v' => null ),
			array( 'd' => '2026-01-03', 'v' => 30.0 ),
		);
	}

	public function test_build_payload_shape(): void {
		$payload = SeriesBuilder::build( $this->points(), 'day', 2, 'value' );

		self::assertSame( 'value', $payload['id'] );
		self::assertSame( array( '2026-01-01', '2026-01-02', '2026-01-03' ), $payload['dates'] );
		self::assertSame( array( 10.0, null, 30.0 ), $payload['values'] );
		self::assertSame( array( '2026-01-04', '2026-01-05' ), $payload['future_dates'] );
		self::assertCount( 3, $payload['past_covariates']['dow'] );
		self::assertCount( 5, $payload['known_covariates']['dow'] );
	}

	public function test_future_dates_weekly_step(): void {
		self::assertSame( array( '2026-01-10', '2026-01-17' ), SeriesBuilder::future_dates( '2026-01-03', 'week', 2 ) );
	}

	public function test_dow_alignment(): void {
		$payload = SeriesBuilder::build( $this->points(), 'day', 1 );
		self::assertSame( array( 4, 5, 6, 0 ), $payload['known_covariates']['dow'] );
	}
}
```

- [ ] **Step 2: Implement SeriesBuilder and run its tests**

`includes/Sources/SeriesBuilder.php`:
```php
<?php
/**
 * Builds the normalized source payload shared by all sources.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Dates, values and `dow` covariates for history + horizon.
 */
final class SeriesBuilder {

	/**
	 * Build the payload.
	 *
	 * @param array  $points      Point list ( d, v ).
	 * @param string $granularity day|week.
	 * @param int    $horizon     Future steps.
	 * @param string $id          Series id.
	 * @return array
	 */
	public static function build( array $points, string $granularity, int $horizon, string $id = 'series' ): array {
		$granularity = ( 'week' === $granularity ) ? 'week' : 'day';
		$horizon     = max( 0, $horizon );

		$dates  = array();
		$values = array();
		foreach ( $points as $point ) {
			$dates[]  = (string) $point['d'];
			$values[] = ( isset( $point['v'] ) && is_numeric( $point['v'] ) ) ? (float) $point['v'] : null;
		}

		$future = empty( $dates ) ? array() : self::future_dates( (string) end( $dates ), $granularity, $horizon );

		$dow = array();
		foreach ( array_merge( $dates, $future ) as $date ) {
			$timestamp = strtotime( $date );
			$dow[]     = ( false === $timestamp ) ? 0 : (int) gmdate( 'w', $timestamp );
		}

		return array(
			'id'               => $id,
			'metric'           => $id,
			'granularity'      => $granularity,
			'dates'            => $dates,
			'values'           => $values,
			'future_dates'     => $future,
			'past_covariates'  => array( 'dow' => array_slice( $dow, 0, count( $dates ) ) ),
			'known_covariates' => array( 'dow' => $dow ),
		);
	}

	/**
	 * Future dates after a last known date.
	 *
	 * @param string $last_date   Last history date.
	 * @param string $granularity day|week.
	 * @param int    $horizon     Steps.
	 * @return string[]
	 */
	public static function future_dates( string $last_date, string $granularity, int $horizon ): array {
		$step   = ( 'week' === $granularity ) ? '+7 days' : '+1 day';
		$cursor = $last_date;
		$out    = array();

		for ( $i = 0; $i < $horizon; $i++ ) {
			$timestamp = strtotime( $cursor . ' ' . $step );
			if ( false === $timestamp ) {
				break;
			}
			$cursor = gmdate( 'Y-m-d', $timestamp );
			$out[]  = $cursor;
		}
		return $out;
	}
}
```

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter SeriesBuilderTest
```
Expected: `OK (3 tests)`.

- [ ] **Step 3: Write the failing CSV parser tests**

`tests/unit/Sources/CsvParserTest.php`:
```php
<?php
/**
 * CSV parser tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

use Brain\Monkey\Functions;
use ImmensForecast\Sources\CsvParser;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Parsing, validation and error codes.
 */
final class CsvParserTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2026-01-10' );
	}

	public function test_parses_date_value_pairs_with_various_separators(): void {
		$points = CsvParser::parse( "date,value\n2026-01-01,10\n2026-01-02; 12,5\n2026-01-03\t20" );
		self::assertSame( array( '2026-01-01', '2026-01-02', '2026-01-03' ), array_column( $points, 'd' ) );
		self::assertSame( array( 10.0, 12.5, 20.0 ), array_column( $points, 'v' ) );

		$points = CsvParser::parse( "2026-01-01 10\n2026-01-02 12" );
		self::assertCount( 2, $points );
	}

	public function test_accepts_dd_mm_yyyy(): void {
		$points = CsvParser::parse( "15/03/2026,42" );
		self::assertSame( '2026-03-15', $points[0]['d'] );
	}

	public function test_missing_values_with_nan_tokens(): void {
		$points = CsvParser::parse( "2026-01-01,NaN\n2026-01-02,-" );
		self::assertSame( array( null, null ), array_column( $points, 'v' ) );
	}

	public function test_values_only_generates_dates_backwards(): void {
		$points = CsvParser::parse( "10\n20\n30", 'day', '2026-01-03' );
		self::assertSame( array( '2026-01-01', '2026-01-02', '2026-01-03' ), array_column( $points, 'd' ) );

		$weekly = CsvParser::parse( "10\n20", 'week', '2026-01-15' );
		self::assertSame( array( '2026-01-08', '2026-01-15' ), array_column( $weekly, 'd' ) );
	}

	public function test_sorts_ascending(): void {
		$points = CsvParser::parse( "2026-02-01,1\n2026-01-01,2" );
		self::assertSame( array( '2026-01-01', '2026-02-01' ), array_column( $points, 'd' ) );
	}

	public function test_error_codes(): void {
		self::assertSame( 'immens_forecast_csv_empty', CsvParser::parse( "  \n " )->get_error_code() );
		self::assertSame( 'immens_forecast_csv_mixed_lines', CsvParser::parse( "2026-01-01,1\n2" )->get_error_code() );
		self::assertSame( 'immens_forecast_csv_invalid_date', CsvParser::parse( "31/02/2026,1" )->get_error_code() );
		self::assertSame( 'immens_forecast_csv_invalid_value', CsvParser::parse( "2026-01-01,abc" )->get_error_code() );
		self::assertSame( 'immens_forecast_unsupported_params', CsvParser::parse( "1", 'bogus' )->get_error_code() );
	}

	public function test_too_many_points(): void {
		$raw = implode( "\n", array_fill( 0, CsvParser::MAX_POINTS + 1, '1' ) );
		self::assertSame( 'immens_forecast_too_many_points', CsvParser::parse( $raw )->get_error_code() );
	}

	public function test_parse_date_helper(): void {
		self::assertSame( '2026-03-05', CsvParser::parse_date( '2026-3-5' ) );
		self::assertSame( '2026-03-05', CsvParser::parse_date( '05/03/2026' ) );
		self::assertNull( CsvParser::parse_date( 'not-a-date' ) );
		self::assertNull( CsvParser::parse_date( '2026-02-30' ) );
	}
}
```

- [ ] **Step 4: Run CSV tests to verify they fail, then implement CsvParser**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter CsvParserTest
```
Expected: FAIL — `Class "ImmensForecast\Sources\CsvParser" not found`.

`includes/Sources/CsvParser.php`:
```php
<?php
/**
 * CSV / pasted-values parser.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Parses "date,value" pairs or bare value lists into a point list.
 */
final class CsvParser {

	const MAX_POINTS = 5000;

	/**
	 * Tokens accepted as a missing value.
	 *
	 * @var string[]
	 */
	private static $null_tokens = array( 'nan', 'na', 'n/a', '-', 'null', 'none' );

	/**
	 * Parse raw text.
	 *
	 * @param string      $raw         Raw input.
	 * @param string      $granularity day|week (for values-only input).
	 * @param string|null $end_date    Last date for values-only input (defaults to today).
	 * @return array|\WP_Error
	 */
	public static function parse( string $raw, string $granularity = 'day', ?string $end_date = null ) {
		$granularity = strtolower( trim( $granularity ) );
		if ( ! in_array( $granularity, array( 'day', 'week' ), true ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Invalid granularity.', 'immens-forecast' ) );
		}

		$lines = array();
		foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $raw ) ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		if ( empty( $lines ) ) {
			return new \WP_Error( 'immens_forecast_csv_empty', __( 'No data found.', 'immens-forecast' ) );
		}

		$mode   = '';
		$points = array();

		foreach ( $lines as $index => $line ) {
			$tokens = preg_split( '/[\s,;]+/', $line, -1, PREG_SPLIT_NO_EMPTY );
			if ( empty( $tokens ) ) {
				continue;
			}
			if ( 0 === $index && null === self::parse_date( $tokens[0] ) && ! is_numeric( $tokens[0] ) ) {
				continue; // Header row (e.g. "date,value").
			}

			$row_mode = ( count( $tokens ) >= 2 ) ? 'pairs' : 'values';
			if ( '' === $mode ) {
				$mode = $row_mode;
			} elseif ( $mode !== $row_mode ) {
				/* translators: %d: row number. */
				return new \WP_Error( 'immens_forecast_csv_mixed_lines', sprintf( __( 'Row %d mixes dated and bare values.', 'immens-forecast' ), $index + 1 ) );
			}

			if ( 'pairs' === $row_mode ) {
				$date = self::parse_date( $tokens[0] );
				if ( null === $date ) {
					/* translators: %d: row number. */
					return new \WP_Error( 'immens_forecast_csv_invalid_date', sprintf( __( 'Invalid date on row %d.', 'immens-forecast' ), $index + 1 ) );
				}
				$value = self::to_value( $tokens[1] );
				if ( false === $value ) {
					/* translators: %d: row number. */
					return new \WP_Error( 'immens_forecast_csv_invalid_value', sprintf( __( 'Invalid value on row %d.', 'immens-forecast' ), $index + 1 ) );
				}
				$points[] = array( 'd' => $date, 'v' => $value );
			} else {
				$value = self::to_value( $tokens[0] );
				if ( false === $value ) {
					/* translators: %d: row number. */
					return new \WP_Error( 'immens_forecast_csv_invalid_value', sprintf( __( 'Invalid value on row %d.', 'immens-forecast' ), $index + 1 ) );
				}
				$points[] = array( 'd' => '', 'v' => $value );
			}
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

		if ( 'values' === $mode ) {
			$points = self::assign_dates( $points, $granularity, $end_date );
		}

		usort(
			$points,
			static function ( $first, $second ) {
				return strcmp( $first['d'], $second['d'] );
			}
		);
		return $points;
	}

	/**
	 * Parse and normalize a date token to Y-m-d, or null.
	 *
	 * @param string $token Token.
	 * @return string|null
	 */
	public static function parse_date( string $token ): ?string {
		$token = trim( $token );
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $token, $match ) ) {
			$year = (int) $match[1];
			$mon  = (int) $match[2];
			$day  = (int) $match[3];
		} elseif ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $token, $match ) ) {
			$year = (int) $match[3];
			$mon  = (int) $match[2];
			$day  = (int) $match[1];
		} else {
			return null;
		}
		if ( ! checkdate( $mon, $day, $year ) ) {
			return null;
		}
		return sprintf( '%04d-%02d-%02d', $year, $mon, $day );
	}

	/**
	 * Convert a value token: float, null for missing tokens, false when invalid.
	 *
	 * @param string $token Token.
	 * @return float|null|false
	 */
	private static function to_value( string $token ) {
		if ( in_array( strtolower( trim( $token ) ), self::$null_tokens, true ) ) {
			return null;
		}
		$normalized = str_replace( ',', '.', trim( $token ) );
		return is_numeric( $normalized ) ? (float) $normalized : false;
	}

	/**
	 * Generate dates backwards from the end date for values-only input.
	 *
	 * @param array       $points      Points without dates.
	 * @param string      $granularity day|week.
	 * @param string|null $end_date    End date.
	 * @return array
	 */
	private static function assign_dates( array $points, string $granularity, ?string $end_date ): array {
		$end = ( null !== $end_date ) ? self::parse_date( $end_date ) : null;
		if ( null === $end ) {
			$end = (string) current_time( 'Y-m-d' );
		}

		$step   = ( 'week' === $granularity ) ? '-7 days' : '-1 day';
		$cursor = $end;
		$dates  = array();
		for ( $i = 0, $count = count( $points ); $i < $count; $i++ ) {
			array_unshift( $dates, $cursor );
			$cursor = gmdate( 'Y-m-d', (int) strtotime( $cursor . ' ' . $step ) );
		}
		foreach ( $points as $index => $point ) {
			$points[ $index ]['d'] = $dates[ $index ];
		}
		return $points;
	}
}
```

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter CsvParserTest
```
Expected: `OK (8 tests)`.

- [ ] **Step 5: Write the failing ManualSource tests**

`tests/unit/Sources/ManualSourceTest.php`:
```php
<?php
/**
 * ManualSource tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

use Brain\Monkey\Functions;
use ImmensForecast\Sources\ManualSource;
use ImmensForecast\Storage\Datasets;
use ImmensForecast\Tests\Support\FakeWpdb;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Dataset-backed series.
 */
final class ManualSourceTest extends TestCase {

	/**
	 * Fake database.
	 *
	 * @var FakeWpdb
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_time' )->justReturn( '2026-11-01 10:00:00' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return (int) abs( (int) $value );
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Create a dataset with three daily points.
	 *
	 * @return int Dataset id.
	 */
	private function seed_dataset(): int {
		return (int) Datasets::create(
			'Web sales',
			'day',
			array(
				array( 'd' => '2026-01-01', 'v' => 10 ),
				array( 'd' => '2026-01-02', 'v' => 20 ),
				array( 'd' => '2026-01-03', 'v' => 30 ),
			)
		);
	}

	public function test_is_available_depends_on_datasets(): void {
		$source = new ManualSource();
		self::assertFalse( $source->is_available() );
		$this->seed_dataset();
		self::assertTrue( $source->is_available() );
	}

	public function test_get_series_builds_payload_and_honors_history_start(): void {
		$id     = $this->seed_dataset();
		$source = new ManualSource();

		$payload = $source->get_series(
			array(
				'dataset_id'    => $id,
				'metric'        => 'value',
				'granularity'   => 'day',
				'history_start' => '2026-01-02',
				'horizon'       => 2,
			)
		);

		self::assertSame( array( '2026-01-02', '2026-01-03' ), $payload['dates'] );
		self::assertSame( array( 20.0, 30.0 ), $payload['values'] );
		self::assertSame( array( '2026-01-04', '2026-01-05' ), $payload['future_dates'] );
		self::assertSame( 'value', $payload['id'] );
	}

	public function test_get_series_errors_when_dataset_missing(): void {
		$source = new ManualSource();
		$error  = $source->get_series( array( 'dataset_id' => 123 ) );
		self::assertSame( 'immens_forecast_unsupported_params', $error->get_error_code() );
	}

	public function test_invalid_granularity_falls_back_to_dataset(): void {
		$id     = $this->seed_dataset();
		$source = new ManualSource();
		$payload = $source->get_series(
			array(
				'dataset_id'  => $id,
				'granularity' => 'bogus',
				'horizon'     => 1,
			)
		);
		self::assertSame( 'day', $payload['granularity'] );
	}
}
```

- [ ] **Step 6: Implement ManualSource and run the tests**

`includes/Sources/ManualSource.php`:
```php
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
```

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter "SeriesBuilderTest|CsvParserTest|ManualSourceTest"
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(sources): add SeriesBuilder, CSV parser and ManualSource"
```
Expected: `OK (15 tests)` then `0 errors`.

### Task 8: WooCommerce orders source

**Files:**
- Create: `includes/Sources/WooSource.php`
- Create: `tests/unit/Sources/FakeWooOrder.php`
- Create: `tests/unit/Sources/WooSourceTest.php`

**Interfaces:**
- Consumes: `Sources\SourceInterface` and `Sources\SeriesBuilder` (Tasks 6–7), WordPress `$wpdb`, WooCommerce `wc_get_orders()` / `wc_prices_include_tax()`.
- Produces: `ImmensForecast\Sources\WooSource` — constants `METRICS = array( 'revenue', 'orders', 'items' )` and `ORDER_STATUSES = array( 'wc-completed', 'wc-processing' )`; uses error codes `immens_forecast_unsupported_params` and `immens_forecast_source_unavailable` (added to Global Constraints).

**Design decisions (locked during spec review):**
- Stats path: one prepared query over `{$wpdb->prefix}wc_order_stats`, gated by `SHOW TABLES LIKE`. Refunds are separate rows with `parent_id <> 0`, and their sign convention differed across WooCommerce versions (`OrderUtil::uses_new_full_refund_data()`), so SQL takes `ABS()` partials (`refund_gross`, `refund_tax`, `refund_items`) and PHP performs the subtraction. Tax is subtracted only when `wc_prices_include_tax()` is true.
- Fallback path: `wc_get_orders()` in inclusive monthly chunks (`limit 100`, `paginate => true`); the status filter applies only to `shop_order`, and a second pass over `shop_order_refund` is bucketed by the **refund** date.
- Zero-fill: missing days/weeks are `0.0` — for counters and sums "no rows" is data, not a gap.
- `items` comes from `num_items_sold` (single table, mirrors WooCommerce's own Orders report) instead of joining `wc_order_product_lookup` — documented deviation, revisit only if E2E shows drift.
- Both paths must return identical series for the same fixture: enforced by a dedicated cross-path test.

- [ ] **Step 1: Write the fixtures and the failing WooSource tests**

`tests/unit/Sources/FakeWooOrder.php`:
```php
<?php
/**
 * WooCommerce order doubles for WooSource tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

/**
 * Date object mimicking WC_DateTime->date().
 */
final class FakeWooDate {

	/**
	 * Stored date-time.
	 *
	 * @var string
	 */
	private $datetime;

	/**
	 * Constructor.
	 *
	 * @param string $datetime Date-time string.
	 */
	public function __construct( string $datetime ) {
		$this->datetime = $datetime;
	}

	/**
	 * Format the stored date.
	 *
	 * @param string $format Date format.
	 * @return string
	 */
	public function date( $format ): string {
		return gmdate( $format, (int) strtotime( $this->datetime ) );
	}
}

/**
 * Line item exposing get_quantity().
 */
final class FakeWooItem {

	/**
	 * Quantity.
	 *
	 * @var int
	 */
	private $quantity;

	/**
	 * Constructor.
	 *
	 * @param int $quantity Quantity.
	 */
	public function __construct( int $quantity ) {
		$this->quantity = $quantity;
	}

	/**
	 * Quantity.
	 *
	 * @return int
	 */
	public function get_quantity(): int {
		return $this->quantity;
	}
}

/**
 * Order/refund object exposing the WC_Order methods WooSource uses.
 */
final class FakeWooOrder {

	/**
	 * Created date-time.
	 *
	 * @var string
	 */
	private $date;

	/**
	 * Order total.
	 *
	 * @var float
	 */
	private $total;

	/**
	 * Total tax.
	 *
	 * @var float
	 */
	private $tax;

	/**
	 * Line item quantities.
	 *
	 * @var int[]
	 */
	private $quantities;

	/**
	 * Constructor.
	 *
	 * @param string $date       Created date-time.
	 * @param float  $total      Order total.
	 * @param float  $tax        Total tax.
	 * @param int[]  $quantities Line item quantities.
	 */
	public function __construct( string $date, float $total, float $tax, array $quantities = array( 1 ) ) {
		$this->date       = $date;
		$this->total      = $total;
		$this->tax        = $tax;
		$this->quantities = $quantities;
	}

	/**
	 * Creation date (site timezone).
	 *
	 * @return FakeWooDate
	 */
	public function get_date_created(): FakeWooDate {
		return new FakeWooDate( $this->date );
	}

	/**
	 * Order total.
	 *
	 * @return float
	 */
	public function get_total(): float {
		return $this->total;
	}

	/**
	 * Total tax.
	 *
	 * @return float
	 */
	public function get_total_tax(): float {
		return $this->tax;
	}

	/**
	 * Line items.
	 *
	 * @return FakeWooItem[]
	 */
	public function get_items(): array {
		$items = array();
		foreach ( $this->quantities as $quantity ) {
			$items[] = new FakeWooItem( (int) $quantity );
		}
		return $items;
	}
}
```

`tests/unit/Sources/WooSourceTest.php`:
```php
<?php
/**
 * WooSource tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

use Brain\Monkey\Functions;
use ImmensForecast\Sources\WooSource;
use ImmensForecast\Tests\Support\FakeWpdb;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Stats path, fallback path and their agreement.
 */
final class WooSourceTest extends TestCase {

	/**
	 * Fake database.
	 *
	 * @var FakeWpdb
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		Functions\when( '__' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2026-11-03' );
		Functions\when( 'wc_prices_include_tax' )->justReturn( false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Two days of stats-table aggregates: one order each day, refunds on day 2.
	 */
	private function seed_stats_rows(): void {
		$this->wpdb->tables = array( 'wp_wc_order_stats' );
		$this->wpdb->rows   = array(
			1 => array(
				'bucket'       => '2026-11-01',
				'orders_count' => 1,
				'gross'        => 100.0,
				'tax'          => 22.0,
				'items'        => 2,
				'refund_gross' => 0.0,
				'refund_tax'   => 0.0,
				'refund_items' => 0,
			),
			2 => array(
				'bucket'       => '2026-11-02',
				'orders_count' => 1,
				'gross'        => 50.0,
				'tax'          => 11.0,
				'items'        => 1,
				'refund_gross' => 30.0,
				'refund_tax'   => 6.6,
				'refund_items' => 1,
			),
		);
	}

	/**
	 * wc_get_orders() double: paginated batches split by order type.
	 *
	 * @param array $orders  shop_order objects.
	 * @param array $refunds shop_order_refund objects.
	 * @return callable
	 */
	private static function source_orders( array $orders, array $refunds ): callable {
		return static function ( $args ) use ( $orders, $refunds ) {
			return (object) array(
				'orders'        => ( 'shop_order_refund' === $args['type'] ) ? $refunds : $orders,
				'max_num_pages' => 1,
				'total'         => count( $orders ),
			);
		};
	}

	public function test_contract_and_metrics(): void {
		$source = new WooSource();

		self::assertSame( 'woo', $source->get_id() );
		self::assertNotSame( '', $source->get_label() );
		self::assertNotSame( '', $source->get_description() );

		$metrics = $source->get_metrics();
		self::assertSame( array( 'revenue', 'orders', 'items' ), array_keys( $metrics ) );
		foreach ( $metrics as $metric ) {
			self::assertSame( array( 'day', 'week' ), $metric['granularities'] );
			self::assertNotSame( '', $metric['label'] );
		}
	}

	/**
	 * `wc_get_orders` stays undefined until something stubs it; the throwing
	 * placeholder Brain\Monkey defines would keep function_exists() true for
	 * the rest of the process, so this test must run isolated.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_is_available_false_without_woocommerce(): void {
		self::assertFalse( ( new WooSource() )->is_available() );
	}

	public function test_is_available_true_with_woocommerce(): void {
		Functions\when( 'wc_get_orders' )->justReturn( array() );

		self::assertTrue( ( new WooSource() )->is_available() );
	}

	public function test_unsupported_metric_returns_error(): void {
		$error = ( new WooSource() )->get_series( array( 'metric' => 'profit', 'granularity' => 'day' ) );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'immens_forecast_unsupported_params', $error->get_error_code() );
	}

	public function test_unsupported_granularity_returns_error(): void {
		$error = ( new WooSource() )->get_series( array( 'metric' => 'revenue', 'granularity' => 'month' ) );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'immens_forecast_unsupported_params', $error->get_error_code() );
	}

	public function test_stats_path_builds_contiguous_daily_series(): void {
		$this->seed_stats_rows();

		$payload = ( new WooSource() )->get_series(
			array(
				'metric'        => 'orders',
				'granularity'   => 'day',
				'history_start' => '2026-11-01',
				'horizon'       => 2,
			)
		);

		self::assertSame( array( '2026-11-01', '2026-11-02', '2026-11-03' ), $payload['dates'] );
		self::assertSame( array( 1.0, 1.0, 0.0 ), $payload['values'] );
		self::assertSame( array( '2026-11-04', '2026-11-05' ), $payload['future_dates'] );
	}

	public function test_week_granularity_buckets_from_iso_monday(): void {
		$this->seed_stats_rows();

		$payload = ( new WooSource() )->get_series(
			array(
				'metric'        => 'items',
				'granularity'   => 'week',
				'history_start' => '2026-11-01',
				'horizon'       => 1,
			)
		);

		// 2026-11-01 is a Sunday: its ISO week starts Monday 2026-10-26.
		self::assertSame( array( '2026-10-26', '2026-11-02' ), $payload['dates'] );
		self::assertSame( array( 2.0, 0.0 ), $payload['values'] );
		self::assertSame( array( '2026-11-09' ), $payload['future_dates'] );
	}

	public function test_revenue_subs_tax_when_prices_include_tax(): void {
		$this->seed_stats_rows();
		Functions\when( 'wc_prices_include_tax' )->justReturn( true );

		$payload = ( new WooSource() )->get_series(
			array(
				'metric'        => 'revenue',
				'granularity'   => 'day',
				'history_start' => '2026-11-01',
				'horizon'       => 1,
			)
		);

		// Day 1: 100 - 22. Day 2: (50 - 30) - (11 - 6.6).
		self::assertSame( array( 78.0, 15.6, 0.0 ), $payload['values'] );
	}

	public function test_fallback_path_uses_wc_get_orders(): void {
		Functions\when( 'wc_get_orders' )->alias(
			self::source_orders(
				array( new FakeWooOrder( '2026-11-01 09:00:00', 100.0, 22.0, array( 2 ) ) ),
				array( new FakeWooOrder( '2026-11-02 10:00:00', 30.0, 6.6, array( 1 ) ) )
			)
		);

		$source = new WooSource();
		$args   = array(
			'granularity'   => 'day',
			'history_start' => '2026-11-01',
			'horizon'       => 1,
		);

		$revenue = $source->get_series( array_merge( $args, array( 'metric' => 'revenue' ) ) );
		self::assertSame( array( '2026-11-01', '2026-11-02', '2026-11-03' ), $revenue['dates'] );
		// The refund subtracts on its own date; prices exclude tax by default here.
		self::assertSame( array( 100.0, -30.0, 0.0 ), $revenue['values'] );

		$items = $source->get_series( array_merge( $args, array( 'metric' => 'items' ) ) );
		self::assertSame( array( 2.0, -1.0, 0.0 ), $items['values'] );
	}

	public function test_both_paths_agree_on_the_same_fixture(): void {
		$this->seed_stats_rows();
		Functions\when( 'wc_prices_include_tax' )->justReturn( true );

		$args = array(
			'metric'        => 'revenue',
			'granularity'   => 'day',
			'history_start' => '2026-11-01',
			'horizon'       => 1,
		);

		$from_stats = ( new WooSource() )->get_series( $args );

		// Same numbers through the orders API: drop the stats table.
		$this->wpdb->tables = array();
		Functions\when( 'wc_get_orders' )->alias(
			self::source_orders(
				array(
					new FakeWooOrder( '2026-11-01 09:00:00', 100.0, 22.0, array( 2 ) ),
					new FakeWooOrder( '2026-11-02 08:00:00', 50.0, 11.0, array( 1 ) ),
				),
				array( new FakeWooOrder( '2026-11-02 10:00:00', 30.0, 6.6, array( 1 ) ) )
			)
		);

		$from_orders = ( new WooSource() )->get_series( $args );

		self::assertSame( $from_stats['dates'], $from_orders['dates'] );
		self::assertSame( $from_stats['values'], $from_orders['values'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_series_errors_when_woocommerce_missing(): void {
		$error = ( new WooSource() )->get_series( array( 'metric' => 'revenue', 'granularity' => 'day' ) );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'immens_forecast_source_unavailable', $error->get_error_code() );
	}
}
```

- [ ] **Step 2: Implement WooSource**

`includes/Sources/WooSource.php`:
```php
<?php
/**
 * WooCommerce orders source.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates WooCommerce orders into a daily or weekly metric series.
 *
 * Prefers the WooCommerce analytics stats table (single aggregated query)
 * and falls back to wc_get_orders() in paginated monthly chunks.
 */
final class WooSource implements SourceInterface {

	/**
	 * Metrics exposed by this source.
	 */
	const METRICS = array( 'revenue', 'orders', 'items' );

	/**
	 * Order statuses that count as sales (stats-table format).
	 */
	const ORDER_STATUSES = array( 'wc-completed', 'wc-processing' );

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'woo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'WooCommerce orders', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Revenue, orders and items sold from your store.', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return function_exists( 'wc_get_orders' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_metrics(): array {
		return array(
			'revenue' => array(
				'label'         => __( 'Net revenue', 'immens-forecast' ),
				'granularities' => array( 'day', 'week' ),
			),
			'orders'  => array(
				'label'         => __( 'Orders', 'immens-forecast' ),
				'granularities' => array( 'day', 'week' ),
			),
			'items'   => array(
				'label'         => __( 'Items sold', 'immens-forecast' ),
				'granularities' => array( 'day', 'week' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $args Args (metric, granularity, history_start, horizon).
	 * @return array|\WP_Error
	 */
	public function get_series( array $args ) {
		$metric = isset( $args['metric'] ) ? strtolower( (string) $args['metric'] ) : '';
		if ( ! in_array( $metric, self::METRICS, true ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Unknown WooCommerce metric.', 'immens-forecast' ) );
		}

		$granularity = isset( $args['granularity'] ) ? strtolower( (string) $args['granularity'] ) : '';
		if ( ! in_array( $granularity, array( 'day', 'week' ), true ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Unsupported WooCommerce granularity.', 'immens-forecast' ) );
		}

		$horizon = isset( $args['horizon'] ) ? (int) $args['horizon'] : 30;
		$horizon = max( 1, min( 180, $horizon ) );

		$today         = current_time( 'Y-m-d' );
		$history_start = isset( $args['history_start'] ) ? (string) $args['history_start'] : '';
		if ( ! self::is_valid_date( $history_start ) || strcmp( $history_start, $today ) > 0 ) {
			$history_start = gmdate( 'Y-m-d', strtotime( $today . ' -364 days' ) );
		}

		$daily = $this->stats_daily( $history_start, $today );
		if ( null === $daily ) {
			if ( ! function_exists( 'wc_get_orders' ) ) {
				return new \WP_Error( 'immens_forecast_source_unavailable', __( 'WooCommerce is not available.', 'immens-forecast' ) );
			}
			$daily = $this->orders_daily( $history_start, $today );
		}

		$points = self::to_points( $daily, $granularity, $history_start, $today, $metric );

		return SeriesBuilder::build( $points, $granularity, $horizon, $metric );
	}

	/**
	 * Daily aggregates from the WooCommerce analytics stats table.
	 *
	 * Refunds are separate rows with a non-zero parent_id; their signs
	 * differed across WooCommerce versions, so they are normalized with
	 * ABS() in SQL and subtracted in PHP.
	 *
	 * @param string $start First day, site timezone (Y-m-d).
	 * @param string $end   Last day, site timezone (Y-m-d).
	 * @return array|null Day => aggregates, or null when the table is missing.
	 */
	private function stats_daily( string $start, string $end ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_order_stats';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(date_created) AS bucket,
					SUM(CASE WHEN parent_id = 0 THEN 1 ELSE 0 END) AS orders_count,
					SUM(CASE WHEN parent_id = 0 THEN total_sales ELSE 0 END) AS gross,
					SUM(CASE WHEN parent_id = 0 THEN tax_total ELSE 0 END) AS tax,
					SUM(CASE WHEN parent_id = 0 THEN num_items_sold ELSE 0 END) AS items,
					SUM(CASE WHEN parent_id <> 0 THEN ABS(total_sales) ELSE 0 END) AS refund_gross,
					SUM(CASE WHEN parent_id <> 0 THEN ABS(tax_total) ELSE 0 END) AS refund_tax,
					SUM(CASE WHEN parent_id <> 0 THEN ABS(num_items_sold) ELSE 0 END) AS refund_items
				FROM %i
				WHERE date_created >= %s AND date_created <= %s AND status IN (%s, %s)
				GROUP BY DATE(date_created)',
				$table,
				$start . ' 00:00:00',
				$end . ' 23:59:59',
				self::ORDER_STATUSES[0],
				self::ORDER_STATUSES[1]
			)
		);

		if ( ! is_array( $rows ) ) {
			return null;
		}

		$prices_include_tax = function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax();

		$daily = array();
		foreach ( $rows as $row ) {
			$revenue = (float) $row->gross - (float) $row->refund_gross;
			if ( $prices_include_tax ) {
				$revenue -= (float) $row->tax - (float) $row->refund_tax;
			}
			$daily[ (string) $row->bucket ] = array(
				'orders'  => (int) $row->orders_count,
				'revenue' => $revenue,
				'items'   => (int) $row->items - (int) $row->refund_items,
			);
		}

		return $daily;
	}

	/**
	 * Daily aggregates via wc_get_orders(), month by month.
	 *
	 * Refunds are bucketed by their own date, matching the stats table.
	 *
	 * @param string $start First day (Y-m-d).
	 * @param string $end   Last day (Y-m-d).
	 * @return array Day => aggregates.
	 */
	private function orders_daily( string $start, string $end ): array {
		$daily = array();

		foreach ( self::month_chunks( $start, $end ) as $chunk ) {
			foreach ( self::fetch_orders( 'shop_order', $chunk[0], $chunk[1] ) as $order ) {
				$day = $order->get_date_created()->date( 'Y-m-d' );
				if ( ! isset( $daily[ $day ] ) ) {
					$daily[ $day ] = array(
						'orders'  => 0,
						'revenue' => 0.0,
						'items'   => 0,
					);
				}
				$daily[ $day ]['orders']++;
				$daily[ $day ]['revenue'] += self::net_total( $order );
				$daily[ $day ]['items']   += self::items_count( $order );
			}

			foreach ( self::fetch_orders( 'shop_order_refund', $chunk[0], $chunk[1] ) as $refund ) {
				$day = $refund->get_date_created()->date( 'Y-m-d' );
				if ( ! isset( $daily[ $day ] ) ) {
					$daily[ $day ] = array(
						'orders'  => 0,
						'revenue' => 0.0,
						'items'   => 0,
					);
				}
				$daily[ $day ]['revenue'] -= abs( self::net_total( $refund ) );
				$daily[ $day ]['items']   -= self::items_count( $refund );
			}
		}

		return $daily;
	}

	/**
	 * All orders of a type inside an inclusive day range, paginated.
	 *
	 * @param string $type  shop_order or shop_order_refund.
	 * @param string $start First day (Y-m-d).
	 * @param string $end   Last day (Y-m-d).
	 * @return array Order objects.
	 */
	private static function fetch_orders( string $type, string $start, string $end ): array {
		$orders = array();
		$page   = 1;

		do {
			$query_args = array(
				'type'         => $type,
				'date_created' => $start . '...' . $end,
				'limit'        => 100,
				'page'         => $page,
				'paginate'     => true,
			);
			if ( 'shop_order' === $type ) {
				$query_args['status'] = self::ORDER_STATUSES;
			}

			$result = wc_get_orders( $query_args );
			$batch  = isset( $result->orders ) ? (array) $result->orders : array();
			$pages  = isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1;

			$orders = array_merge( $orders, $batch );
			$page++;
		} while ( $page <= $pages );

		return $orders;
	}

	/**
	 * Order total net of tax when the store displays prices tax-inclusive.
	 *
	 * @param object $order WC_Order-like object.
	 * @return float
	 */
	private static function net_total( $order ): float {
		$total = (float) $order->get_total();
		if ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ) {
			$total -= (float) $order->get_total_tax();
		}
		return $total;
	}

	/**
	 * Sum of line-item quantities in an order.
	 *
	 * @param object $order WC_Order-like object.
	 * @return int
	 */
	private static function items_count( $order ): int {
		$items = 0;
		foreach ( $order->get_items() as $item ) {
			$items += (int) $item->get_quantity();
		}
		return $items;
	}

	/**
	 * Turn daily aggregates into a contiguous series for one metric.
	 *
	 * Missing buckets are filled with 0.0: for counters and sums,
	 * "no rows" is data, not a gap. Week buckets start on ISO Mondays.
	 *
	 * @param array  $daily       Day => aggregates.
	 * @param string $granularity day or week.
	 * @param string $start       First day (Y-m-d).
	 * @param string $end         Last day (Y-m-d).
	 * @param string $metric      Metric id.
	 * @return array Points ( d, v ).
	 */
	private static function to_points( array $daily, string $granularity, string $start, string $end, string $metric ): array {
		if ( 'week' === $granularity ) {
			$start = self::week_start( $start );
			$end   = self::week_start( $end );
		}

		$totals = array();
		foreach ( $daily as $day => $values ) {
			$bucket = ( 'week' === $granularity ) ? self::week_start( (string) $day ) : (string) $day;
			if ( strcmp( $bucket, $start ) < 0 || strcmp( $bucket, $end ) > 0 ) {
				continue;
			}
			if ( ! isset( $totals[ $bucket ] ) ) {
				$totals[ $bucket ] = 0.0;
			}
			$totals[ $bucket ] += $values[ $metric ];
		}

		$interval = ( 'week' === $granularity ) ? '+7 days' : '+1 day';
		$points   = array();
		$day      = $start;
		while ( strcmp( $day, $end ) <= 0 ) {
			$points[] = array(
				'd' => $day,
				'v' => isset( $totals[ $day ] ) ? (float) $totals[ $day ] : 0.0,
			);
			$day = gmdate( 'Y-m-d', strtotime( $day . ' ' . $interval ) );
		}

		return $points;
	}

	/**
	 * Monday of the ISO week containing a date.
	 *
	 * @param string $date Y-m-d.
	 * @return string Y-m-d.
	 */
	private static function week_start( string $date ): string {
		$timestamp = strtotime( $date . ' 12:00:00' );
		$dow       = (int) gmdate( 'N', $timestamp ); // 1 (Monday) … 7 (Sunday).
		return gmdate( 'Y-m-d', $timestamp - ( $dow - 1 ) * 86400 );
	}

	/**
	 * Inclusive monthly chunks covering a day range.
	 *
	 * @param string $start First day (Y-m-d).
	 * @param string $end   Last day (Y-m-d).
	 * @return array List of array( first, last ) day pairs.
	 */
	private static function month_chunks( string $start, string $end ): array {
		$chunks  = array();
		$current = $start;
		while ( strcmp( $current, $end ) <= 0 ) {
			$month_end = gmdate( 'Y-m-t', strtotime( $current . ' 12:00:00' ) );
			$chunk_end = ( strcmp( $month_end, $end ) > 0 ) ? $end : $month_end;
			$chunks[]  = array( $current, $chunk_end );
			$current   = gmdate( 'Y-m-d', strtotime( $chunk_end . ' +1 day' ) );
		}
		return $chunks;
	}

	/**
	 * Strict Y-m-d validation.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private static function is_valid_date( string $date ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			return false;
		}
		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}
}
```

- [ ] **Step 3: Run the WooSource tests, then PHPCS and commit**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter WooSourceTest
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(sources): add WooCommerce orders source"
```

Expected: `OK (11 tests)` then `0 errors`.

### Task 9: Provider interface and registry

**Files:**
- Create: `includes/Forecast/Providers/ProviderInterface.php`
- Create: `includes/Forecast/Providers/ProviderRegistry.php`
- Create: `tests/unit/Forecast/Providers/StubProvider.php`
- Create: `tests/unit/Forecast/Providers/ProviderRegistryTest.php`

**Interfaces:**
- Consumes: WordPress `apply_filters` (registry build).
- Produces:
  - `ImmensForecast\Forecast\Providers\ProviderInterface` — `get_id()`, `get_label()`, `get_description()`, `get_capabilities()`, `is_available()`, `forecast( array $series, int $horizon, array $options )` (array|\WP_Error), `health()` (array|\WP_Error). Capability keys: `max_horizon`, `max_series`, `max_context`, `supports_multi_series`, `supports_known_covariates`, `supports_past_covariates`, `supports_categorical`, `quantile_levels` (float[]), `requires_remote` (bool).
  - `ImmensForecast\Forecast\Providers\ProviderRegistry` — `add()`, `get()`, `has()`, `all()`, `ids()`, `available()`, static `build()` reading the `immens_forecast_providers` filter (default empty; the free plugin registers `baseline` and `endpoint` in Task 15; non-`ProviderInterface` values are ignored).

- [ ] **Step 1: Write the interface, the stub and the failing registry tests**

`includes/Forecast/Providers/ProviderInterface.php`:
```php
<?php
/**
 * Forecast provider contract.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * A forecasting backend (local baseline, remote endpoint, Pro models).
 *
 * Capability keys: `max_horizon` (int), `max_series` (int), `max_context`
 * (int), `supports_multi_series` (bool), `supports_known_covariates` (bool),
 * `supports_past_covariates` (bool), `supports_categorical` (bool),
 * `quantile_levels` (float[]), `requires_remote` (bool).
 */
interface ProviderInterface {

	/**
	 * Stable provider id (e.g. `baseline`, `endpoint`).
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Short description shown in settings.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Declared capabilities (see interface docblock).
	 *
	 * @return array
	 */
	public function get_capabilities(): array;

	/**
	 * Whether the provider is usable right now.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Forecast one or more series.
	 *
	 * Input series follow the normalized format: `id`, `values`
	 * (`float|null[]`), `past_covariates`, `known_covariates` (length
	 * `n + horizon`) and optional categorical covariates.
	 *
	 * @param array $series  Normalized series list.
	 * @param int   $horizon Steps to forecast.
	 * @param array $options Provider options (model, quantiles, timeout…).
	 * @return array|\WP_Error
	 */
	public function forecast( array $series, int $horizon, array $options );

	/**
	 * Connectivity/health probe for "Test connection".
	 *
	 * @return array|\WP_Error
	 */
	public function health();
}
```

`tests/unit/Forecast/Providers/StubProvider.php`:
```php
<?php
/**
 * Stub provider for registry tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast\Providers
 */

namespace ImmensForecast\Tests\Unit\Forecast\Providers;

use ImmensForecast\Forecast\Providers\ProviderInterface;

/**
 * Stub provider.
 */
final class StubProvider implements ProviderInterface {

	/**
	 * Provider id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Availability flag.
	 *
	 * @var bool
	 */
	private $available;

	/**
	 * Constructor.
	 *
	 * @param string $id        Provider id.
	 * @param bool   $available Availability.
	 */
	public function __construct( string $id = 'stub', bool $available = true ) {
		$this->id        = $id;
		$this->available = $available;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return 'Stub provider';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return 'Stub provider for tests';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capabilities(): array {
		return array(
			'max_horizon'               => 1024,
			'max_series'                => 1,
			'max_context'               => 32768,
			'supports_multi_series'     => false,
			'supports_known_covariates' => true,
			'supports_past_covariates'  => true,
			'supports_categorical'      => false,
			'quantile_levels'           => array( 0.1, 0.5, 0.9 ),
			'requires_remote'           => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $series  Series.
	 * @param int   $horizon Horizon.
	 * @param array $options Options.
	 * @return array
	 */
	public function forecast( array $series, int $horizon, array $options ) {
		return array(
			'series'  => $series,
			'horizon' => $horizon,
			'options' => $options,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function health() {
		return array( 'status' => 'ok' );
	}
}
```

`tests/unit/Forecast/Providers/ProviderRegistryTest.php`:
```php
<?php
/**
 * Provider registry tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast\Providers
 */

namespace ImmensForecast\Tests\Unit\Forecast\Providers;

use Brain\Monkey\Functions;
use ImmensForecast\Forecast\Providers\ProviderRegistry;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Registration, lookup and filtering.
 */
final class ProviderRegistryTest extends TestCase {

	public function test_add_get_has_ids(): void {
		$baseline = new StubProvider( 'baseline' );
		$endpoint = new StubProvider( 'endpoint' );
		$registry = new ProviderRegistry( array( $baseline, $endpoint ) );

		self::assertTrue( $registry->has( 'baseline' ) );
		self::assertSame( $baseline, $registry->get( 'baseline' ) );
		self::assertNull( $registry->get( 'nope' ) );
		self::assertSame( array( 'baseline', 'endpoint' ), $registry->ids() );
		self::assertCount( 2, $registry->all() );
	}

	public function test_duplicate_id_overwrites(): void {
		$registry = new ProviderRegistry();
		$registry->add( new StubProvider( 'baseline' ) );
		$replacement = new StubProvider( 'baseline' );
		$registry->add( $replacement );

		self::assertSame( $replacement, $registry->get( 'baseline' ) );
		self::assertCount( 1, $registry->all() );
	}

	public function test_available_filters_unavailable(): void {
		$registry = new ProviderRegistry(
			array(
				new StubProvider( 'baseline', true ),
				new StubProvider( 'endpoint', false ),
			)
		);

		self::assertSame( array( 'baseline' ), array_keys( $registry->available() ) );
	}

	public function test_build_uses_filter_and_ignores_non_providers(): void {
		Functions\when( 'apply_filters' )->justReturn( array( new StubProvider( 'baseline' ), 'garbage', 42 ) );

		$registry = ProviderRegistry::build();

		self::assertSame( array( 'baseline' ), $registry->ids() );
	}

	public function test_build_with_empty_default_returns_empty_registry(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$registry = ProviderRegistry::build();

		self::assertSame( array(), $registry->ids() );
	}
}
```

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter ProviderRegistryTest
```
Expected: FAIL — `Class "ImmensForecast\Forecast\Providers\ProviderRegistry" not found`.

- [ ] **Step 2: Implement ProviderRegistry**

`includes/Forecast/Providers/ProviderRegistry.php`:
```php
<?php
/**
 * Registry of forecast providers.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Holds providers keyed by id, built from the `immens_forecast_providers` filter.
 */
final class ProviderRegistry {

	/**
	 * Providers keyed by id.
	 *
	 * @var ProviderInterface[]
	 */
	private $providers = array();

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface[] $providers Initial providers.
	 */
	public function __construct( array $providers = array() ) {
		foreach ( $providers as $provider ) {
			$this->add( $provider );
		}
	}

	/**
	 * Register a provider (last one wins on id collision).
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return void
	 */
	public function add( ProviderInterface $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Get a provider by id.
	 *
	 * @param string $id Provider id.
	 * @return ProviderInterface|null
	 */
	public function get( string $id ): ?ProviderInterface {
		return isset( $this->providers[ $id ] ) ? $this->providers[ $id ] : null;
	}

	/**
	 * Whether a provider id is registered.
	 *
	 * @param string $id Provider id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->providers[ $id ] );
	}

	/**
	 * All providers keyed by id.
	 *
	 * @return ProviderInterface[]
	 */
	public function all(): array {
		return $this->providers;
	}

	/**
	 * Registered provider ids.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->providers );
	}

	/**
	 * Only providers that are currently available.
	 *
	 * @return ProviderInterface[]
	 */
	public function available(): array {
		$out = array();
		foreach ( $this->providers as $id => $provider ) {
			if ( $provider->is_available() ) {
				$out[ $id ] = $provider;
			}
		}
		return $out;
	}

	/**
	 * Build a registry from the public filter (free + Pro + third parties).
	 *
	 * @return ProviderRegistry
	 */
	public static function build(): ProviderRegistry {
		$registry = new self();
		$filtered = apply_filters( 'immens_forecast_providers', array() );
		foreach ( (array) $filtered as $provider ) {
			if ( $provider instanceof ProviderInterface ) {
				$registry->add( $provider );
			}
		}
		return $registry;
	}
}
```

- [ ] **Step 3: Run the registry tests, then PHPCS and commit**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter ProviderRegistryTest
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(providers): add ProviderInterface and ProviderRegistry"
```

Expected: `OK (5 tests)` then `0 errors`.

### Task 10: Local baseline provider

**Files:**
- Create: `includes/Forecast/Providers/BaselineProvider.php`
- Create: `tests/unit/Forecast/Providers/BaselineProviderTest.php`

**Interfaces:**
- Consumes: `ProviderInterface` (Task 9), `Math` (Task 3), normalized series (§4.2), `options['quantiles']` (default `[0.1, 0.5, 0.9]`), `options['granularity']` (`day|week`, default `day`), `options['non_negative']` (default `true`).
- Produces: provider payload `['model' => 'baseline', 'horizon' => int, 'quantile_levels' => float[], 'series' => [['id', 'context_used', 'median' => float[], 'quantiles' => ['0.1' => float[], …]]]]`; errors `immens_forecast_insufficient_data` (data: `points`, `finite`) and `immens_forecast_unsupported_params`. Engine (Task 12) wraps the payload with `provider`, `fallback`, `fallback_reason`.
- Decisions: `median` is the point forecast (level × seasonal factor, clamped by `non_negative`); residuals feed the quantile spread only, so `median` is NOT required to equal the `0.5` quantile row (Task 12 must read `median` as the central line and must not derive it from `quantiles['0.5']`). [Corrected during execution: the original "median uses the same residual model at level 0.5" contradicted this task's own verbatim tests — causal residual medians are non-zero bias, e.g. 13.75 vs the expected 10.0 — see Task 10 review.] `context_used` = number of finite values; daily seasonality uses the `dow` covariate, weekly seasonality (period 52) requires a `woy` covariate and ≥104 points, otherwise level-only (WooSource only supplies `dow`, so weekly Woo runs level-only).

- [ ] **Step 1: Write the failing baseline tests**

`tests/unit/Forecast/Providers/BaselineProviderTest.php`:
```php
<?php
/**
 * Baseline provider tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast\Providers
 */

namespace ImmensForecast\Tests\Unit\Forecast\Providers;

use Brain\Monkey\Functions;
use ImmensForecast\Forecast\Providers\BaselineProvider;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Deterministic seasonal baseline.
 */
final class BaselineProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Build a normalized series, deriving past covariates from the full ones.
	 *
	 * @param string $id     Series id.
	 * @param array  $values Values.
	 * @param array  $known  Full-length known covariates.
	 * @return array
	 */
	private function series( string $id, array $values, array $known = array() ): array {
		$series = array(
			'id'     => $id,
			'values' => $values,
		);
		if ( ! empty( $known ) ) {
			$series['known_covariates'] = $known;
			$series['past_covariates']  = array();
			foreach ( $known as $key => $full ) {
				$series['past_covariates'][ $key ] = array_slice( $full, 0, count( $values ) );
			}
		}
		return $series;
	}

	public function test_insufficient_points_error(): void {
		$provider = new BaselineProvider();
		$result   = $provider->forecast( array( $this->series( 'revenue', array_fill( 0, 15, 10.0 ) ) ), 3, array() );

		self::assertSame( 'immens_forecast_insufficient_data', $result->get_error_code() );
		self::assertSame( array( 'points' => 15, 'finite' => 15 ), $result->get_error_data() );
	}

	public function test_insufficient_finite_values_error(): void {
		$provider = new BaselineProvider();
		$values   = array_merge( array( 1.0, 2.0, 3.0 ), array_fill( 0, 13, null ) );
		$result   = $provider->forecast( array( $this->series( 'revenue', $values ) ), 2, array() );

		self::assertSame( 'immens_forecast_insufficient_data', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 16, $data['points'] );
		self::assertSame( 3, $data['finite'] );
	}

	public function test_horizon_out_of_range_error(): void {
		$provider = new BaselineProvider();
		$series   = array( $this->series( 'revenue', array_fill( 0, 16, 10.0 ) ) );

		self::assertSame( 'immens_forecast_unsupported_params', $provider->forecast( $series, 0, array() )->get_error_code() );
		self::assertSame( 'immens_forecast_unsupported_params', $provider->forecast( $series, 181, array() )->get_error_code() );
	}

	public function test_constant_series_flat_band(): void {
		$provider = new BaselineProvider();
		$result   = $provider->forecast(
			array( $this->series( 'revenue', array_fill( 0, 20, 50.0 ) ) ),
			3,
			array( 'quantiles' => array( 0.1, 0.5, 0.9 ) )
		);

		self::assertSame( 'baseline', $result['model'] );
		self::assertSame( 3, $result['horizon'] );
		self::assertSame( array( 0.1, 0.5, 0.9 ), $result['quantile_levels'] );

		$series = $result['series'][0];
		self::assertSame( 'revenue', $series['id'] );
		self::assertSame( 20, $series['context_used'] );
		self::assertSame( array( 50.0, 50.0, 50.0 ), $series['median'] );
		self::assertSame( array( 50.0, 50.0, 50.0 ), $series['quantiles']['0.1'] );
		self::assertSame( array( 50.0, 50.0, 50.0 ), $series['quantiles']['0.9'] );
	}

	public function test_daily_seasonality_repeats_pattern(): void {
		$provider = new BaselineProvider();
		$pattern  = array( 10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0 );
		$values   = array_merge( $pattern, $pattern, $pattern, $pattern );
		$dow      = array();
		for ( $i = 0; $i < 31; $i++ ) {
			$dow[] = $i % 7;
		}

		$result = $provider->forecast(
			array( $this->series( 'revenue', $values, array( 'dow' => $dow ) ) ),
			3,
			array( 'granularity' => 'day' )
		);

		$median = $result['series'][0]['median'];
		self::assertEqualsWithDelta( 10.0, $median[0], 1e-9 );
		self::assertEqualsWithDelta( 20.0, $median[1], 1e-9 );
		self::assertEqualsWithDelta( 30.0, $median[2], 1e-9 );
	}

	public function test_missing_covariates_gives_flat_forecast(): void {
		$provider = new BaselineProvider();
		$values   = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$values[] = ( 0 === $i % 7 ) ? 10.0 : 100.0;
		}

		$result = $provider->forecast( array( $this->series( 'revenue', $values ) ), 3, array() );
		$median = $result['series'][0]['median'];

		self::assertCount( 3, $median );
		self::assertEqualsWithDelta( $median[0], $median[1], 1e-9 );
		self::assertEqualsWithDelta( $median[0], $median[2], 1e-9 );
	}

	public function test_weekly_seasonality_with_woy(): void {
		$provider = new BaselineProvider();
		$pattern  = array();
		for ( $i = 0; $i < 52; $i++ ) {
			$pattern[] = 50.0 + $i;
		}
		$values = array_merge( $pattern, $pattern );
		$woy    = array();
		for ( $i = 0; $i < 156; $i++ ) {
			$woy[] = $i % 52;
		}

		$result = $provider->forecast(
			array( $this->series( 'revenue', $values, array( 'woy' => $woy ) ) ),
			52,
			array( 'granularity' => 'week' )
		);

		$median = $result['series'][0]['median'];
		self::assertCount( 52, $median );
		self::assertGreaterThan( $median[0], $median[1] );
		self::assertGreaterThan( $median[0], $median[51] );
	}

	public function test_weekly_without_woy_is_level_only(): void {
		$provider = new BaselineProvider();
		$pattern  = array();
		for ( $i = 0; $i < 52; $i++ ) {
			$pattern[] = 50.0 + $i;
		}

		$result = $provider->forecast(
			array( $this->series( 'revenue', array_merge( $pattern, $pattern ) ) ),
			6,
			array( 'granularity' => 'week' )
		);

		$median = $result['series'][0]['median'];
		self::assertEqualsWithDelta( $median[0], $median[5], 1e-9 );
	}

	public function test_empirical_quantiles_widen_and_stay_positive(): void {
		$provider = new BaselineProvider();
		$values   = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$values[] = ( 0 === $i % 2 ) ? 100.0 : 200.0;
		}

		$result = $provider->forecast(
			array( $this->series( 'revenue', $values ) ),
			4,
			array( 'quantiles' => array( 0.1, 0.5, 0.9 ) )
		);

		$series = $result['series'][0];
		for ( $step = 0; $step < 4; $step++ ) {
			self::assertLessThan( $series['median'][ $step ], $series['quantiles']['0.1'][ $step ] );
			self::assertGreaterThan( $series['median'][ $step ], $series['quantiles']['0.9'][ $step ] );
			self::assertGreaterThanOrEqual( 0.0, $series['quantiles']['0.1'][ $step ] );
		}
	}

	public function test_non_negative_clamp_applies_by_default(): void {
		$provider = new BaselineProvider();

		// Only 7 in-sample residuals → Gaussian fallback with σ = 1.4826·MAD, wide
		// enough to push the 10% quantile below zero. The level is small (10) because
		// the finite values cycle through 0, 10 and 300.
		$values = array();
		$cycle  = array( 0.0, 10.0, 300.0 );
		for ( $i = 0; $i < 16; $i++ ) {
			$values[] = ( 0 === $i % 2 ) ? $cycle[ ( $i / 2 ) % 3 ] : null;
		}
		$series = $this->series( 'revenue', $values );

		$clamped = $provider->forecast( array( $series ), 2, array( 'quantiles' => array( 0.1, 0.5, 0.9 ) ) );
		$raw     = $provider->forecast(
			array( $series ),
			2,
			array(
				'quantiles'    => array( 0.1, 0.5, 0.9 ),
				'non_negative' => false,
			)
		);

		$clamped_low = $clamped['series'][0]['quantiles']['0.1'][0];
		$raw_low     = $raw['series'][0]['quantiles']['0.1'][0];

		self::assertSame( 0.0, $clamped_low );
		self::assertLessThan( 0.0, $raw_low );
	}

	public function test_multiple_series_are_returned(): void {
		$provider = new BaselineProvider();
		$result   = $provider->forecast(
			array(
				$this->series( 'revenue', array_fill( 0, 18, 100.0 ) ),
				$this->series( 'orders', array_fill( 0, 18, 5.0 ) ),
			),
			2,
			array()
		);

		self::assertSame( array( 'revenue', 'orders' ), array_column( $result['series'], 'id' ) );
		self::assertSame( 5.0, $result['series'][1]['median'][1] );
	}
}
```

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter BaselineProviderTest
```
Expected: FAIL — `Class "ImmensForecast\Forecast\Providers\BaselineProvider" not found`.

- [ ] **Step 2: Implement BaselineProvider**

`includes/Forecast/Providers/BaselineProvider.php`:
```php
<?php
/**
 * Deterministic local baseline provider.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast\Providers;

use ImmensForecast\Forecast\Math;

defined( 'ABSPATH' ) || exit;

/**
 * Seasonal baseline: level · seasonal factor plus residual quantiles.
 */
final class BaselineProvider implements ProviderInterface {

	const MIN_POINTS              = 16;
	const MIN_FINITE              = 4;
	const LEVEL_WINDOW            = 28;
	const RESIDUAL_WINDOW         = 90;
	const MIN_EMPIRICAL_RESIDUALS = 10;
	const MAX_HORIZON             = 180;
	const MAX_SERIES              = 20;
	const MAX_CONTEXT             = 5000;
	const MIN_FACTOR              = 0.2;
	const MAX_FACTOR              = 5.0;
	const DEFAULT_QUANTILES       = array( 0.1, 0.5, 0.9 );

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'baseline';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Local baseline', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Deterministic seasonal baseline, runs offline in PHP.', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capabilities(): array {
		$levels = array();
		for ( $i = 1; $i <= 99; $i++ ) {
			$levels[] = $i / 100;
		}

		return array(
			'max_horizon'               => self::MAX_HORIZON,
			'max_series'                => self::MAX_SERIES,
			'max_context'               => self::MAX_CONTEXT,
			'supports_multi_series'     => true,
			'supports_known_covariates' => true,
			'supports_past_covariates'  => false,
			'supports_categorical'      => false,
			'quantile_levels'           => $levels,
			'requires_remote'           => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $series  Normalized series list.
	 * @param int   $horizon Steps to forecast.
	 * @param array $options Options.
	 * @return array|\WP_Error
	 */
	public function forecast( array $series, int $horizon, array $options ) {
		if ( $horizon < 1 || $horizon > self::MAX_HORIZON ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'The requested horizon is not supported.', 'immens-forecast' ) );
		}

		$levels       = $this->normalize_levels( isset( $options['quantiles'] ) ? $options['quantiles'] : null );
		$granularity  = ( isset( $options['granularity'] ) && 'week' === $options['granularity'] ) ? 'week' : 'day';
		$non_negative = ! isset( $options['non_negative'] ) || (bool) $options['non_negative'];

		$out = array();
		foreach ( $series as $item ) {
			$result = $this->forecast_one( (array) $item, $horizon, $levels, $granularity, $non_negative );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$out[] = $result;
		}

		return array(
			'model'           => 'baseline',
			'horizon'         => $horizon,
			'quantile_levels' => $levels,
			'series'          => $out,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function health() {
		return array(
			'status'  => 'ok',
			'offline' => true,
		);
	}

	/**
	 * Keep valid quantile levels, defaulting to 0.1/0.5/0.9.
	 *
	 * @param mixed $levels Requested levels.
	 * @return float[]
	 */
	private function normalize_levels( $levels ): array {
		$clean = array();
		if ( is_array( $levels ) ) {
			foreach ( $levels as $level ) {
				if ( ! is_numeric( $level ) ) {
					continue;
				}
				$level = (float) $level;
				if ( $level > 0.0 && $level < 1.0 ) {
					$clean[] = $level;
				}
			}
		}
		if ( empty( $clean ) ) {
			$clean = self::DEFAULT_QUANTILES;
		}
		$clean = array_values( array_unique( $clean ) );
		sort( $clean );
		return $clean;
	}

	/**
	 * Forecast a single series.
	 *
	 * @param array  $item        Series.
	 * @param int    $horizon     Horizon.
	 * @param float[] $levels     Quantile levels.
	 * @param string $granularity day|week.
	 * @param bool   $non_negative Clamp at zero.
	 * @return array|\WP_Error
	 */
	private function forecast_one( array $item, int $horizon, array $levels, string $granularity, bool $non_negative ) {
		$id     = isset( $item['id'] ) ? (string) $item['id'] : 'series';
		$values = ( isset( $item['values'] ) && is_array( $item['values'] ) ) ? array_values( $item['values'] ) : array();
		$n      = count( $values );
		$finite = Math::finite( $values );

		if ( $n < self::MIN_POINTS || count( $finite ) < self::MIN_FINITE ) {
			return new \WP_Error(
				'immens_forecast_insufficient_data',
				sprintf(
					/* translators: 1: points, 2: finite values, 3: minimum points, 4: minimum finite values. */
					__( 'Not enough data: %1$d points with %2$d finite values (minimum %3$d points and %4$d finite values).', 'immens-forecast' ),
					$n,
					count( $finite ),
					self::MIN_POINTS,
					self::MIN_FINITE
				),
				array(
					'points' => $n,
					'finite' => count( $finite ),
				)
			);
		}

		$period = ( 'week' === $granularity ) ? 52 : 7;
		$key    = ( 'week' === $granularity ) ? 'woy' : 'dow';

		$past    = $this->in_sample_covariate( $item, $key, $n );
		$future  = $this->future_covariate( $item, $key, $n, $horizon );
		$level   = Math::median( array_slice( $finite, -self::LEVEL_WINDOW ) );
		$factors = $this->seasonal_factors( $values, $past, $period );
		$resid   = $this->residuals( $values, $past, $factors, $period );

		$empirical = count( $resid ) >= self::MIN_EMPIRICAL_RESIDUALS;
		$sigma     = 1.4826 * Math::mad( $resid );

		$median    = array();
		$quantiles = array();
		foreach ( $levels as $quantile_level ) {
			$quantiles[ Math::format_level( $quantile_level ) ] = array();
		}

		for ( $step = 0; $step < $horizon; $step++ ) {
			$factor = 1.0;
			if ( null !== $future && isset( $future[ $step ] ) && is_numeric( $future[ $step ] ) ) {
				$slot   = ( (int) $future[ $step ] ) % $period;
				$factor = isset( $factors[ $slot ] ) ? $factors[ $slot ] : 1.0;
			}
			$point    = $level * $factor;
			$median[] = $non_negative ? max( 0.0, $point ) : $point;
			foreach ( $levels as $quantile_level ) {
				$quantiles[ Math::format_level( $quantile_level ) ][] = $this->spread( $point, $quantile_level, $resid, $empirical, $sigma, $non_negative );
			}
		}

		return array(
			'id'           => $id,
			'context_used' => count( $finite ),
			'median'       => $median,
			'quantiles'    => $quantiles,
		);
	}

	/**
	 * Final forecast value for one step and level.
	 *
	 * @param float   $point        Point forecast.
	 * @param float   $level        Quantile level.
	 * @param float[] $residuals    In-sample residuals.
	 * @param bool    $empirical    Use empirical residuals.
	 * @param float   $sigma        Gaussian sigma.
	 * @param bool    $non_negative Clamp at zero.
	 * @return float
	 */
	private function spread( float $point, float $level, array $residuals, bool $empirical, float $sigma, bool $non_negative ): float {
		$spread = $empirical ? Math::quantile( $residuals, $level ) : $sigma * $this->normal_quantile( $level );
		$value  = $point + $spread;
		return $non_negative ? max( 0.0, $value ) : $value;
	}

	/**
	 * In-sample covariate slice (past first, known as fallback).
	 *
	 * @param array  $item Series.
	 * @param string $key  Covariate key.
	 * @param int    $n    Series length.
	 * @return array|null
	 */
	private function in_sample_covariate( array $item, string $key, int $n ): ?array {
		foreach ( array( 'past_covariates', 'known_covariates' ) as $group ) {
			if ( isset( $item[ $group ][ $key ] ) && is_array( $item[ $group ][ $key ] ) ) {
				$source = array_values( $item[ $group ][ $key ] );
				if ( count( $source ) >= $n ) {
					return array_slice( $source, 0, $n );
				}
			}
		}
		return null;
	}

	/**
	 * Future covariate slice from known_covariates (length n + horizon).
	 *
	 * @param array  $item    Series.
	 * @param string $key     Covariate key.
	 * @param int    $n       Series length.
	 * @param int    $horizon Horizon.
	 * @return array|null
	 */
	private function future_covariate( array $item, string $key, int $n, int $horizon ): ?array {
		if ( ! isset( $item['known_covariates'][ $key ] ) || ! is_array( $item['known_covariates'][ $key ] ) ) {
			return null;
		}
		$source = array_values( $item['known_covariates'][ $key ] );
		if ( count( $source ) < $n + $horizon ) {
			return null;
		}
		return array_slice( $source, $n, $horizon );
	}

	/**
	 * Seasonal factors s(k): median of value / M per slot, clamped.
	 *
	 * @param array      $values     Values.
	 * @param array|null $covariates Slot indices aligned with values.
	 * @param int        $period     Season length.
	 * @return float[]
	 */
	private function seasonal_factors( array $values, ?array $covariates, int $period ): array {
		$factors = array_fill( 0, $period, 1.0 );
		if ( null === $covariates || count( $covariates ) < 2 * $period ) {
			return $factors;
		}
		$m = Math::median( $values );
		if ( $m <= 0.0 ) {
			return $factors;
		}
		$buckets = array_fill( 0, $period, array() );
		foreach ( $values as $index => $value ) {
			if ( ! is_numeric( $value ) || ! isset( $covariates[ $index ] ) || ! is_numeric( $covariates[ $index ] ) ) {
				continue;
			}
			$slot               = ( (int) $covariates[ $index ] ) % $period;
			$buckets[ $slot ][] = (float) $value / $m;
		}
		foreach ( $buckets as $slot => $ratios ) {
			if ( ! empty( $ratios ) ) {
				$factors[ $slot ] = Math::clamp( Math::median( $ratios ), self::MIN_FACTOR, self::MAX_FACTOR );
			}
		}
		return $factors;
	}

	/**
	 * In-sample residuals over the last min(90, n) finite points.
	 *
	 * @param array      $values     Values.
	 * @param array|null $covariates Slot indices aligned with values.
	 * @param float[]    $factors    Seasonal factors.
	 * @param int        $period     Season length.
	 * @return float[]
	 */
	private function residuals( array $values, ?array $covariates, array $factors, int $period ): array {
		$n     = count( $values );
		$start = max( 0, $n - self::RESIDUAL_WINDOW );
		$out   = array();
		for ( $i = $start; $i < $n; $i++ ) {
			if ( ! is_numeric( $values[ $i ] ) ) {
				continue;
			}
			$from = max( 0, $i - self::LEVEL_WINDOW );
			$prev = Math::finite( array_slice( $values, $from, $i - $from ) );
			if ( count( $prev ) < 1 ) {
				continue;
			}
			$factor = 1.0;
			if ( null !== $covariates && isset( $covariates[ $i ] ) && is_numeric( $covariates[ $i ] ) ) {
				$slot   = ( (int) $covariates[ $i ] ) % $period;
				$factor = isset( $factors[ $slot ] ) ? $factors[ $slot ] : 1.0;
			}
			$out[] = (float) $values[ $i ] - ( Math::median( $prev ) * $factor );
		}
		return $out;
	}

	/**
	 * Standard normal quantile (Acklam's rational approximation).
	 *
	 * @param float $p Probability in (0,1).
	 * @return float
	 */
	private function normal_quantile( float $p ): float {
		$a = array( -3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00 );
		$b = array( -5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01 );
		$c = array( -7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00 );
		$d = array( 7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00 );

		$p = Math::clamp( $p, 1e-9, 1.0 - 1e-9 );
		if ( $p < 0.02425 ) {
			$q = sqrt( -2.0 * log( $p ) );
			return ( ( ( ( ( $c[0] * $q + $c[1] ) * $q + $c[2] ) * $q + $c[3] ) * $q + $c[4] ) * $q + $c[5] ) / ( ( ( ( $d[0] * $q + $d[1] ) * $q + $d[2] ) * $q + $d[3] ) * $q + 1.0 );
		}
		if ( $p > 0.97575 ) {
			$q = sqrt( -2.0 * log( 1.0 - $p ) );
			return -( ( ( ( ( $c[0] * $q + $c[1] ) * $q + $c[2] ) * $q + $c[3] ) * $q + $c[4] ) * $q + $c[5] ) / ( ( ( ( $d[0] * $q + $d[1] ) * $q + $d[2] ) * $q + $d[3] ) * $q + 1.0 );
		}
		$q = $p - 0.5;
		$r = $q * $q;
		return ( ( ( ( ( $a[0] * $r + $a[1] ) * $r + $a[2] ) * $r + $a[3] ) * $r + $a[4] ) * $r + $a[5] ) * $q / ( ( ( ( ( $b[0] * $r + $b[1] ) * $r + $b[2] ) * $r + $b[3] ) * $r + $b[4] ) * $r + 1.0 );
	}
}
```

- [ ] **Step 3: Run the baseline tests, then PHPCS and commit**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter BaselineProviderTest
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(providers): add BaselineProvider"
```

Expected: `OK (11 tests)` then `0 errors`.

### Task 11: Remote endpoint provider

**Files:**
- Create: `includes/Forecast/Providers/EndpointProvider.php`
- Create: `tests/unit/Forecast/Providers/EndpointProviderTest.php`

**Interfaces:**
- Consumes: `ProviderInterface` (Task 9), `Math` (Task 3), `Admin\Settings` (Task 4: `endpoint_url`, `endpoint_token_enc`, `endpoint_model`, `endpoint_timeout`, `quantiles`), filters `immens_forecast_sslverify` and `immens_forecast_endpoint_headers`, `wp_remote_post` / `wp_remote_get`.
- Produces: same provider payload shape as Task 10 (`model | horizon | quantile_levels | series`); errors `immens_forecast_provider_unavailable`, `immens_forecast_endpoint_unreachable`, `immens_forecast_endpoint_timeout`, `immens_forecast_endpoint_unauthorized`, `immens_forecast_endpoint_http` (data `status`), `immens_forecast_endpoint_bad_response`.
- Decisions: `is_available()` = endpoint URL configured; the `Authorization: Bearer` header is added only when a decrypted token exists; `median` is taken from the response or derived from the `0.5` quantile (§4.4); response quantile keys are canonicalized with `Math::format_level()`; timeout clamped to `Settings::TIMEOUT_MIN..TIMEOUT_MAX`.

- [ ] **Step 1: Write the failing endpoint tests**

`tests/unit/Forecast/Providers/EndpointProviderTest.php`:
```php
<?php
/**
 * Endpoint provider tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast\Providers
 */

namespace ImmensForecast\Tests\Unit\Forecast\Providers;

use Brain\Monkey\Functions;
use ImmensForecast\Admin\Settings;
use ImmensForecast\Forecast\Providers\EndpointProvider;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * HTTP contract, mapping and error translation.
 */
final class EndpointProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Settings::reset_cache();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return isset( $response['body'] ) ? (string) $response['body'] : '';
			}
		);
	}

	protected function tearDown(): void {
		Settings::reset_cache();
		parent::tearDown();
	}

	/**
	 * Store settings for the provider.
	 *
	 * @param array $settings Settings subset.
	 * @return void
	 */
	private function configure( array $settings ): void {
		Functions\when( 'get_option' )->justReturn( $settings );
		Settings::reset_cache();
	}

	public function test_is_available_requires_url(): void {
		$provider = new EndpointProvider();

		$this->configure( array() );
		self::assertFalse( $provider->is_available() );

		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		self::assertTrue( $provider->is_available() );
	}

	public function test_capabilities_require_remote(): void {
		$capabilities = ( new EndpointProvider() )->get_capabilities();

		self::assertTrue( $capabilities['requires_remote'] );
		self::assertSame( 1024, $capabilities['max_horizon'] );
		self::assertCount( 99, $capabilities['quantile_levels'] );
	}

	public function test_forecast_without_url_errors(): void {
		$this->configure( array() );

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 3, array() );

		self::assertSame( 'immens_forecast_provider_unavailable', $result->get_error_code() );
	}

	public function test_forecast_success_maps_payload(): void {
		$this->configure(
			array(
				'endpoint_url'   => 'https://forecast.example.com',
				'endpoint_model' => '209m',
			)
		);
		$payload = array(
			'model'           => array(
				'alias'  => '209m',
				'hub_id' => 'mistralai/Timestral-209M',
			),
			'horizon'         => 2,
			'quantile_levels' => array( 0.1, 0.5, 0.9 ),
			'series'          => array(
				array(
					'id'           => 'revenue',
					'context_used' => 30,
					'quantiles'    => array(
						'0.1' => array( 1.0, 2.0 ),
						'0.5' => array( 10.0, 20.0 ),
						'0.9' => array( 19.0, 38.0 ),
					),
				),
			),
		);
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( $payload ),
			)
		);

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0, 2.0 ) ) ), 2, array() );

		self::assertSame( '209m', $result['model'] );
		self::assertSame( 2, $result['horizon'] );
		self::assertSame( 'revenue', $result['series'][0]['id'] );
		self::assertSame( 30, $result['series'][0]['context_used'] );
		// Median is derived from the 0.5 quantile when missing in the response.
		self::assertEquals( array( 10.0, 20.0 ), $result['series'][0]['median'] );
		self::assertEquals( array( 19.0, 38.0 ), $result['series'][0]['quantiles']['0.9'] );
	}

	public function test_forecast_unauthorized_error(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '',
			)
		);

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 2, array() );

		self::assertSame( 'immens_forecast_endpoint_unauthorized', $result->get_error_code() );
	}

	public function test_forecast_http_error(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => 'boom',
			)
		);

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 2, array() );

		self::assertSame( 'immens_forecast_endpoint_http', $result->get_error_code() );
		self::assertSame( array( 'status' => 500 ), $result->get_error_data() );
	}

	public function test_forecast_bad_response(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'not-json',
			)
		);

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 2, array() );

		self::assertSame( 'immens_forecast_endpoint_bad_response', $result->get_error_code() );
	}

	public function test_forecast_timeout_error(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 60001 milliseconds' ) );

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 2, array() );

		self::assertSame( 'immens_forecast_endpoint_timeout', $result->get_error_code() );
	}

	public function test_forecast_unreachable_error(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ) );

		$result = ( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 2, array() );

		self::assertSame( 'immens_forecast_endpoint_unreachable', $result->get_error_code() );
	}

	public function test_forecast_sends_json_body_and_headers(): void {
		$this->configure(
			array(
				'endpoint_url'     => 'https://forecast.example.com/',
				'endpoint_model'   => '28m',
				'endpoint_timeout' => 45,
			)
		);
		$captured = array();
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array( 'series' => array() ) ),
				);
			}
		);

		( new EndpointProvider() )->forecast( array( array( 'id' => 'revenue', 'values' => array( 1.0 ) ) ), 2, array() );

		self::assertSame( 'https://forecast.example.com/v1/forecast', $captured['url'] );
		self::assertSame( 45, $captured['args']['timeout'] );
		self::assertTrue( $captured['args']['sslverify'] );
		self::assertArrayNotHasKey( 'Authorization', $captured['args']['headers'] );
		self::assertSame( 'application/json', $captured['args']['headers']['Content-Type'] );

		$body = json_decode( $captured['args']['body'], true );
		self::assertSame( '28m', $body['model'] );
		self::assertSame( 2, $body['horizon'] );
		self::assertSame( array( 0.1, 0.5, 0.9 ), $body['quantiles'] );
	}

	public function test_health_success(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'status' => 'ok' ) ),
			)
		);

		$result = ( new EndpointProvider() )->health();

		self::assertSame( 'ok', $result['status'] );
		self::assertSame( 200, $result['http'] );
	}

	public function test_health_http_error(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 503 ),
				'body'     => '',
			)
		);

		$result = ( new EndpointProvider() )->health();

		self::assertSame( 'immens_forecast_endpoint_http', $result->get_error_code() );
	}
}
```

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter EndpointProviderTest
```
Expected: FAIL — `Class "ImmensForecast\Forecast\Providers\EndpointProvider" not found`.

- [ ] **Step 2: Implement EndpointProvider**

`includes/Forecast/Providers/EndpointProvider.php`:
```php
<?php
/**
 * Remote endpoint provider (bring your own forecast API).
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast\Providers;

use ImmensForecast\Admin\Settings;
use ImmensForecast\Forecast\Math;

defined( 'ABSPATH' ) || exit;

/**
 * Calls a remote endpoint implementing the documented contract (§4.4).
 */
final class EndpointProvider implements ProviderInterface {

	const MAX_HORIZON   = 1024;
	const MAX_SERIES    = 20;
	const MAX_CONTEXT   = 32768;
	const DEFAULT_MODEL = '209m';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'endpoint';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Remote endpoint', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Your own forecasting API, compatible with the documented contract.', 'immens-forecast' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capabilities(): array {
		$levels = array();
		for ( $i = 1; $i <= 99; $i++ ) {
			$levels[] = round( $i / 100, 2 );
		}

		return array(
			'max_horizon'               => self::MAX_HORIZON,
			'max_series'                => self::MAX_SERIES,
			'max_context'               => self::MAX_CONTEXT,
			'supports_multi_series'     => true,
			'supports_known_covariates' => true,
			'supports_past_covariates'  => true,
			'supports_categorical'      => false,
			'quantile_levels'           => $levels,
			'requires_remote'           => true,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return Settings::is_endpoint_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $series  Normalized series list.
	 * @param int   $horizon Steps to forecast.
	 * @param array $options Options.
	 * @return array|\WP_Error
	 */
	public function forecast( array $series, int $horizon, array $options ) {
		$base = $this->base_url();
		if ( '' === $base ) {
			return new \WP_Error( 'immens_forecast_provider_unavailable', __( 'No endpoint URL is configured.', 'immens-forecast' ) );
		}

		$model = ( isset( $options['model'] ) && is_string( $options['model'] ) && '' !== $options['model'] )
			? $options['model']
			: (string) Settings::get( 'endpoint_model', self::DEFAULT_MODEL );
		$levels = $this->normalize_levels( isset( $options['quantiles'] ) ? $options['quantiles'] : null );

		$response = wp_remote_post(
			$base . '/v1/forecast',
			array(
				'timeout'   => $this->timeout(),
				'sslverify' => $this->sslverify(),
				'headers'   => $this->headers(),
				'body'      => wp_json_encode(
					array(
						'series'    => array_values( $series ),
						'horizon'   => $horizon,
						'model'     => $model,
						'quantiles' => $levels,
					)
				),
			)
		);

		return $this->parse_response( $response, $horizon, $levels );
	}

	/**
	 * {@inheritDoc}
	 */
	public function health() {
		$base = $this->base_url();
		if ( '' === $base ) {
			return new \WP_Error( 'immens_forecast_provider_unavailable', __( 'No endpoint URL is configured.', 'immens-forecast' ) );
		}

		$response = wp_remote_get(
			$base . '/health',
			array(
				'timeout'   => $this->timeout(),
				'sslverify' => $this->sslverify(),
			)
		);

		$error = $this->transport_error( $response );
		if ( null !== $error ) {
			return $error;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return $this->http_error( $status );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return array(
			'status' => 'ok',
			'http'   => $status,
			'body'   => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Trimmed base URL without a trailing slash.
	 *
	 * @return string
	 */
	private function base_url(): string {
		return rtrim( trim( (string) Settings::get( 'endpoint_url', '' ) ), '/' );
	}

	/**
	 * Clamped request timeout.
	 *
	 * @return int
	 */
	private function timeout(): int {
		$timeout = (int) Settings::get( 'endpoint_timeout', 60 );
		return max( Settings::TIMEOUT_MIN, min( Settings::TIMEOUT_MAX, $timeout ) );
	}

	/**
	 * Whether TLS verification is enabled (filterable for development).
	 *
	 * @return bool
	 */
	private function sslverify(): bool {
		return (bool) apply_filters( 'immens_forecast_sslverify', true );
	}

	/**
	 * Request headers, including the bearer token when configured.
	 *
	 * @return array
	 */
	private function headers(): array {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		$token = Settings::endpoint_token();
		if ( null !== $token && '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$extra = apply_filters( 'immens_forecast_endpoint_headers', array() );
		if ( is_array( $extra ) ) {
			$headers = array_merge( $headers, $extra );
		}

		return $headers;
	}

	/**
	 * Keep valid quantile levels, sorted and unique.
	 *
	 * @param mixed $levels Requested levels.
	 * @return float[]
	 */
	private function normalize_levels( $levels ): array {
		if ( ! is_array( $levels ) ) {
			$levels = Settings::get( 'quantiles', array( 0.1, 0.5, 0.9 ) );
		}

		$clean = array();
		foreach ( (array) $levels as $level ) {
			if ( ! is_numeric( $level ) ) {
				continue;
			}
			$level = (float) $level;
			if ( $level > 0.0 && $level < 1.0 ) {
				$clean[] = $level;
			}
		}
		if ( empty( $clean ) ) {
			$clean = array( 0.1, 0.5, 0.9 );
		}

		$clean = array_values( array_unique( $clean ) );
		sort( $clean );
		return $clean;
	}

	/**
	 * Map a transport failure to a WP_Error.
	 *
	 * @param mixed $response HTTP response or WP_Error.
	 * @return \WP_Error|null
	 */
	private function transport_error( $response ) {
		if ( ! is_wp_error( $response ) ) {
			return null;
		}

		$message = $response->get_error_message();
		$timeout = ( false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' ) );

		return new \WP_Error(
			$timeout ? 'immens_forecast_endpoint_timeout' : 'immens_forecast_endpoint_unreachable',
			$message
		);
	}

	/**
	 * Map a non-2xx status to a WP_Error.
	 *
	 * @param int $status HTTP status.
	 * @return \WP_Error
	 */
	private function http_error( int $status ) {
		if ( 401 === $status || 403 === $status ) {
			return new \WP_Error(
				'immens_forecast_endpoint_unauthorized',
				__( 'The endpoint rejected the credentials.', 'immens-forecast' ),
				array( 'status' => $status )
			);
		}

		return new \WP_Error(
			'immens_forecast_endpoint_http',
			/* translators: %d: HTTP status code. */
			sprintf( __( 'The endpoint returned HTTP %d.', 'immens-forecast' ), $status ),
			array( 'status' => $status )
		);
	}

	/**
	 * Parse and normalize a forecast response.
	 *
	 * @param mixed   $response HTTP response or WP_Error.
	 * @param int     $horizon  Requested horizon.
	 * @param float[] $levels   Requested levels.
	 * @return array|\WP_Error
	 */
	private function parse_response( $response, int $horizon, array $levels ) {
		$error = $this->transport_error( $response );
		if ( null !== $error ) {
			return $error;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return $this->http_error( $status );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['series'] ) || ! is_array( $data['series'] ) ) {
			return new \WP_Error( 'immens_forecast_endpoint_bad_response', __( 'The endpoint did not return a valid forecast payload.', 'immens-forecast' ) );
		}

		$model = self::DEFAULT_MODEL;
		if ( isset( $data['model'] ) ) {
			if ( is_array( $data['model'] ) && isset( $data['model']['alias'] ) ) {
				$model = (string) $data['model']['alias'];
			} elseif ( is_string( $data['model'] ) ) {
				$model = $data['model'];
			}
		}

		$quantile_levels = ( isset( $data['quantile_levels'] ) && is_array( $data['quantile_levels'] ) )
			? array_values( $data['quantile_levels'] )
			: $levels;

		return array(
			'model'           => $model,
			'horizon'         => isset( $data['horizon'] ) ? (int) $data['horizon'] : $horizon,
			'quantile_levels' => $quantile_levels,
			'series'          => $this->normalize_series( $data['series'] ),
		);
	}

	/**
	 * Normalize the returned series list.
	 *
	 * @param array $items Raw series list.
	 * @return array
	 */
	private function normalize_series( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			$item      = (array) $item;
			$quantiles = array();

			if ( isset( $item['quantiles'] ) && is_array( $item['quantiles'] ) ) {
				foreach ( $item['quantiles'] as $level => $values ) {
					$key               = is_string( $level ) ? $level : Math::format_level( (float) $level );
					$quantiles[ $key ] = array_values( (array) $values );
				}
			}

			if ( isset( $item['median'] ) && is_array( $item['median'] ) ) {
				$median = array_values( $item['median'] );
			} elseif ( isset( $quantiles['0.5'] ) ) {
				$median = $quantiles['0.5'];
			} else {
				$median = array();
			}

			$out[] = array(
				'id'           => isset( $item['id'] ) ? (string) $item['id'] : 'series',
				'context_used' => isset( $item['context_used'] ) ? (int) $item['context_used'] : 0,
				'median'       => $median,
				'quantiles'    => $quantiles,
			);
		}
		return $out;
	}
}
```

- [ ] **Step 3: Run the endpoint tests, then PHPCS and commit**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter EndpointProviderTest
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(providers): add EndpointProvider"
```

Expected: `OK (12 tests)` then `0 errors`.

### Task 12: Cache, Result and Engine

**Files:**
- Create: `includes/Forecast/Cache.php`
- Create: `includes/Forecast/Result.php`
- Create: `includes/Forecast/Engine.php`
- Create: `tests/unit/Forecast/CacheTest.php`
- Create: `tests/unit/Forecast/ResultTest.php`
- Create: `tests/unit/Forecast/EngineTest.php`

**Interfaces:**
- Consumes: `SourceRegistry::build()->get()` + `SourceInterface::get_series()`/`get_metrics()` (Task 6/7), `SeriesBuilder::build()` (Task 7), `ProviderRegistry::build()->get()` (Task 9), `Math::format_level()` (Task 3), `Settings` (Task 4).
- Produces:
  - `Cache::PREFIX`, `Cache::key( array $parts ): string` (md5 hash suffixed to the prefix), `Cache::version(): string`, `Cache::ttl(): int` (default 43200 s, filter `immens_forecast_cache_ttl`, clamp ≥60), `Cache::get( string $key )`, `Cache::set( string $key, array $value ): void`, `Cache::forget( string $key ): void`, `Cache::lock_key( int $user_id ): string`, `Cache::acquire_lock( int $user_id, int $ttl = 60 ): bool`, `Cache::release_lock( int $user_id ): void`.
  - `Result::assemble( array $payload, array $series, array $meta ): array` → UI-ready payload: `provider, model, horizon, quantile_levels, elapsed_ms, cached, fallback, fallback_reason, fallback_code, history{dates,values}, dates, median, quantiles, kpis{forecast_total, previous_total, delta_pct, interval_width}`.
  - `Engine::run( array $params, int $user_id = 0 ): array|\WP_Error` with `$params` keys `source, metric, granularity, history_days, horizon, provider, model, quantiles, refresh`; errors `immens_forecast_source_unavailable`, `immens_forecast_unsupported_params`, `immens_forecast_provider_unavailable`, `immens_forecast_locked`; falls back to the baseline provider on any endpoint error (§7.4) and maps a `429` to `immens_forecast_rate_limited`.
- Decisions: cache key = md5 of the §7.2 concatenation including the last-point hash and the plugin version; the cache is bypassed when `refresh` is true; the anti double-click lock is per user (default 60 s) and released in a `finally` block.

- [ ] **Step 1: Write the failing cache and result tests**

`tests/unit/Forecast/CacheTest.php`:
```php
<?php
/**
 * Cache helper tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast
 */

namespace ImmensForecast\Tests\Unit\Forecast;

use Brain\Monkey\Functions;
use ImmensForecast\Forecast\Cache;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Keys, TTL, transients and locks.
 */
final class CacheTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	public function test_key_is_stable_and_prefixed(): void {
		$parts = array( 'woo', 'revenue', 'day', '2025-01-01', 30, 'baseline', '209m', '0.1,0.5,0.9', 'abc', '1.0.0' );

		$key = Cache::key( $parts );

		self::assertSame( $key, Cache::key( $parts ) );
		self::assertStringStartsWith( 'immens_forecast_', $key );
		self::assertSame( 32, strlen( substr( $key, strlen( 'immens_forecast_' ) ) ) );
	}

	public function test_key_changes_with_parts(): void {
		$base = array( 'woo', 'revenue', 'day', '2025-01-01', 30 );

		self::assertNotSame( Cache::key( $base ), Cache::key( array( 'woo', 'revenue', 'day', '2025-01-01', 60 ) ) );
	}

	public function test_ttl_default_is_12h(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		self::assertSame( 43200, Cache::ttl() );
	}

	public function test_ttl_uses_filter(): void {
		Functions\when( 'apply_filters' )->justReturn( 120 );

		self::assertSame( 120, Cache::ttl() );
	}

	public function test_ttl_clamps_to_minimum(): void {
		Functions\when( 'apply_filters' )->justReturn( 5 );

		self::assertSame( 60, Cache::ttl() );
	}

	public function test_get_delegates_to_transient(): void {
		Functions\expect( 'get_transient' )->once()->with( 'immens_forecast_abc' )->andReturn( array( 'ok' => true ) );

		self::assertSame( array( 'ok' => true ), Cache::get( 'immens_forecast_abc' ) );
	}

	public function test_set_delegates_to_transient_with_ttl(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\expect( 'set_transient' )->once()->with( 'immens_forecast_x', array( 'a' => 1 ), 43200 )->andReturn( true );

		Cache::set( 'immens_forecast_x', array( 'a' => 1 ) );
	}

	public function test_forget_delegates_to_transient(): void {
		Functions\expect( 'delete_transient' )->once()->with( 'immens_forecast_x' )->andReturn( true );

		Cache::forget( 'immens_forecast_x' );
	}

	public function test_lock_acquire_and_release(): void {
		self::assertSame( 'immens_forecast_lock_7', Cache::lock_key( 7 ) );

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		self::assertTrue( Cache::acquire_lock( 7 ) );

		Functions\when( 'get_transient' )->justReturn( 1 );
		self::assertFalse( Cache::acquire_lock( 7 ) );

		Functions\expect( 'delete_transient' )->once()->with( 'immens_forecast_lock_7' )->andReturn( true );
		Cache::release_lock( 7 );
	}
}
```

`tests/unit/Forecast/ResultTest.php`:
```php
<?php
/**
 * Result assembler tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast
 */

namespace ImmensForecast\Tests\Unit\Forecast;

use Brain\Monkey\Functions;
use ImmensForecast\Forecast\Result;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Payload enrichment and KPI maths.
 */
final class ResultTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Build the provider payload fixture.
	 *
	 * @param int $median_points Number of forecast points.
	 * @return array
	 */
	private function payload( int $median_points = 2 ): array {
		$median = array_slice( array( 40.0, 50.0, 60.0 ), 0, $median_points );
		$low    = array_slice( array( 30.0, 40.0, 50.0 ), 0, $median_points );
		$high   = array_slice( array( 50.0, 60.0, 70.0 ), 0, $median_points );

		return array(
			'model'           => 'baseline-seasonal',
			'horizon'         => 2,
			'quantile_levels' => array( 0.1, 0.5, 0.9 ),
			'series'          => array(
				array(
					'id'           => 'revenue',
					'context_used' => 3,
					'median'       => $median,
					'quantiles'    => array(
						'0.1' => $low,
						'0.5' => $median,
						'0.9' => $high,
					),
				),
			),
		);
	}

	/**
	 * Build the series fixture.
	 *
	 * @return array
	 */
	private function series(): array {
		return array(
			'id'           => 'revenue',
			'dates'        => array( '2025-01-01', '2025-01-02', '2025-01-03' ),
			'values'       => array( 10.0, 20.0, 30.0 ),
			'future_dates' => array( '2025-01-04', '2025-01-05' ),
		);
	}

	/**
	 * Build the meta fixture.
	 *
	 * @param array $overrides Meta overrides.
	 * @return array
	 */
	private function meta( array $overrides = array() ): array {
		return array_merge(
			array(
				'provider'        => 'baseline',
				'model'           => 'baseline-seasonal',
				'horizon'         => 2,
				'quantile_levels' => array( 0.1, 0.5, 0.9 ),
				'elapsed_ms'      => 12,
				'cached'          => false,
				'fallback'        => false,
				'fallback_reason' => null,
				'fallback_code'   => null,
			),
			$overrides
		);
	}

	public function test_assemble_attaches_dates_history_and_slices(): void {
		$result = Result::assemble( $this->payload(), $this->series(), $this->meta() );

		self::assertSame( array( '2025-01-04', '2025-01-05' ), $result['dates'] );
		self::assertSame( array( '2025-01-01', '2025-01-02', '2025-01-03' ), $result['history']['dates'] );
		self::assertSame( array( 10.0, 20.0, 30.0 ), $result['history']['values'] );
		self::assertCount( 2, $result['median'] );
		self::assertCount( 2, $result['quantiles']['0.9'] );
		self::assertSame( 'baseline', $result['provider'] );
		self::assertSame( 12, $result['elapsed_ms'] );
	}

	public function test_assemble_slices_longer_median_to_horizon(): void {
		$result = Result::assemble( $this->payload( 3 ), $this->series(), $this->meta() );

		self::assertSame( array( 40.0, 50.0 ), $result['median'] );
		self::assertCount( 2, $result['quantiles']['0.1'] );
	}

	public function test_kpis_compute_totals_delta_and_width(): void {
		$result = Result::assemble( $this->payload(), $this->series(), $this->meta() );

		self::assertSame( 90.0, $result['kpis']['forecast_total'] );
		self::assertSame( 50.0, $result['kpis']['previous_total'] );
		self::assertSame( 80.0, $result['kpis']['delta_pct'] );
		self::assertSame( 20.0, $result['kpis']['interval_width'] );
	}

	public function test_kpis_delta_null_without_prior_data(): void {
		$series        = $this->series();
		$series['values'] = array();

		$result = Result::assemble( $this->payload(), $series, $this->meta() );

		self::assertSame( 0.0, $result['kpis']['previous_total'] );
		self::assertNull( $result['kpis']['delta_pct'] );
	}

	public function test_fallback_meta_is_preserved(): void {
		$meta = $this->meta(
			array(
				'provider'        => 'baseline',
				'fallback'        => true,
				'fallback_reason' => 'The endpoint timed out.',
				'fallback_code'   => 'immens_forecast_endpoint_timeout',
			)
		);

		$result = Result::assemble( $this->payload(), $this->series(), $meta );

		self::assertTrue( $result['fallback'] );
		self::assertSame( 'The endpoint timed out.', $result['fallback_reason'] );
		self::assertSame( 'immens_forecast_endpoint_timeout', $result['fallback_code'] );
		self::assertFalse( $result['cached'] );
	}
}
```

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter "CacheTest|ResultTest"
```
Expected: FAIL — `Class "ImmensForecast\Forecast\Cache" not found`.

- [ ] **Step 2: Implement Cache and Result**

`includes/Forecast/Cache.php`:
```php
<?php
/**
 * Transient cache and per-user locks.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the transients API.
 */
final class Cache {

	const PREFIX      = 'immens_forecast_';
	const DEFAULT_TTL = 43200;
	const MIN_TTL     = 60;
	const LOCK_TTL    = 60;

	/**
	 * Deterministic cache key for a list of parts.
	 *
	 * @param array $parts Key parts.
	 * @return string
	 */
	public static function key( array $parts ): string {
		$normalized = array();
		foreach ( $parts as $part ) {
			if ( is_array( $part ) ) {
				$part = implode( ',', array_map( 'strval', $part ) );
			}
			$normalized[] = (string) $part;
		}
		return self::PREFIX . md5( implode( '|', $normalized ) );
	}

	/**
	 * Plugin version used in cache keys.
	 *
	 * @return string
	 */
	public static function version(): string {
		return defined( 'IMMENS_FORECAST_VERSION' ) ? (string) IMMENS_FORECAST_VERSION : 'dev';
	}

	/**
	 * Cache lifetime in seconds.
	 *
	 * @return int
	 */
	public static function ttl(): int {
		$ttl = (int) apply_filters( 'immens_forecast_cache_ttl', self::DEFAULT_TTL );
		return max( self::MIN_TTL, $ttl );
	}

	/**
	 * Cached value or null.
	 *
	 * @param string $key Cache key.
	 * @return array|null
	 */
	public static function get( string $key ): ?array {
		$value = get_transient( $key );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Store a value with the configured TTL.
	 *
	 * @param string $key   Cache key.
	 * @param array  $value Payload.
	 * @return void
	 */
	public static function set( string $key, array $value ): void {
		set_transient( $key, $value, self::ttl() );
	}

	/**
	 * Drop a cached value.
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	public static function forget( string $key ): void {
		delete_transient( $key );
	}

	/**
	 * Transient key of the per-user lock.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function lock_key( int $user_id ): string {
		return self::PREFIX . 'lock_' . $user_id;
	}

	/**
	 * Acquire the per-user lock.
	 *
	 * @param int $user_id User id.
	 * @param int $ttl     Lock lifetime.
	 * @return bool True when the caller now owns the lock.
	 */
	public static function acquire_lock( int $user_id, int $ttl = self::LOCK_TTL ): bool {
		$key = self::lock_key( $user_id );
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, max( 1, $ttl ) );
		return true;
	}

	/**
	 * Release the per-user lock.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function release_lock( int $user_id ): void {
		delete_transient( self::lock_key( $user_id ) );
	}
}
```

`includes/Forecast/Result.php`:
```php
<?php
/**
 * Result payload assembler.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast;

defined( 'ABSPATH' ) || exit;

/**
 * Enriches provider output with history, dates and KPIs.
 */
final class Result {

	/**
	 * Assemble the UI-ready payload.
	 *
	 * @param array $payload Provider payload ( model, series ).
	 * @param array $series  Series payload from SeriesBuilder.
	 * @param array $meta    Engine metadata.
	 * @return array
	 */
	public static function assemble( array $payload, array $series, array $meta ): array {
		$horizon = isset( $meta['horizon'] ) ? (int) $meta['horizon'] : 0;

		$history_dates  = isset( $series['dates'] ) ? array_values( (array) $series['dates'] ) : array();
		$history_values = isset( $series['values'] ) ? array_values( (array) $series['values'] ) : array();
		$future_dates   = isset( $series['future_dates'] )
			? array_slice( array_values( (array) $series['future_dates'] ), 0, max( 0, $horizon ) )
			: array();

		$first     = ( isset( $payload['series'][0] ) && is_array( $payload['series'][0] ) ) ? $payload['series'][0] : array();
		$median    = isset( $first['median'] ) ? array_slice( array_values( (array) $first['median'] ), 0, max( 0, $horizon ) ) : array();
		$quantiles = array();
		if ( isset( $first['quantiles'] ) && is_array( $first['quantiles'] ) ) {
			foreach ( $first['quantiles'] as $level => $values ) {
				$quantiles[ (string) $level ] = array_slice( array_values( (array) $values ), 0, max( 0, $horizon ) );
			}
		}

		return array(
			'provider'        => isset( $meta['provider'] ) ? (string) $meta['provider'] : '',
			'model'           => isset( $meta['model'] ) ? (string) $meta['model'] : '',
			'horizon'         => $horizon,
			'quantile_levels' => isset( $meta['quantile_levels'] ) ? (array) $meta['quantile_levels'] : array(),
			'elapsed_ms'      => isset( $meta['elapsed_ms'] ) ? (int) $meta['elapsed_ms'] : 0,
			'cached'          => ! empty( $meta['cached'] ),
			'fallback'        => ! empty( $meta['fallback'] ),
			'fallback_reason' => isset( $meta['fallback_reason'] ) ? $meta['fallback_reason'] : null,
			'fallback_code'   => isset( $meta['fallback_code'] ) ? $meta['fallback_code'] : null,
			'history'         => array(
				'dates'  => $history_dates,
				'values' => $history_values,
			),
			'dates'           => $future_dates,
			'median'          => $median,
			'quantiles'       => $quantiles,
			'kpis'            => self::kpis( $median, $quantiles, $history_values, $horizon ),
		);
	}

	/**
	 * Compute the dashboard KPIs.
	 *
	 * @param array $median         Median forecast.
	 * @param array $quantiles      Quantiles keyed by level.
	 * @param array $history_values Historical values.
	 * @param int   $horizon        Horizon.
	 * @return array
	 */
	private static function kpis( array $median, array $quantiles, array $history_values, int $horizon ): array {
		$forecast_total = 0.0;
		foreach ( $median as $value ) {
			if ( is_numeric( $value ) ) {
				$forecast_total += (float) $value;
			}
		}

		$finite = array();
		foreach ( $history_values as $value ) {
			if ( is_numeric( $value ) ) {
				$finite[] = (float) $value;
			}
		}
		$previous       = ( $horizon > 0 ) ? array_slice( $finite, max( 0, count( $finite ) - $horizon ) ) : array();
		$previous_total = 0.0;
		foreach ( $previous as $value ) {
			$previous_total += $value;
		}

		$delta_pct = ( $previous_total > 0.0 )
			? round( ( $forecast_total - $previous_total ) / $previous_total * 100.0, 1 )
			: null;

		$levels = array();
		foreach ( array_keys( $quantiles ) as $level ) {
			if ( is_numeric( $level ) ) {
				$levels[] = (float) $level;
			}
		}

		$interval_width = 0.0;
		if ( ! empty( $levels ) ) {
			$low_key  = Math::format_level( min( $levels ) );
			$high_key = Math::format_level( max( $levels ) );
			$low      = isset( $quantiles[ $low_key ] ) ? (array) $quantiles[ $low_key ] : array();
			$high     = isset( $quantiles[ $high_key ] ) ? (array) $quantiles[ $high_key ] : array();
			$count    = min( count( $low ), count( $high ) );
			$sum      = 0.0;
			$used     = 0;
			for ( $i = 0; $i < $count; $i++ ) {
				if ( is_numeric( $low[ $i ] ) && is_numeric( $high[ $i ] ) ) {
					$sum += (float) $high[ $i ] - (float) $low[ $i ];
					$used++;
				}
			}
			$interval_width = ( $used > 0 ) ? round( $sum / $used, 2 ) : 0.0;
		}

		return array(
			'forecast_total' => round( $forecast_total, 2 ),
			'previous_total' => round( $previous_total, 2 ),
			'delta_pct'      => $delta_pct,
			'interval_width' => $interval_width,
		);
	}
}
```

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter "CacheTest|ResultTest"
```
Expected: `OK (14 tests)`.

- [ ] **Step 3: Write the failing engine tests**

`tests/unit/Forecast/EngineTest.php`:
```php
<?php
/**
 * Engine orchestration tests.
 *
 * @package ImmensForecast\Tests\Unit\Forecast
 */

namespace ImmensForecast\Tests\Unit\Forecast;

use Brain\Monkey\Functions;
use ImmensForecast\Admin\Settings;
use ImmensForecast\Forecast\Engine;
use ImmensForecast\Forecast\Providers\ProviderInterface;
use ImmensForecast\Sources\SourceInterface;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Validation, caching, locking and fallback.
 */
final class EngineTest extends TestCase {

	/**
	 * Build a source double.
	 *
	 * @param array|\WP_Error $result Value returned by get_series().
	 * @return SourceInterface
	 */
	private function make_source( $result ) {
		return new class( $result ) implements SourceInterface {
			private $result;

			public function __construct( $result ) {
				$this->result = $result;
			}

			public function get_id(): string {
				return 'fake';
			}

			public function get_label(): string {
				return 'Fake';
			}

			public function get_description(): string {
				return '';
			}

			public function is_available(): bool {
				return true;
			}

			public function get_metrics(): array {
				return array( 'revenue' => array( 'label' => 'Revenue', 'granularities' => array( 'day' ) ) );
			}

			public function get_series( array $args ) {
				return $this->result;
			}
		};
	}

	/**
	 * Build a provider double counting the calls.
	 *
	 * @param string          $id      Provider id.
	 * @param array|\WP_Error $payload Value returned by forecast().
	 * @param \stdClass       $calls   Call counter.
	 * @return ProviderInterface
	 */
	private function make_provider( string $id, $payload, \stdClass $calls ) {
		return new class( $id, $payload, $calls ) implements ProviderInterface {
			private $id;
			private $payload;
			private $calls;

			public function __construct( $id, $payload, $calls ) {
				$this->id      = $id;
				$this->payload = $payload;
				$this->calls   = $calls;
			}

			public function get_id(): string {
				return $this->id;
			}

			public function get_label(): string {
				return $this->id;
			}

			public function get_description(): string {
				return '';
			}

			public function get_capabilities(): array {
				return array( 'max_horizon' => 180, 'max_series' => 20 );
			}

			public function is_available(): bool {
				return true;
			}

			public function forecast( array $series, int $horizon, array $options ) {
				$this->calls->n++;
				return $this->payload;
			}

			public function health() {
				return array( 'status' => 'ok' );
			}
		};
	}

	/**
	 * Register source and providers through the public filters.
	 *
	 * @param SourceInterface|null $source    Source double.
	 * @param array                $providers Providers keyed by id.
	 * @return void
	 */
	private function register( $source, array $providers ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( $source, $providers ) {
				if ( 'immens_forecast_sources' === $tag ) {
					return ( null === $source ) ? array() : array( $source );
				}
				if ( 'immens_forecast_providers' === $tag ) {
					return $providers;
				}
				return $value;
			}
		);
	}

	/**
	 * Series payload fixture.
	 *
	 * @param int $points History points.
	 * @return array
	 */
	private function series_payload( int $points = 20 ): array {
		$dates  = array();
		$values = array();
		for ( $i = 0; $i < $points; $i++ ) {
			$dates[]  = gmdate( 'Y-m-d', strtotime( '2025-01-01 +' . $i . ' days' ) );
			$values[] = 10.0 + $i;
		}

		return array(
			'id'               => 'revenue',
			'metric'           => 'revenue',
			'granularity'      => 'day',
			'dates'            => $dates,
			'values'           => $values,
			'future_dates'     => array( '2025-01-21', '2025-01-22' ),
			'past_covariates'  => array( 'dow' => array_fill( 0, $points, 3 ) ),
			'known_covariates' => array( 'dow' => array_fill( 0, $points + 2, 3 ) ),
		);
	}

	/**
	 * Provider payload fixture.
	 *
	 * @return array
	 */
	private function provider_payload(): array {
		return array(
			'model'           => 'fake-model',
			'horizon'         => 2,
			'quantile_levels' => array( 0.1, 0.5, 0.9 ),
			'series'          => array(
				array(
					'id'           => 'revenue',
					'context_used' => 20,
					'median'       => array( 40.0, 41.0 ),
					'quantiles'    => array(
						'0.1' => array( 30.0, 31.0 ),
						'0.5' => array( 40.0, 41.0 ),
						'0.9' => array( 50.0, 51.0 ),
					),
				),
			),
		);
	}

	/**
	 * Params fixture.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private function params( array $overrides = array() ): array {
		return array_merge(
			array(
				'source'       => 'fake',
				'metric'       => 'revenue',
				'granularity'  => 'day',
				'history_days' => 365,
				'horizon'      => 2,
				'provider'     => 'fake',
				'model'        => 'fake-model',
				'quantiles'    => array( 0.1, 0.5, 0.9 ),
				'refresh'      => false,
			),
			$overrides
		);
	}

	protected function setUp(): void {
		parent::setUp();
		Settings::reset_cache();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias(
			static function ( $value ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
			}
		);
	}

	public function test_run_rejects_unknown_source(): void {
		$calls = new \stdClass();
		$calls->n = 0;
		$this->register( null, array() );

		$result = Engine::run( $this->params( array( 'source' => 'missing' ) ) );

		self::assertSame( 'immens_forecast_source_unavailable', $result->get_error_code() );
	}

	public function test_run_rejects_unknown_metric(): void {
		$calls = new \stdClass();
		$calls->n = 0;
		$this->register( $this->make_source( $this->series_payload() ), array() );

		$result = Engine::run( $this->params( array( 'metric' => 'orders' ) ) );

		self::assertSame( 'immens_forecast_unsupported_params', $result->get_error_code() );
	}

	public function test_run_returns_meta_and_kpis(): void {
		$calls     = new \stdClass();
		$calls->n  = 0;
		$baseline  = new \stdClass();
		$baseline->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array(
				'fake'     => $this->make_provider( 'fake', $this->provider_payload(), $calls ),
				'baseline' => $this->make_provider( 'baseline', $this->provider_payload(), $baseline ),
			)
		);

		$result = Engine::run( $this->params() );

		self::assertSame( 'fake', $result['provider'] );
		self::assertSame( 'fake-model', $result['model'] );
		self::assertFalse( $result['cached'] );
		self::assertFalse( $result['fallback'] );
		self::assertCount( 2, $result['dates'] );
		self::assertArrayHasKey( 'forecast_total', $result['kpis'] );
		self::assertSame( 1, $calls->n );
	}

	public function test_run_uses_cache_hit(): void {
		$calls    = new \stdClass();
		$calls->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array( 'fake' => $this->make_provider( 'fake', $this->provider_payload(), $calls ) )
		);
		Functions\when( 'get_transient' )->justReturn( array( 'provider' => 'fake', 'dates' => array() ) );

		$result = Engine::run( $this->params() );

		self::assertTrue( $result['cached'] );
		self::assertSame( 0, $calls->n );
	}

	public function test_run_refresh_bypasses_cache(): void {
		$calls    = new \stdClass();
		$calls->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array( 'fake' => $this->make_provider( 'fake', $this->provider_payload(), $calls ) )
		);
		Functions\when( 'get_transient' )->justReturn( array( 'provider' => 'fake' ) );

		$result = Engine::run( $this->params( array( 'refresh' => true ) ) );

		self::assertFalse( $result['cached'] );
		self::assertSame( 1, $calls->n );
	}

	public function test_run_falls_back_on_provider_error(): void {
		$calls    = new \stdClass();
		$calls->n = 0;
		$baseline = new \stdClass();
		$baseline->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array(
				'fake'     => $this->make_provider( 'fake', new \WP_Error( 'immens_forecast_endpoint_timeout', 'The endpoint timed out.' ), $calls ),
				'baseline' => $this->make_provider( 'baseline', $this->provider_payload(), $baseline ),
			)
		);

		$result = Engine::run( $this->params() );

		self::assertSame( 'baseline', $result['provider'] );
		self::assertTrue( $result['fallback'] );
		self::assertSame( 'immens_forecast_endpoint_timeout', $result['fallback_code'] );
		self::assertSame( 'The endpoint timed out.', $result['fallback_reason'] );
		self::assertSame( 1, $calls->n );
		self::assertSame( 1, $baseline->n );
	}

	public function test_run_maps_429_to_rate_limited(): void {
		$calls = new \stdClass();
		$calls->n = 0;
		$baseline = new \stdClass();
		$baseline->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array(
				'fake'     => $this->make_provider( 'fake', new \WP_Error( 'immens_forecast_endpoint_http', 'Too many requests.', array( 'status' => 429 ) ), $calls ),
				'baseline' => $this->make_provider( 'baseline', $this->provider_payload(), $baseline ),
			)
		);

		$result = Engine::run( $this->params() );

		self::assertSame( 'immens_forecast_rate_limited', $result['fallback_code'] );
		self::assertTrue( $result['fallback'] );
	}

	public function test_run_returns_locked_when_lock_is_held(): void {
		$calls = new \stdClass();
		$calls->n = 0;
		$this->register(
			$this->make_source( $this->series_payload() ),
			array( 'fake' => $this->make_provider( 'fake', $this->provider_payload(), $calls ) )
		);
		Functions\when( 'get_transient' )->justReturn( 1 );

		$result = Engine::run( $this->params(), 7 );

		self::assertSame( 'immens_forecast_locked', $result->get_error_code() );
		self::assertSame( 0, $calls->n );
	}
}
```

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter EngineTest
```
Expected: FAIL — `Class "ImmensForecast\Forecast\Engine" not found`.

- [ ] **Step 4: Implement Engine**

`includes/Forecast/Engine.php`:
```php
<?php
/**
 * Forecast orchestration.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Forecast;

use ImmensForecast\Admin\Settings;
use ImmensForecast\Forecast\Providers\ProviderRegistry;
use ImmensForecast\Sources\SourceRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Validates params, resolves the source and provider, caches and falls back.
 */
final class Engine {

	const HORIZON_MIN     = 1;
	const HORIZON_MAX     = 180;
	const HISTORY_MIN     = 7;
	const HISTORY_MAX     = 3650;
	const DEFAULT_HISTORY = 365;

	/**
	 * Run a forecast request.
	 *
	 * @param array $params  Request params.
	 * @param int   $user_id Current user (0 skips the lock).
	 * @return array|\WP_Error
	 */
	public static function run( array $params, int $user_id = 0 ) {
		$params = self::normalize_params( $params );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$source = SourceRegistry::build()->get( $params['source'] );
		if ( null === $source || ! $source->is_available() ) {
			return new \WP_Error( 'immens_forecast_source_unavailable', __( 'The requested data source is not available.', 'immens-forecast' ) );
		}

		$metrics = $source->get_metrics();
		if ( ! isset( $metrics[ $params['metric'] ] ) || ! is_array( $metrics[ $params['metric'] ] ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Unknown metric for this source.', 'immens-forecast' ) );
		}
		$granularities = isset( $metrics[ $params['metric'] ]['granularities'] )
			? (array) $metrics[ $params['metric'] ]['granularities']
			: array( 'day' );
		if ( ! in_array( $params['granularity'], $granularities, true ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Unsupported granularity for this metric.', 'immens-forecast' ) );
		}

		$history_start = gmdate( 'Y-m-d', time() - ( $params['history_days'] * 86400 ) );
		$args          = array(
			'metric'        => $params['metric'],
			'granularity'   => $params['granularity'],
			'history_start' => $history_start,
			'horizon'       => $params['horizon'],
		);
		if ( ! empty( $params['dataset_id'] ) ) {
			$args['dataset_id'] = (int) $params['dataset_id'];
		}

		$series = $source->get_series( $args );
		if ( is_wp_error( $series ) ) {
			return $series;
		}
		if ( ! is_array( $series ) ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'The source returned no usable series.', 'immens-forecast' ) );
		}

		$cache_key = Cache::key(
			array(
				$params['source'],
				$params['metric'],
				$params['granularity'],
				$history_start,
				$params['horizon'],
				$params['provider'],
				$params['model'],
				$params['quantiles'],
				self::last_point_hash( $series ),
				Cache::version(),
			)
		);

		if ( empty( $params['refresh'] ) ) {
			$cached = Cache::get( $cache_key );
			if ( null !== $cached ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$locked = ( $user_id > 0 ) && ! Cache::acquire_lock( $user_id );
		if ( $locked ) {
			return new \WP_Error( 'immens_forecast_locked', __( 'A forecast is already running. Please try again in a few seconds.', 'immens-forecast' ) );
		}

		try {
			$result = self::forecast( $series, $params );
		} finally {
			if ( $user_id > 0 ) {
				Cache::release_lock( $user_id );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Cache::set( $cache_key, $result );
		return $result;
	}

	/**
	 * Sanitize and clamp request params.
	 *
	 * @param array $params Raw params.
	 * @return array|\WP_Error
	 */
	private static function normalize_params( array $params ) {
		$source      = isset( $params['source'] ) ? sanitize_key( (string) $params['source'] ) : '';
		$metric      = isset( $params['metric'] ) ? sanitize_key( (string) $params['metric'] ) : '';
		$granularity = isset( $params['granularity'] ) ? strtolower( (string) $params['granularity'] ) : 'day';
		if ( ! in_array( $granularity, array( 'day', 'week' ), true ) ) {
			$granularity = 'day';
		}

		if ( '' === $source || '' === $metric ) {
			return new \WP_Error( 'immens_forecast_unsupported_params', __( 'A source and a metric are required.', 'immens-forecast' ) );
		}

		$history_days = isset( $params['history_days'] ) ? (int) $params['history_days'] : self::DEFAULT_HISTORY;
		$history_days = max( self::HISTORY_MIN, min( self::HISTORY_MAX, $history_days ) );

		$horizon = isset( $params['horizon'] ) ? (int) $params['horizon'] : (int) Settings::get( 'horizon', 30 );
		$horizon = max( self::HORIZON_MIN, min( self::HORIZON_MAX, $horizon ) );

		$provider = isset( $params['provider'] ) ? sanitize_key( (string) $params['provider'] ) : (string) Settings::get( 'provider', 'baseline' );
		$model    = isset( $params['model'] ) ? (string) $params['model'] : (string) Settings::get( 'endpoint_model', '209m' );
		$quantiles = isset( $params['quantiles'] ) ? (array) $params['quantiles'] : (array) Settings::get( 'quantiles', array( 0.1, 0.5, 0.9 ) );

		return array(
			'source'       => $source,
			'metric'       => $metric,
			'granularity'  => $granularity,
			'history_days' => $history_days,
			'horizon'      => $horizon,
			'provider'     => $provider,
			'model'        => $model,
			'quantiles'    => $quantiles,
			'dataset_id'   => isset( $params['dataset_id'] ) ? absint( $params['dataset_id'] ) : 0,
			'refresh'      => ! empty( $params['refresh'] ),
		);
	}

	/**
	 * Call the provider, falling back to the baseline on any error.
	 *
	 * @param array $series Series payload.
	 * @param array $params Normalized params.
	 * @return array|\WP_Error
	 */
	private static function forecast( array $series, array $params ) {
		$started   = microtime( true );
		$providers = ProviderRegistry::build();

		$provider        = $providers->get( $params['provider'] );
		$fallback_code   = null;
		$fallback_reason = null;

		if ( null === $provider || ! $provider->is_available() ) {
			$fallback_code   = 'immens_forecast_provider_unavailable';
			$fallback_reason = __( 'The selected provider is not available; the local baseline was used.', 'immens-forecast' );
			$provider        = $providers->get( 'baseline' );
		}
		if ( null === $provider ) {
			return new \WP_Error( 'immens_forecast_provider_unavailable', __( 'No forecast provider is available.', 'immens-forecast' ) );
		}

		$capabilities = $provider->get_capabilities();
		$max_horizon  = isset( $capabilities['max_horizon'] ) ? (int) $capabilities['max_horizon'] : self::HORIZON_MAX;
		if ( $max_horizon > 0 && $params['horizon'] > $max_horizon ) {
			return new \WP_Error(
				'immens_forecast_unsupported_params',
				/* translators: %d: maximum number of forecast steps. */
				sprintf( __( 'The selected provider supports at most %d steps.', 'immens-forecast' ), $max_horizon )
			);
		}

		$input = array(
			array(
				'id'                           => isset( $series['id'] ) ? (string) $series['id'] : 'series',
				'values'                       => isset( $series['values'] ) ? (array) $series['values'] : array(),
				'past_covariates'              => isset( $series['past_covariates'] ) ? $series['past_covariates'] : null,
				'known_covariates'             => isset( $series['known_covariates'] ) ? $series['known_covariates'] : null,
				'categorical_past_covariates'  => null,
				'categorical_known_covariates' => null,
			),
		);
		$options = array(
			'model'       => $params['model'],
			'quantiles'   => $params['quantiles'],
			'granularity' => $params['granularity'],
		);

		$payload = $provider->forecast( $input, $params['horizon'], $options );

		if ( is_wp_error( $payload ) ) {
			$error_data      = $payload->get_error_data();
			$error_data      = is_array( $error_data ) ? $error_data : array();
			$fallback_code   = $payload->get_error_code();
			$fallback_reason = $payload->get_error_message();

			if ( 'immens_forecast_endpoint_http' === $fallback_code && isset( $error_data['status'] ) && 429 === (int) $error_data['status'] ) {
				$fallback_code = 'immens_forecast_rate_limited';
			}
			$fallback_reason = self::fallback_message( $fallback_code, $fallback_reason, $error_data );

			if ( 'baseline' !== $provider->get_id() ) {
				$baseline = $providers->get( 'baseline' );
				if ( null !== $baseline ) {
					$payload = $baseline->forecast( $input, $params['horizon'], $options );
				}
			}
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}
		}

		return Result::assemble(
			$payload,
			$series,
			array(
				'provider'        => $provider->get_id(),
				'model'           => isset( $payload['model'] ) ? (string) $payload['model'] : $params['model'],
				'horizon'         => $params['horizon'],
				'quantile_levels' => isset( $payload['quantile_levels'] ) ? (array) $payload['quantile_levels'] : $params['quantiles'],
				'elapsed_ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'cached'          => false,
				'fallback'        => null !== $fallback_code,
				'fallback_reason' => $fallback_reason,
				'fallback_code'   => $fallback_code,
			)
		);
	}

	/**
	 * Human message for a fallback reason.
	 *
	 * @param string $code    Error code.
	 * @param string $message Original message.
	 * @param array  $data    Error data.
	 * @return string
	 */
	private static function fallback_message( string $code, string $message, array $data ): string {
		if ( 'immens_forecast_rate_limited' === $code && isset( $data['retry_after'] ) ) {
			return sprintf(
				/* translators: %d: seconds to wait before retrying. */
				__( 'Rate limit reached. Retry in %d seconds; the local baseline was used.', 'immens-forecast' ),
				(int) $data['retry_after']
			);
		}
		return $message;
	}

	/**
	 * Hash of the last data point (invalidates the cache on new data).
	 *
	 * @param array $series Series payload.
	 * @return string
	 */
	private static function last_point_hash( array $series ): string {
		$dates  = isset( $series['dates'] ) ? (array) $series['dates'] : array();
		$values = isset( $series['values'] ) ? (array) $series['values'] : array();
		$count  = count( $dates );
		$last   = 'empty';

		if ( $count > 0 ) {
			$value = isset( $values[ $count - 1 ] ) && is_numeric( $values[ $count - 1 ] )
				? (string) (float) $values[ $count - 1 ]
				: 'null';
			$last  = (string) $dates[ $count - 1 ] . ':' . $value;
		}

		return md5( $last );
	}
}
```

- [ ] **Step 5: Wire the engine, run the task tests, PHPCS and commit**

In `includes/Plugin.php`, replace the `engine()` placeholder body:

```php
	/**
	 * Forecast engine.
	 *
	 * @return Engine
	 */
	public function engine(): Engine {
		return new Engine();
	}
```

Add `use ImmensForecast\Forecast\Engine;` to the imports and update the Task 1 test that asserted the placeholder to `self::assertInstanceOf( Engine::class, Plugin::instance()->engine() )`.

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter "CacheTest|ResultTest|EngineTest|PluginTest"
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(forecast): add cache, result assembler and engine"
```

Expected: `OK (25 tests)` (22 forecast tests + 3 Plugin tests) then `0 errors`.

### Task 13: Admin AJAX endpoints

**Files:**
- Extend: `includes/Forecast/Providers/EndpointProvider.php` (add `models()`)
- Extend: `tests/unit/Forecast/Providers/EndpointProviderTest.php` (+2 tests)
- Create: `includes/Admin/Ajax.php`
- Create: `tests/unit/Admin/AjaxTest.php`

**Interfaces:**
- Consumes: `Engine::run()` + `Engine::HISTORY_MIN|HISTORY_MAX|HORIZON_MIN|HORIZON_MAX|DEFAULT_HISTORY` (Task 12), `Settings::capability()`/`update()`/`TIMEOUT_MIN|TIMEOUT_MAX` (Task 4), `Crypto::encrypt()` (Task 2), `EndpointProvider::health()` (Task 11).
- Produces:
  - `EndpointProvider::models(): array|\WP_Error` → `['models' => [['alias' => '28m', 'hub_id' => ''], …]]` from `GET {base}/v1/models` (§4.4).
  - `ImmensForecast\Admin\Ajax`: `NONCE = 'immens_forecast'`, `register()` (hooks `wp_ajax_immens_forecast_run|_test_endpoint|_load_models`), `collect_params( array $input ): array`, handlers `run()`, `test_endpoint()`, `load_models()`.
  - Error envelope `['code', 'message', 'data']` with HTTP status 403 (capability), 400 (default), 409 (`immens_forecast_locked`), 429 (`immens_forecast_rate_limited`); success envelope = the `Result::assemble()` payload via `wp_send_json_success`.
- Decisions: every handler runs `check_ajax_referer( 'immens_forecast', 'nonce' )` **before** the capability check; `test_endpoint` and `load_models` persist the sanitized endpoint patch first (token encrypted with `Crypto` only when non-empty, empty = keep current) and then call the provider, so "Test connessione"/"Carica modelli" operate on saved credentials; only `http`/`https` URLs are accepted.

- [ ] **Step 1: Extend the endpoint provider with `models()`**

Append to `includes/Forecast/Providers/EndpointProvider.php` (before the closing brace):
```php
	/**
	 * List the models exposed by the endpoint (§4.4).
	 *
	 * @return array|\WP_Error
	 */
	public function models() {
		$base = $this->base_url();
		if ( '' === $base ) {
			return new \WP_Error( 'immens_forecast_provider_unavailable', __( 'No endpoint URL is configured.', 'immens-forecast' ) );
		}

		$response = wp_remote_get(
			$base . '/v1/models',
			array(
				'timeout'   => $this->timeout(),
				'sslverify' => $this->sslverify(),
				'headers'   => $this->headers(),
			)
		);

		$error = $this->transport_error( $response );
		if ( null !== $error ) {
			return $error;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return $this->http_error( $status );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'immens_forecast_endpoint_bad_response', __( 'The endpoint did not return a valid model list.', 'immens-forecast' ) );
		}

		$items  = ( isset( $data['models'] ) && is_array( $data['models'] ) ) ? $data['models'] : $data;
		$models = array();
		foreach ( $items as $item ) {
			if ( is_string( $item ) ) {
				$models[] = array(
					'alias'  => $item,
					'hub_id' => '',
				);
				continue;
			}
			if ( is_array( $item ) && isset( $item['alias'] ) ) {
				$models[] = array(
					'alias'  => (string) $item['alias'],
					'hub_id' => isset( $item['hub_id'] ) ? (string) $item['hub_id'] : '',
				);
			}
		}

		return array( 'models' => $models );
	}
```

Append to `tests/unit/Forecast/Providers/EndpointProviderTest.php`:
```php
	public function test_models_returns_normalized_list(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode(
					array(
						'models' => array(
							array(
								'alias'  => '28m',
								'hub_id' => 'a/28m',
							),
							array( 'alias' => '209m' ),
						),
					)
				),
			)
		);

		$result = ( new EndpointProvider() )->models();

		self::assertCount( 2, $result['models'] );
		self::assertSame( '28m', $result['models'][0]['alias'] );
		self::assertSame( '', $result['models'][1]['hub_id'] );
	}

	public function test_models_http_error(): void {
		$this->configure( array( 'endpoint_url' => 'https://forecast.example.com' ) );
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => '',
			)
		);

		$result = ( new EndpointProvider() )->models();

		self::assertSame( 'immens_forecast_endpoint_http', $result->get_error_code() );
	}
```

- [ ] **Step 2: Write the failing AJAX tests**

`tests/unit/Admin/AjaxTest.php`:
```php
<?php
/**
 * AJAX endpoint tests.
 *
 * @package ImmensForecast\Tests\Unit\Admin
 */

namespace ImmensForecast\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use ImmensForecast\Admin\Ajax;
use ImmensForecast\Admin\Settings;
use ImmensForecast\Forecast\Providers\ProviderInterface;
use ImmensForecast\Sources\SourceInterface;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Sentinel thrown by the wp_send_json_* stubs.
 */
final class AjaxResponse extends \Exception {

	/** @var string */
	public $type;

	/** @var mixed */
	public $payload;

	/** @var int */
	public $status;

	/**
	 * Constructor.
	 *
	 * @param string $type    success|error.
	 * @param mixed  $payload Payload.
	 * @param int    $status  HTTP status.
	 */
	public function __construct( string $type, $payload, int $status ) {
		parent::__construct( $type );
		$this->type    = $type;
		$this->payload = $payload;
		$this->status  = $status;
	}
}

/**
 * Guards, sanitization and response envelopes.
 */
final class AjaxTest extends TestCase {

	/**
	 * Capture wp_send_json_* calls as exceptions.
	 *
	 * @return void
	 */
	private function capture(): void {
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null, $status = 200 ) {
				throw new AjaxResponse( 'success', $data, (int) $status );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = 200 ) {
				throw new AjaxResponse( 'error', $data, (int) $status );
			}
		);
	}

	/**
	 * Invoke a handler and return the captured response.
	 *
	 * @param callable $handler Handler.
	 * @return AjaxResponse
	 */
	private function invoke( callable $handler ): AjaxResponse {
		try {
			$handler();
		} catch ( AjaxResponse $response ) {
			return $response;
		}
		self::fail( 'No JSON response was sent.' );
	}

	/**
	 * Source double.
	 *
	 * @return SourceInterface
	 */
	private function source_double() {
		return new class() implements SourceInterface {
			public function get_id(): string {
				return 'fake';
			}

			public function get_label(): string {
				return 'Fake';
			}

			public function get_description(): string {
				return '';
			}

			public function is_available(): bool {
				return true;
			}

			public function get_metrics(): array {
				return array( 'revenue' => array( 'label' => 'Revenue', 'granularities' => array( 'day' ) ) );
			}

			public function get_series( array $args ) {
				return array(
					'id'               => 'revenue',
					'dates'            => array( '2025-01-01', '2025-01-02' ),
					'values'           => array( 10.0, 20.0 ),
					'future_dates'     => array( '2025-01-03' ),
					'past_covariates'  => array( 'dow' => array( 3, 4 ) ),
					'known_covariates' => array( 'dow' => array( 3, 4, 5 ) ),
				);
			}
		};
	}

	/**
	 * Provider double.
	 *
	 * @return ProviderInterface
	 */
	private function provider_double() {
		return new class() implements ProviderInterface {
			public function get_id(): string {
				return 'fake';
			}

			public function get_label(): string {
				return 'Fake';
			}

			public function get_description(): string {
				return '';
			}

			public function get_capabilities(): array {
				return array( 'max_horizon' => 180 );
			}

			public function is_available(): bool {
				return true;
			}

			public function forecast( array $series, int $horizon, array $options ) {
				return array(
					'model'           => 'fake-model',
					'horizon'         => 1,
					'quantile_levels' => array( 0.1, 0.5, 0.9 ),
					'series'          => array(
						array(
							'id'           => 'revenue',
							'context_used' => 2,
							'median'       => array( 30.0 ),
							'quantiles'    => array(
								'0.1' => array( 25.0 ),
								'0.5' => array( 30.0 ),
								'0.9' => array( 35.0 ),
							),
						),
					),
				);
			}

			public function health() {
				return array( 'status' => 'ok' );
			}
		};
	}

	/**
	 * Register the source/provider doubles through the filters.
	 *
	 * @return void
	 */
	private function register_doubles(): void {
		$source    = $this->source_double();
		$provider  = $this->provider_double();
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( $source, $provider ) {
				if ( 'immens_forecast_sources' === $tag ) {
					return array( $source );
				}
				if ( 'immens_forecast_providers' === $tag ) {
					return array( $provider, $provider );
				}
				return $value;
			}
		);
	}

	protected function setUp(): void {
		parent::setUp();
		Settings::reset_cache();
		$_POST = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $value ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return (int) abs( (int) $value );
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		Settings::reset_cache();
		parent::tearDown();
	}

	public function test_register_hooks_three_actions(): void {
		Functions\expect( 'add_action' )->times( 3 )->andReturn( true );

		Ajax::register();
	}

	public function test_collect_params_whitelists_and_clamps(): void {
		$params = Ajax::collect_params(
			array(
				'source'       => 'Woo!',
				'metric'       => 'Revenue',
				'granularity'  => 'year',
				'history_days' => '99999',
				'horizon'      => '0',
				'quantiles'    => array( '0.1', 'junk', '1.5' ),
				'dataset_id'   => '7',
				'refresh'      => '1',
				'evil'         => 'x',
			)
		);

		self::assertSame( 'woo', $params['source'] );
		self::assertSame( 'revenue', $params['metric'] );
		self::assertSame( 'day', $params['granularity'] );
		self::assertSame( 3650, $params['history_days'] );
		self::assertSame( 1, $params['horizon'] );
		self::assertSame( array( 0.1 ), $params['quantiles'] );
		self::assertSame( 7, $params['dataset_id'] );
		self::assertTrue( $params['refresh'] );
		self::assertArrayNotHasKey( 'evil', $params );
	}

	public function test_run_denies_without_capability(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->capture();

		$response = $this->invoke( array( Ajax::class, 'run' ) );

		self::assertSame( 'error', $response->type );
		self::assertSame( 403, $response->status );
		self::assertSame( 'immens_forecast_forbidden', $response->payload['code'] );
	}

	public function test_run_sends_engine_error(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->capture();
		$_POST = array(
			'source' => 'missing',
			'metric' => 'revenue',
		);

		$response = $this->invoke( array( Ajax::class, 'run' ) );

		self::assertSame( 'error', $response->type );
		self::assertSame( 400, $response->status );
		self::assertSame( 'immens_forecast_source_unavailable', $response->payload['code'] );
	}

	public function test_run_sends_success_payload(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->register_doubles();
		$this->capture();
		$_POST = array(
			'source'   => 'fake',
			'metric'   => 'revenue',
			'provider' => 'fake',
			'horizon'  => '1',
		);

		$response = $this->invoke( array( Ajax::class, 'run' ) );

		self::assertSame( 'success', $response->type );
		self::assertSame( 'fake', $response->payload['provider'] );
		self::assertArrayHasKey( 'kpis', $response->payload );
	}

	public function test_test_endpoint_persists_settings_and_returns_health(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$updated = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$updated ) {
				$updated = array( $key, $value );
				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"status":"ok"}',
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return isset( $response['body'] ) ? (string) $response['body'] : '';
			}
		);
		$this->capture();
		$_POST = array(
			'endpoint_url'     => 'https://forecast.example.com',
			'endpoint_model'   => '28m',
			'endpoint_timeout' => '45',
			'endpoint_token'   => '',
		);

		$response = $this->invoke( array( Ajax::class, 'test_endpoint' ) );

		self::assertSame( 'success', $response->type );
		self::assertSame( 'ok', $response->payload['status'] );
		self::assertSame( 'immens_forecast_settings', $updated[0] );
		self::assertSame( 'https://forecast.example.com', $updated[1]['endpoint_url'] );
		self::assertSame( '28m', $updated[1]['endpoint_model'] );
		self::assertSame( 45, $updated[1]['endpoint_timeout'] );
	}

	public function test_load_models_returns_aliases(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"models":[{"alias":"28m","hub_id":"a/28m"},{"alias":"209m"}]}',
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return isset( $response['body'] ) ? (string) $response['body'] : '';
			}
		);
		$this->capture();
		$_POST = array( 'endpoint_url' => 'https://forecast.example.com' );

		$response = $this->invoke( array( Ajax::class, 'load_models' ) );

		self::assertSame( 'success', $response->type );
		self::assertCount( 2, $response->payload['models'] );
		self::assertSame( '209m', $response->payload['models'][1]['alias'] );
	}

	public function test_load_models_maps_transport_error(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
		$this->capture();
		$_POST = array( 'endpoint_url' => 'https://forecast.example.com' );

		$response = $this->invoke( array( Ajax::class, 'load_models' ) );

		self::assertSame( 'error', $response->type );
		self::assertSame( 'immens_forecast_endpoint_timeout', $response->payload['code'] );
		self::assertSame( 400, $response->status );
	}
}
```

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter "EndpointProviderTest|AjaxTest"
```
Expected: FAIL — `Class "ImmensForecast\Admin\Ajax" not found`.

- [ ] **Step 3: Implement Ajax**

Before running anything, amend `test_run_denies_without_capability` so the capability filter is stubbed:

```diff
 	public function test_run_denies_without_capability(): void {
 		Functions\expect( 'check_ajax_referer' )->once()->with( 'immens_forecast', 'nonce' );
+		Functions\when( 'apply_filters' )->returnArg( 2 );
 		Functions\when( 'current_user_can' )->justReturn( false );
 		$this->capture();
```

`includes/Admin/Ajax.php`:
```php
<?php
/**
 * Admin AJAX endpoints.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Admin;

use ImmensForecast\Forecast\Crypto;
use ImmensForecast\Forecast\Engine;
use ImmensForecast\Forecast\Providers\EndpointProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Nonce and capability guarded handlers for the forecast UI.
 */
final class Ajax {

	const NONCE         = 'immens_forecast';
	const MAX_QUANTILES = 9;

	/**
	 * Register the AJAX actions.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_immens_forecast_run', array( __CLASS__, 'run' ) );
		add_action( 'wp_ajax_immens_forecast_test_endpoint', array( __CLASS__, 'test_endpoint' ) );
		add_action( 'wp_ajax_immens_forecast_load_models', array( __CLASS__, 'load_models' ) );
	}

	/**
	 * Run a forecast request.
	 *
	 * @return void
	 */
	public static function run(): void {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$params = self::collect_params( wp_unslash( $_POST ) );
		self::respond( Engine::run( $params, get_current_user_id() ) );
	}

	/**
	 * Persist the endpoint settings and check their health.
	 *
	 * @return void
	 */
	public static function test_endpoint(): void {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$patch = self::collect_endpoint_settings( wp_unslash( $_POST ) );
		if ( is_wp_error( $patch ) ) {
			self::respond( $patch );
			return;
		}
		Settings::update( $patch );
		self::respond( ( new EndpointProvider() )->health() );
	}

	/**
	 * Persist the endpoint settings and list the remote models.
	 *
	 * @return void
	 */
	public static function load_models(): void {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$patch = self::collect_endpoint_settings( wp_unslash( $_POST ) );
		if ( is_wp_error( $patch ) ) {
			self::respond( $patch );
			return;
		}
		Settings::update( $patch );
		self::respond( ( new EndpointProvider() )->models() );
	}

	/**
	 * Collect and sanitize the forecast params.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function collect_params( array $input ): array {
		$quantiles = array();
		if ( isset( $input['quantiles'] ) && is_array( $input['quantiles'] ) ) {
			foreach ( array_slice( $input['quantiles'], 0, self::MAX_QUANTILES ) as $level ) {
				if ( ! is_numeric( $level ) ) {
					continue;
				}
				$level = (float) $level;
				if ( $level > 0.0 && $level < 1.0 ) {
					$quantiles[] = $level;
				}
			}
		}
		if ( empty( $quantiles ) ) {
			$quantiles = (array) Settings::get( 'quantiles', array( 0.1, 0.5, 0.9 ) );
		}

		$granularity = isset( $input['granularity'] ) ? strtolower( sanitize_text_field( (string) $input['granularity'] ) ) : 'day';
		if ( ! in_array( $granularity, array( 'day', 'week' ), true ) ) {
			$granularity = 'day';
		}

		$history_days = isset( $input['history_days'] ) ? (int) $input['history_days'] : Engine::DEFAULT_HISTORY;
		$horizon      = isset( $input['horizon'] ) ? (int) $input['horizon'] : (int) Settings::get( 'horizon', 30 );

		$params = array(
			'source'       => isset( $input['source'] ) ? sanitize_key( (string) $input['source'] ) : '',
			'metric'       => isset( $input['metric'] ) ? sanitize_key( (string) $input['metric'] ) : '',
			'granularity'  => $granularity,
			'history_days' => max( Engine::HISTORY_MIN, min( Engine::HISTORY_MAX, $history_days ) ),
			'horizon'      => max( Engine::HORIZON_MIN, min( Engine::HORIZON_MAX, $horizon ) ),
			'refresh'      => ! empty( $input['refresh'] ),
			'quantiles'    => $quantiles,
		);

		if ( ! empty( $input['provider'] ) ) {
			$params['provider'] = sanitize_key( (string) $input['provider'] );
		}
		if ( ! empty( $input['model'] ) ) {
			$params['model'] = sanitize_text_field( (string) $input['model'] );
		}
		if ( ! empty( $input['dataset_id'] ) ) {
			$params['dataset_id'] = absint( $input['dataset_id'] );
		}

		return $params;
	}

	/**
	 * Nonce and capability guard.
	 *
	 * @return void
	 */
	private static function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_send_json_error(
				array(
					'code'    => 'immens_forecast_forbidden',
					'message' => __( 'You are not allowed to run forecasts.', 'immens-forecast' ),
				),
				403
			);
		}
	}

	/**
	 * Send the JSON envelope for an engine/provider result.
	 *
	 * @param array|\WP_Error $result Result.
	 * @return void
	 */
	private static function respond( $result ): void {
		if ( ! is_wp_error( $result ) ) {
			wp_send_json_success( $result );
			return;
		}

		$code   = $result->get_error_code();
		$status = 400;
		if ( 'immens_forecast_locked' === $code ) {
			$status = 409;
		} elseif ( 'immens_forecast_rate_limited' === $code ) {
			$status = 429;
		}

		wp_send_json_error(
			array(
				'code'    => $code,
				'message' => $result->get_error_message(),
				'data'    => $result->get_error_data(),
			),
			$status
		);
	}

	/**
	 * Sanitize the endpoint settings patch.
	 *
	 * @param array $input Raw input.
	 * @return array|\WP_Error
	 */
	private static function collect_endpoint_settings( array $input ) {
		$patch = array();

		if ( isset( $input['endpoint_url'] ) ) {
			$url = esc_url_raw( (string) $input['endpoint_url'] );
			if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
				return new \WP_Error( 'immens_forecast_unsupported_params', __( 'Only http and https endpoint URLs are allowed.', 'immens-forecast' ) );
			}
			$patch['endpoint_url'] = $url;
		}
		if ( isset( $input['endpoint_model'] ) ) {
			$patch['endpoint_model'] = sanitize_text_field( (string) $input['endpoint_model'] );
		}
		if ( isset( $input['endpoint_timeout'] ) ) {
			$timeout                   = (int) $input['endpoint_timeout'];
			$patch['endpoint_timeout'] = max( Settings::TIMEOUT_MIN, min( Settings::TIMEOUT_MAX, $timeout ) );
		}
		if ( ! empty( $input['endpoint_token'] ) ) {
			$crypto                      = new Crypto();
			$patch['endpoint_token_enc'] = $crypto->encrypt( sanitize_text_field( (string) $input['endpoint_token'] ) );
		}

		return $patch;
	}
}
```

- [ ] **Step 4: Run the task tests, PHPCS and commit**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter "EndpointProviderTest|AjaxTest"
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(admin): add AJAX endpoints and endpoint model listing"
```

Expected: `OK (22 tests)` (14 EndpointProviderTest + 8 AjaxTest) then `0 errors`.

- [ ] **Step 5: Register the AJAX hooks in the plugin bootstrap**

In `includes/Plugin.php` `boot()`, after the admin hooks, add:

```php
if ( wp_doing_ajax() ) {
	Admin\Ajax::register();
}
```

### Task 14: Admin page, settings screen and assets

**Files:**
- Create: `includes/Admin/Page.php`
- Create: `templates/forecast-page.php`
- Create: `templates/settings-page.php`
- Create: `assets/css/admin.css`
- Create: `assets/js/admin.js`
- Vendor: `assets/vendor/chart.umd.min.js` (Chart.js 3.9.1, MIT)
- Create: `tests/unit/Admin/PageTest.php`

**Interfaces:**
- Consumes: `Settings::capability()/sanitize()/update()/endpoint_token()` (Task 4), `SourceRegistry::build()` (Task 6), `ProviderRegistry::build()` (Task 9), `Storage\Datasets::all()` (Task 5), `IMMENS_FORECAST_PATH|URL|VERSION` (Task 1), `Ajax::NONCE` (Task 13).
- Produces:
  - `ImmensForecast\Admin\Page`: `register()` (menu top-level `immens-forecast` + submenus `immens-forecast`, `immens-forecast-settings`; extra submenus via `immens_forecast_admin_menu` filter with items `['title' => string, 'slug' => string, 'callback' => callable]`), `enqueue( string $hook )`, `render_forecast()`, `render_settings()`, `handle_save()` (admin_post `immens_forecast_save_settings`), `template_path( string $template ): string` (filter `immens_forecast_template_path`, falls back to the bundled template when the filtered path does not exist).
  - Templates receive `$args` from `render_*`: forecast page gets `sources` (id ⇒ `['label','description','metrics']`), `providers`, `defaults` (provider, horizon, quantiles, history_days), `endpoint_configured`; settings page gets `settings` (with the token replaced by a non-empty placeholder when a token exists), `providers`, `datasets`.
- Decisions: chart + dashboard live in `admin.js` with `window.immensForecast = {ajaxUrl, nonce, i18n}`; Chart.js is bundled (no CDN, w.org-safe); the settings form posts to `admin-post.php` (`immens_forecast_save_settings`); ESC/localization via `wp_set_script_translations`.

- [ ] **Step 1: Write the failing admin page tests**

`tests/unit/Admin/PageTest.php`:
```php
<?php
/**
 * Admin page tests.
 *
 * @package ImmensForecast\Tests\Unit\Admin
 */

namespace ImmensForecast\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use ImmensForecast\Admin\Page;
use ImmensForecast\Admin\Settings;
use ImmensForecast\Tests\Unit\TestCase;

/**
 * Sentinel for redirect/die exits.
 */
final class AdminExit extends \Exception {

	/** @var string */
	public $destination;

	/**
	 * Constructor.
	 *
	 * @param string $destination Target URL or message.
	 */
	public function __construct( string $destination ) {
		parent::__construct( $destination );
		$this->destination = $destination;
	}
}

/**
 * Menu, assets, templates and settings saving.
 */
final class PageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Settings::reset_cache();
		$_POST = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return (int) abs( (int) $value );
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		Settings::reset_cache();
		parent::tearDown();
	}

	public function test_register_adds_menu_and_submenus(): void {
		Functions\expect( 'add_menu_page' )->once()->andReturn( 'toplevel_page_immens-forecast' );
		Functions\expect( 'add_submenu_page' )->twice()->andReturn( 'immens-forecast_page_x' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		Page::register();
	}

	public function test_register_appends_filtered_submenus(): void {
		Functions\expect( 'add_menu_page' )->once()->andReturn( 'toplevel_page_immens-forecast' );
		Functions\expect( 'add_submenu_page' )->times( 3 )->andReturn( 'immens-forecast_page_x' );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'immens_forecast_admin_menu' === $tag ) {
					return array(
						array(
							'title'    => 'Forecast Pro',
							'slug'     => 'immens-forecast-pro',
							'callback' => '__return_null',
						),
					);
				}
				return $value;
			}
		);

		Page::register();
	}

	public function test_enqueue_skips_unrelated_hooks(): void {
		Page::enqueue( 'edit.php' );
	}

	public function test_enqueue_registers_assets_and_localizes(): void {
		$localized = array();
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'immens-forecast-admin', IMMENS_FORECAST_URL . 'assets/css/admin.css', array(), IMMENS_FORECAST_VERSION )->andReturn( true );
		Functions\expect( 'wp_enqueue_script' )->twice();
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $data ) use ( &$localized ) {
				$localized = array(
					'handle' => $handle,
					'name'   => $name,
					'data'   => $data,
				);
				return true;
			}
		);
		Functions\expect( 'wp_set_script_translations' )->once()->with( 'immens-forecast-admin', 'immens-forecast' )->andReturn( true );

		Page::enqueue( 'toplevel_page_immens-forecast' );

		self::assertSame( 'immens-forecast-admin', $localized['handle'] );
		self::assertSame( 'immensForecast', $localized['name'] );
		self::assertSame( 'nonce123', $localized['data']['nonce'] );
		self::assertSame( 'https://example.test/wp-admin/admin-ajax.php', $localized['data']['ajaxUrl'] );
		self::assertArrayHasKey( 'i18n', $localized['data'] );
	}

	public function test_template_path_is_filterable_with_fallback(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		self::assertSame( IMMENS_FORECAST_PATH . 'templates/forecast-page.php', Page::template_path( 'forecast-page.php' ) );

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'immens_forecast_template_path' === $tag ) {
					return '/does/not/exist/forecast-page.php';
				}
				return $value;
			}
		);

		self::assertSame( IMMENS_FORECAST_PATH . 'templates/forecast-page.php', Page::template_path( 'forecast-page.php' ) );
	}

	public function test_handle_save_persists_sanitized_settings(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\expect( 'check_admin_referer' )->once()->with( 'immens_forecast_save_settings' );
		$updated = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$updated ) {
				$updated = array( $key, $value );
				return true;
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) {
				throw new AdminExit( (string) $url );
			}
		);
		$_POST = array(
			'provider'     => 'endpoint',
			'endpoint_url' => 'https://forecast.example.com',
		);

		try {
			Page::handle_save();
		} catch ( AdminExit $exit ) {
			self::assertStringContainsString( 'page=immens-forecast-settings', $exit->destination );
			self::assertSame( 'immens_forecast_settings', $updated[0] );
			self::assertSame( 'endpoint', $updated[1]['provider'] );
			self::assertSame( 'https://forecast.example.com', $updated[1]['endpoint_url'] );
			return;
		}
		self::fail( 'Expected a redirect.' );
	}

	public function test_handle_save_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\expect( 'check_admin_referer' )->never();
		Functions\when( 'wp_die' )->alias(
			static function ( $message = '', $title = '', $args = array() ) {
				throw new AdminExit( (string) $message );
			}
		);

		try {
			Page::handle_save();
		} catch ( AdminExit $exit ) {
			self::assertNotSame( '', $exit->destination );
			return;
		}
		self::fail( 'Expected wp_die().' );
	}
}
```

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter PageTest
```
Expected: FAIL — `Class "ImmensForecast\Admin\Page" not found`.

- [ ] **Step 2: Implement the admin page**

`includes/Admin/Page.php`:
```php
<?php
/**
 * Admin pages and assets.
 *
 * @package ImmensForecast
 */

namespace ImmensForecast\Admin;

use ImmensForecast\Forecast\Engine;
use ImmensForecast\Forecast\Providers\ProviderInterface;
use ImmensForecast\Sources\SourceInterface;
use ImmensForecast\Storage\Datasets;

defined( 'ABSPATH' ) || exit;

/**
 * Forecast and settings screens.
 */
final class Page {

	/**
	 * Register the admin menu and its submenus.
	 *
	 * @return void
	 */
	public static function register(): void {
		$capability = Settings::capability();

		add_menu_page(
			__( 'Forecast', 'immens-forecast' ),
			__( 'Forecast', 'immens-forecast' ),
			$capability,
			'immens-forecast',
			array( __CLASS__, 'render_forecast' ),
			'dashicons-chart-line',
			30
		);

		add_submenu_page(
			'immens-forecast',
			__( 'Forecast', 'immens-forecast' ),
			__( 'Forecast', 'immens-forecast' ),
			$capability,
			'immens-forecast',
			array( __CLASS__, 'render_forecast' )
		);

		add_submenu_page(
			'immens-forecast',
			__( 'Settings', 'immens-forecast' ),
			__( 'Settings', 'immens-forecast' ),
			$capability,
			'immens-forecast-settings',
			array( __CLASS__, 'render_settings' )
		);

		$extra = apply_filters( 'immens_forecast_admin_menu', array() );
		if ( ! is_array( $extra ) ) {
			return;
		}

		foreach ( $extra as $item ) {
			if ( ! is_array( $item ) || empty( $item['slug'] ) || empty( $item['title'] ) ) {
				continue;
			}
			$callback = isset( $item['callback'] ) && is_callable( $item['callback'] ) ? $item['callback'] : '__return_null';
			add_submenu_page(
				'immens-forecast',
				(string) $item['title'],
				(string) $item['title'],
				$capability,
				(string) $item['slug'],
				$callback
			);
		}
	}

	/**
	 * Enqueue the admin assets on the plugin screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'immens-forecast' ) ) {
			return;
		}

		wp_enqueue_style(
			'immens-forecast-admin',
			IMMENS_FORECAST_URL . 'assets/css/admin.css',
			array(),
			IMMENS_FORECAST_VERSION
		);

		wp_enqueue_script(
			'immens-forecast-chart',
			IMMENS_FORECAST_URL . 'assets/vendor/chart.umd.min.js',
			array(),
			'3.9.1',
			true
		);

		wp_enqueue_script(
			'immens-forecast-admin',
			IMMENS_FORECAST_URL . 'assets/js/admin.js',
			array( 'immens-forecast-chart' ),
			IMMENS_FORECAST_VERSION,
			true
		);

		wp_localize_script(
			'immens-forecast-admin',
			'immensForecast',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( Ajax::NONCE ),
				'pageUrl'     => admin_url( 'admin.php?page=immens-forecast' ),
				'settingsUrl' => admin_url( 'admin.php?page=immens-forecast-settings' ),
				'i18n'        => array(
					'error'        => __( 'The forecast could not be generated.', 'immens-forecast' ),
					'fallback'     => __( 'Local baseline (limited data)', 'immens-forecast' ),
					'testing'      => __( 'Testing...', 'immens-forecast' ),
					'loading'      => __( 'Loading...', 'immens-forecast' ),
					'testOk'       => __( 'Connection OK.', 'immens-forecast' ),
					'testFailed'   => __( 'Connection failed.', 'immens-forecast' ),
					'modelsLoaded' => __( 'Models loaded.', 'immens-forecast' ),
					'noModels'     => __( 'No models returned by the endpoint.', 'immens-forecast' ),
				),
			)
		);

		wp_set_script_translations( 'immens-forecast-admin', 'immens-forecast' );
	}

	/**
	 * Render the forecast screen.
	 *
	 * @return void
	 */
	public static function render_forecast(): void {
		self::guard_render();

		self::render(
			'forecast-page.php',
			array(
				'sources'             => self::describe_sources(),
				'providers'           => self::describe_providers(),
				'datasets'            => self::describe_datasets(),
				'endpoint_configured' => Settings::is_endpoint_configured(),
				'imported'            => (int) filter_input( INPUT_GET, 'imported', FILTER_SANITIZE_NUMBER_INT ),
				'defaults'            => array(
					'provider'     => (string) Settings::get( 'provider', 'baseline' ),
					'horizon'      => (int) Settings::get( 'horizon', 30 ),
					'history_days' => (int) Settings::get( 'history_days', Engine::DEFAULT_HISTORY ),
					'quantiles'    => (array) Settings::get( 'quantiles', array( 0.1, 0.5, 0.9 ) ),
				),
			)
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public static function render_settings(): void {
		self::guard_render();

		$settings = Settings::all();

		self::render(
			'settings-page.php',
			array(
				'settings'      => $settings,
				'providers'     => self::describe_providers(),
				'datasets'      => self::describe_datasets(),
				'token_set'     => '' !== (string) Settings::endpoint_token(),
				'updated'       => (bool) filter_input( INPUT_GET, 'updated', FILTER_SANITIZE_NUMBER_INT ),
				'imported'      => (int) filter_input( INPUT_GET, 'imported', FILTER_SANITIZE_NUMBER_INT ),
				'import_failed' => (bool) filter_input( INPUT_GET, 'import_failed', FILTER_SANITIZE_NUMBER_INT ),
			)
		);
	}

	/**
	 * Persist the submitted settings.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to edit these settings.', 'immens-forecast' ), 403 );
		}

		check_admin_referer( 'immens_forecast_save_settings' );

		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer().

		Settings::update( Settings::sanitize( (array) $input ) );

		wp_safe_redirect( admin_url( 'admin.php?page=immens-forecast-settings&updated=1' ) );
		exit;
	}

	/**
	 * Resolve a template path, allowing theme/plugin overrides.
	 *
	 * @param string $template Template file name.
	 * @return string
	 */
	public static function template_path( string $template ): string {
		$default = IMMENS_FORECAST_PATH . 'templates/' . $template;
		$path    = (string) apply_filters( 'immens_forecast_template_path', $default, $template );

		return ( '' !== $path && file_exists( $path ) ) ? $path : $default;
	}

	/**
	 * Render a plugin template with the given arguments.
	 *
	 * @param string $template Template file name.
	 * @param array  $args     Template arguments exposed as $args.
	 * @return void
	 */
	private static function render( string $template, array $args ): void {
		$path = self::template_path( $template );
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		include $path;
	}

	/**
	 * Capability guard for the screens.
	 *
	 * @return void
	 */
	private static function guard_render(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'immens-forecast' ), 403 );
		}
	}

	/**
	 * Describe the registered sources for the UI.
	 *
	 * @return array
	 */
	private static function describe_sources(): array {
		$sources = array();

		foreach ( (array) apply_filters( 'immens_forecast_sources', array() ) as $source ) {
			if ( ! $source instanceof SourceInterface ) {
				continue;
			}

			$metrics = array();
			foreach ( (array) $source->get_metrics() as $id => $label ) {
				$metrics[ sanitize_key( (string) $id ) ] = (string) $label;
			}

			$sources[ $source->get_id() ] = array(
				'label'       => $source->get_label(),
				'description' => $source->get_description(),
				'available'   => $source->is_available(),
				'metrics'     => $metrics,
			);
		}

		return $sources;
	}

	/**
	 * Describe the registered providers for the UI.
	 *
	 * @return array
	 */
	private static function describe_providers(): array {
		$providers = array();

		foreach ( (array) apply_filters( 'immens_forecast_providers', array() ) as $provider ) {
			if ( ! $provider instanceof ProviderInterface ) {
				continue;
			}

			$capabilities = $provider->get_capabilities();

			$providers[ $provider->get_id() ] = array(
				'label'       => $provider->get_label(),
				'description' => $provider->get_description(),
				'available'   => $provider->is_available(),
				'remote'      => is_array( $capabilities ) && ! empty( $capabilities['remote'] ),
			);
		}

		return $providers;
	}

	/**
	 * Describe the stored manual datasets.
	 *
	 * @return array
	 */
	private static function describe_datasets(): array {
		$datasets = array();

		foreach ( (array) Datasets::all() as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}

			$datasets[ (int) $row['id'] ] = array(
				'name'        => isset( $row['name'] ) ? (string) $row['name'] : '',
				'granularity' => isset( $row['granularity'] ) ? (string) $row['granularity'] : 'day',
			);
		}

		return $datasets;
	}
}
```

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter PageTest
```
Expected: `OK (7 tests)`.

- [ ] **Step 3: Templates**

`templates/forecast-page.php`:
```php
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

	<script type="application/json" id="immens-forecast-meta"><?php echo wp_json_encode( $meta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON data block. ?></script>

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
```

`templates/settings-page.php`:
```php
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
```

- [ ] **Step 4: Admin assets**

`assets/css/admin.css`:
```css
.immens-forecast-layout {
	display: grid;
	grid-template-columns: minmax(260px, 320px) 1fr;
	gap: 24px;
	margin-top: 16px;
}

.immens-forecast-form {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 16px;
	align-self: start;
}

.immens-forecast-field {
	margin-bottom: 12px;
}

.immens-forecast-field label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.immens-forecast-field select,
.immens-forecast-field input {
	width: 100%;
}

.immens-forecast-checkbox {
	display: block;
	margin: 4px 0 12px;
}

.immens-forecast-kpis {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 12px;
	margin-bottom: 16px;
}

.immens-forecast-kpi {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 12px 16px;
}

.immens-forecast-kpi .immens-forecast-kpi-label {
	color: #50575e;
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	display: block;
}

.immens-forecast-kpi .immens-forecast-kpi-value {
	font-size: 20px;
	font-weight: 600;
}

.immens-forecast-chart-box {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 16px;
	margin-bottom: 16px;
}

.immens-forecast-table table {
	margin-top: 4px;
}

.immens-forecast-status.is-error {
	color: #d63638;
}

.immens-forecast-status.is-success {
	color: #007017;
}

.immens-forecast-datasets .submitdelete {
	color: #b32d2e;
}

@media (max-width: 960px) {
	.immens-forecast-layout {
		grid-template-columns: 1fr;
	}
}
```

`assets/js/admin.js`:
```js
( function () {
	'use strict';

	var config = window.immensForecast;
	if ( ! config ) {
		return;
	}

	var metaElement = document.getElementById( 'immens-forecast-meta' );
	var meta = { sources: {}, providers: {}, defaults: {} };
	if ( metaElement ) {
		try {
			meta = JSON.parse( metaElement.textContent );
		} catch ( error ) {
			meta = { sources: {}, providers: {}, defaults: {} };
		}
	}

	var chart = null;

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		Object.keys( data ).forEach( function ( key ) {
			if ( data[ key ] !== null && data[ key ] !== undefined ) {
				body.append( key, data[ key ] );
			}
		} );
		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function showError( container, message ) {
		if ( ! container ) {
			return;
		}
		container.querySelector( 'p' ).textContent = message || config.i18n.error;
		container.hidden = false;
	}

	function hideError( container ) {
		if ( container ) {
			container.hidden = true;
		}
	}

	function renderKpis( payload ) {
		var container = document.getElementById( 'immens-forecast-kpis' );
		if ( ! container ) {
			return;
		}
		var kpis = payload.kpis || {};
		var rows = [
			[ 'Forecast total', formatNumber( kpis.forecast_total ) ],
			[ 'Previous total', formatNumber( kpis.previous_total ) ],
			[ 'Delta', formatPercent( kpis.delta_pct ) ],
			[ 'Interval width', formatNumber( kpis.interval_width ) ]
		];
		container.innerHTML = '';
		rows.forEach( function ( row ) {
			var card = document.createElement( 'div' );
			card.className = 'immens-forecast-kpi';
			var label = document.createElement( 'span' );
			label.className = 'immens-forecast-kpi-label';
			label.textContent = row[ 0 ];
			var value = document.createElement( 'span' );
			value.className = 'immens-forecast-kpi-value';
			value.textContent = row[ 1 ];
			card.appendChild( label );
			card.appendChild( value );
			container.appendChild( card );
		} );
	}

	function formatNumber( value ) {
		if ( value === null || value === undefined || isNaN( value ) ) {
			return '--';
		}
		return Number( value ).toLocaleString();
	}

	function formatPercent( value ) {
		if ( value === null || value === undefined || isNaN( value ) ) {
			return '--';
		}
		return Number( value ).toFixed( 1 ) + '%';
	}

	function pad( length ) {
		var out = [];
		for ( var i = 0; i < length; i++ ) {
			out.push( null );
		}
		return out;
	}

	function renderChart( payload, canvas ) {
		if ( ! canvas || typeof Chart === 'undefined' ) {
			return;
		}

		var history = payload.history || { dates: [], values: [] };
		var historyLength = history.dates.length;
		var futureLength = ( payload.dates || [] ).length;
		var lower = payload.quantiles ? payload.quantiles[ String( Object.keys( payload.quantiles )[ 0 ] ) ] : null;
		var upper = payload.quantiles ? payload.quantiles[ String( Object.keys( payload.quantiles ).pop() ) ] : null;

		var datasets = [
			{
				label: 'History',
				data: history.values.concat( pad( futureLength ) ),
				borderColor: '#2271b1',
				backgroundColor: 'rgba(34, 113, 177, 0.08)',
				pointRadius: 0,
				borderWidth: 2,
				tension: 0.2
			}
		];

		if ( upper && lower ) {
			datasets.push( {
				label: 'Upper',
				data: pad( historyLength ).concat( upper ),
				borderColor: 'rgba(214, 54, 56, 0.35)',
				backgroundColor: 'rgba(214, 54, 56, 0.12)',
				pointRadius: 0,
				borderWidth: 1,
				tension: 0.2
			} );
			datasets.push( {
				label: 'Lower',
				data: pad( historyLength ).concat( lower ),
				borderColor: 'rgba(214, 54, 56, 0.35)',
				backgroundColor: 'rgba(214, 54, 56, 0.12)',
				fill: '-1',
				pointRadius: 0,
				borderWidth: 1,
				tension: 0.2
			} );
		}

		datasets.push( {
			label: 'Forecast',
			data: pad( historyLength ).concat( payload.median || [] ),
			borderColor: '#d63638',
			borderDash: [ 6, 4 ],
			pointRadius: 0,
			borderWidth: 2,
			tension: 0.2
		} );

		if ( chart ) {
			chart.destroy();
		}

		chart = new Chart( canvas.getContext( '2d' ), {
			type: 'line',
			data: {
				labels: ( history.dates || [] ).concat( payload.dates || [] ),
				datasets: datasets
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				animation: false,
				interaction: { mode: 'index', intersect: false },
				plugins: { legend: { position: 'bottom' } },
				scales: { y: { beginAtZero: false } }
			}
		} );
	}

	function renderTable( payload ) {
		var container = document.getElementById( 'immens-forecast-table' );
		if ( ! container ) {
			return;
		}
		var quantileKeys = payload.quantiles ? Object.keys( payload.quantiles ).sort( function ( a, b ) { return a - b; } ) : [];
		var lower = quantileKeys.length ? payload.quantiles[ quantileKeys[ 0 ] ] : null;
		var upper = quantileKeys.length ? payload.quantiles[ quantileKeys[ quantileKeys.length - 1 ] ] : null;
		var rows = [ '<table class="widefat striped"><thead><tr>' ];
		rows.push( '<th>Date</th><th>Forecast</th>' );
		if ( lower ) {
			rows.push( '<th>' + quantileKeys[ 0 ] + '</th>' );
		}
		if ( upper && quantileKeys.length > 1 ) {
			rows.push( '<th>' + quantileKeys[ quantileKeys.length - 1 ] + '</th>' );
		}
		rows.push( '</tr></thead><tbody>' );
		( payload.dates || [] ).forEach( function ( date, index ) {
			rows.push( '<tr><td>' + date + '</td><td>' + formatNumber( payload.median[ index ] ) + '</td>' );
			if ( lower ) {
				rows.push( '<td>' + formatNumber( lower[ index ] ) + '</td>' );
			}
			if ( upper && quantileKeys.length > 1 ) {
				rows.push( '<td>' + formatNumber( upper[ index ] ) + '</td>' );
			}
			rows.push( '</tr>' );
		} );
		rows.push( '</tbody></table>' );
		container.innerHTML = rows.join( '' );
	}

	function refreshManualField() {
		var source = document.getElementById( 'immens-forecast-source' );
		var manual = document.getElementById( 'immens-forecast-manual-field' );
		var metric = document.getElementById( 'immens-forecast-metric' );
		if ( ! source || ! meta.sources[ source.value ] ) {
			return;
		}
		if ( manual ) {
			manual.hidden = 'manual' !== source.value;
		}
		if ( metric ) {
			metric.innerHTML = '';
			var metrics = meta.sources[ source.value ].metrics || {};
			Object.keys( metrics ).forEach( function ( id ) {
				var option = document.createElement( 'option' );
				option.value = id;
				option.textContent = metrics[ id ];
				metric.appendChild( option );
			} );
		}
	}

	var form = document.getElementById( 'immens-forecast-form' );
	if ( form ) {
		var source = document.getElementById( 'immens-forecast-source' );
		if ( source ) {
			source.addEventListener( 'change', refreshManualField );
		}
		refreshManualField();

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var errorBox = document.getElementById( 'immens-forecast-error' );
			var fallbackBox = document.getElementById( 'immens-forecast-fallback' );
			hideError( errorBox );
			if ( fallbackBox ) {
				fallbackBox.hidden = true;
			}

			var data = {
				source: form.source.value,
				metric: form.metric.value,
				granularity: form.granularity.value,
				history_days: form.history_days.value,
				horizon: form.horizon.value,
				provider: form.provider.value,
				model: form.model.value,
				refresh: form.refresh.checked ? 1 : ''
			};
			if ( 'manual' === form.source.value ) {
				data.dataset_id = form.dataset_id.value;
			}

			post( 'immens_forecast_run', data ).then( function ( response ) {
				if ( ! response || ! response.success ) {
					showError( errorBox, response && response.data ? response.data.message : null );
					return;
				}
				var payload = response.data;
				renderKpis( payload );
				renderChart( payload, document.getElementById( 'immens-forecast-chart' ) );
				renderTable( payload );
				if ( payload.fallback && fallbackBox ) {
					fallbackBox.querySelector( 'p' ).textContent = config.i18n.fallback + ( payload.fallback_reason ? ' (' + payload.fallback_reason + ')' : '' );
					fallbackBox.hidden = false;
				}
			} ).catch( function () {
				showError( errorBox, null );
			} );
		} );
	}

	function endpointData() {
		return {
			endpoint_url: document.getElementById( 'immens-forecast-setting-url' ).value,
			endpoint_token: document.getElementById( 'immens-forecast-setting-token' ).value,
			endpoint_model: document.getElementById( 'immens-forecast-setting-model' ).value,
			endpoint_timeout: document.getElementById( 'immens-forecast-setting-timeout' ).value
		};
	}

	function bindEndpointButton( id, action, okMessage ) {
		var button = document.getElementById( id );
		var status = document.getElementById( 'immens-forecast-endpoint-status' );
		if ( ! button || ! status ) {
			return;
		}
		button.addEventListener( 'click', function () {
			var original = button.textContent;
			button.disabled = true;
			button.textContent = config.i18n.loading;
			status.className = 'immens-forecast-status';
			status.textContent = '';
			post( action, endpointData() ).then( function ( response ) {
				button.disabled = false;
				button.textContent = original;
				if ( response && response.success ) {
					status.className = 'immens-forecast-status is-success';
					status.textContent = okMessage;
				} else {
					status.className = 'immens-forecast-status is-error';
					status.textContent = response && response.data ? response.data.message : config.i18n.testFailed;
				}
			} ).catch( function () {
				button.disabled = false;
				button.textContent = original;
				status.className = 'immens-forecast-status is-error';
				status.textContent = config.i18n.testFailed;
			} );
		} );
	}

	bindEndpointButton( 'immens-forecast-test', 'immens_forecast_test_endpoint', config.i18n.testOk );
	bindEndpointButton( 'immens-forecast-models', 'immens_forecast_load_models', config.i18n.modelsLoaded );
}() );
```

- [ ] **Step 5: Vendor Chart.js (bundled)**

```powershell
New-Item -ItemType Directory -Force "assets\vendor" | Out-Null
Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.umd.min.js" -OutFile "assets\vendor\chart.umd.min.js"
(Get-Item "assets\vendor\chart.umd.min.js").Length
(Get-FileHash "assets\vendor\chart.umd.min.js" -Algorithm SHA256).Hash | Set-Content "assets\vendor\chart.umd.min.js.sha256"
```

Expected: size >= 150000 bytes (Chart.js 3.9.1 UMD ≈ 200 KB). The `.sha256` file pins the artifact for CI and release packaging (Task 16). If the download is unavailable, fall back to `npm pack chart.js@3.9.1` and extract `package/dist/chart.umd.min.js`.

- [ ] **Step 6: CSV import and dataset deletion handlers**

Append to `includes/Admin/Page.php` (and add `use ImmensForecast\Sources\CsvParser;` to the imports):

```php
	/**
	 * Import a CSV dataset.
	 *
	 * @return void
	 */
	public static function handle_import(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to import data.', 'immens-forecast' ), 403 );
		}

		check_admin_referer( 'immens_forecast_import_csv' );

		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer().

		$name        = isset( $input['dataset_name'] ) ? sanitize_text_field( (string) $input['dataset_name'] ) : '';
		$granularity = isset( $input['dataset_granularity'] ) ? sanitize_key( (string) $input['dataset_granularity'] ) : 'day';
		$raw         = isset( $input['dataset_csv'] ) ? (string) $input['dataset_csv'] : '';

		$points = CsvParser::parse( $raw, $granularity );
		if ( is_wp_error( $points ) ) {
			self::redirect_settings( 'import_failed=1' );
		}

		$result = Datasets::create( $name, $granularity, $points );
		if ( is_wp_error( $result ) ) {
			self::redirect_settings( 'import_failed=1' );
		}

		self::redirect_settings( 'imported=' . (int) $result );
	}

	/**
	 * Delete a stored dataset.
	 *
	 * @return void
	 */
	public static function handle_delete_dataset(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to delete data.', 'immens-forecast' ), 403 );
		}

		check_admin_referer( 'immens_forecast_delete_dataset' );

		$id = (int) filter_input( INPUT_GET, 'dataset', FILTER_SANITIZE_NUMBER_INT );
		if ( $id > 0 ) {
			Datasets::delete( $id );
		}

		self::redirect_settings( 'deleted=1' );
	}

	/**
	 * Redirect back to the settings screen.
	 *
	 * @param string $query Extra query string.
	 * @return void
	 */
	private static function redirect_settings( string $query ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=immens-forecast-settings&' . $query ) );
		exit;
	}
```

Then wire everything in `includes/Plugin.php` `boot()`:

```php
add_action( 'admin_menu', array( Admin\Page::class, 'register' ) );
add_action( 'admin_enqueue_scripts', array( Admin\Page::class, 'enqueue' ) );
add_action( 'admin_post_immens_forecast_save_settings', array( Admin\Page::class, 'handle_save' ) );
add_action( 'admin_post_immens_forecast_import_csv', array( Admin\Page::class, 'handle_import' ) );
add_action( 'admin_post_immens_forecast_delete_dataset', array( Admin\Page::class, 'handle_delete_dataset' ) );
```

- [ ] **Step 7: Run the tests, PHPCS and commit**

```powershell
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist --filter PageTest
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
git add -A
git commit -m "feat(admin): add forecast UI, settings screen and assets"
```

Expected: `OK (7 tests)`, then `0 errors`.

### Task 15: Uninstall and data lifecycle

**Files:**
- Create: `uninstall.php`

**Interfaces:**
- Consumes: option `immens_forecast_settings` (with `delete_data_on_uninstall`), option `immens_forecast_db_version` (Datasets), table `{prefix}immens_forecast_datasets`, transients `_transient_immens_forecast_*` (cache + locks), `WP_UNINSTALL_PLUGIN` guard.
- Produces: nothing (terminal lifecycle script). Activation/deactivation already exist in `Plugin::activate()`/`Plugin::deactivate()` (Task 1); do not change them here.
- Decisions: cache/lock transients are always purged on uninstall; **user data is preserved unless `delete_data_on_uninstall` is truthy**; no cron is registered in the free plugin (keeps w.org review simple).

- [ ] **Step 1: Write the uninstall script**

`uninstall.php`:
```php
<?php
/**
 * Uninstall routine.
 *
 * @package ImmensForecast
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$immens_forecast_option = 'immens_forecast_settings';
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
```

- [ ] **Step 2: Syntax check**

```powershell
tools\php-cli.cmd -l uninstall.php
```
Expected: `No syntax errors detected in uninstall.php`.

- [ ] **Step 3: Commit**

```powershell
git add uninstall.php
git commit -m "feat(lifecycle): add opt-in uninstall cleanup"
```

Expected: clean commit.

### Task 16: Packaging, documentation and CI

**Files:**
- Create: `readme.txt`
- Create: `README.md`
- Create: `tools/build-zip.ps1`
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `.distignore` (Task 1), `assets/vendor/chart.umd.min.js.sha256` (Task 14), the `Version:` header of `immens-forecast.php` (Task 1), composer scripts `test`/`lint` (Task 1).
- Produces: `dist/immens-forecast-<version>.zip` with the plugin folder at the zip root (WordPress installable), and a CI workflow that runs tests, PHPCS and the vendored-asset checksum.
- Decisions: no `.pot` ship in F1 (translations go through translate.wordpress.org; `wp i18n make-pot` is documented in the README); `README.md` is GitHub-facing and excluded from the zip, `readme.txt` is w.org-facing and included.

- [ ] **Step 1: Write the WordPress.org readme**

`readme.txt`:
```
=== Immens Forecast – Sales & Time Series Forecasting ===
Contributors: studioimmens
Tags: forecasting, woocommerce, sales, time series, analytics
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Forecast sales and time series with an offline baseline model or any external forecasting endpoint you connect.

== Description ==

Immens Forecast projects future sales and any numeric time series, directly from your WordPress dashboard.

* **Offline baseline model** – get probabilistic forecasts (median plus configurable quantiles) with zero external services.
* **WooCommerce source** – forecast revenue, orders or product counts from your store history.
* **Manual CSV datasets** – paste dated values (or bare values) and forecast them immediately.
* **Bring your own endpoint** – connect any service exposing the timestral-api style `/forecast`, `/health` and `/models` routes with a bearer token.
* **Caching and safety rails** – results are cached, requests are locked per series and rate-limit errors surface clearly.

The plugin works standalone and does not require an account. Your data stays on your site unless you explicitly configure an external endpoint.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it through the Plugins screen.
2. Activate the plugin and open the **Forecast** menu.
3. (Optional) Configure an external endpoint under **Forecast → Settings**.

== FAQ ==

= Does it need WooCommerce? =
No. WooCommerce adds the store source, but you can forecast manual CSV datasets on any site.

= Where are the forecasts computed? =
Locally, with the bundled baseline model, unless you configure an external endpoint.

== Changelog ==

= 0.1.0 =
* Initial release: budget-based baseline forecasting, WooCommerce and manual CSV sources, external endpoint support, caching, admin UI and CSV import.

== Upgrade Notice ==

= 0.1.0 =
First public release.
```

- [ ] **Step 2: Write the GitHub README**

`README.md`:
```markdown
# Immens Forecast

Sales & time series forecasting for WordPress. Ships an offline baseline model and can talk to any external forecasting endpoint (timestral-api compatible).

## Development

Requirements: PHP 7.4+, Composer.

```bash
composer install
composer test   # PHPUnit (unit suite, no WordPress required)
composer lint   # PHPCS with WordPress-Core/Extra + PHPCompatibilityWP
```

## Building the release zip

```powershell
powershell -ExecutionPolicy Bypass -File tools\build-zip.ps1
```

Creates `dist/immens-forecast-<version>.zip` honouring `.distignore`.

## Translations

Strings use the `immens-forecast` text domain. Generate the template with:

```bash
wp i18n make-pot . languages/immens-forecast.pot --skip-audit
```

## Third-party assets

* Chart.js 3.9.1 (MIT) is vendored in `assets/vendor/chart.umd.min.js`; its SHA-256 is pinned in `assets/vendor/chart.umd.min.js.sha256` and verified in CI.

## License

GPL-2.0-or-later.
```

- [ ] **Step 3: Write the release build script**

`tools/build-zip.ps1`:
```powershell
param(
	[string]$OutputDir = "dist"
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot

$main = Get-Content (Join-Path $root "immens-forecast.php") -Raw
if ($main -notmatch "Version:\s*([0-9]+\.[0-9]+\.[0-9]+)") {
	throw "Version header not found in immens-forecast.php"
}
$version = $Matches[1]

$stage  = Join-Path ([System.IO.Path]::GetTempPath()) ("immens-forecast-build-" + [guid]::NewGuid().ToString("N"))
$target = Join-Path $stage "immens-forecast"
New-Item -ItemType Directory -Path $target -Force | Out-Null

$exclude = @(
	".git", ".superpowers", ".github", ".gitignore", ".gitattributes", ".distignore",
	"composer.json", "composer.lock", "phpcs.xml.dist", "phpunit.xml.dist",
	"tests", "docs", "tools", "vendor", "node_modules", "build", "dist",
	"CHANGELOG.md", "README.md"
)

Get-ChildItem -Path $root -Force | Where-Object { $exclude -notcontains $_.Name } | ForEach-Object {
	Copy-Item -Path $_.FullName -Destination $target -Recurse -Force
}

New-Item -ItemType Directory -Path (Join-Path $root $OutputDir) -Force | Out-Null
$zip = Join-Path $root ($OutputDir + "\immens-forecast-" + $version + ".zip")
if (Test-Path $zip) {
	Remove-Item $zip
}

Compress-Archive -Path $target -DestinationPath $zip

Remove-Item $stage -Recurse -Force
Write-Host ("Built " + $zip)
```

- [ ] **Step 4: Write the CI workflow**

`.github/workflows/ci.yml`:
```yaml
name: CI

on:
  push:
    branches: [ main ]
  pull_request:

jobs:
  tests:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: [ '7.4', '8.0', '8.1', '8.2', '8.3' ]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          tools: composer:v2
          coverage: none
      - run: composer install --prefer-dist --no-progress
      - run: composer test

  coding-standards:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer:v2
          coverage: none
      - run: composer install --prefer-dist --no-progress
      - run: composer lint

  vendored-assets:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Verify vendored Chart.js checksum
        run: |
          expected="$(tr -d '\r\n' < assets/vendor/chart.umd.min.js.sha256)"
          actual="$(sha256sum assets/vendor/chart.umd.min.js | cut -d' ' -f1)"
          if [ "${expected,,}" != "${actual,,}" ]; then
            echo "chart.umd.min.js checksum mismatch: $expected != $actual"
            exit 1
          fi

  package:
    runs-on: ubuntu-latest
    needs: [ tests, coding-standards, vendored-assets ]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: none
      - name: Build zip
        run: bash tools/build-zip.sh
      - uses: actions/upload-artifact@v4
        with:
          name: immens-forecast-zip
          path: dist/*.zip
```

The CI runs on Linux, so `tools/build-zip.sh` mirrors the PowerShell script for CI use:

`tools/build-zip.sh`:
```bash
#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
version="$(grep -m1 'Version:' "$root/immens-forecast.php" | sed -E 's/.*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*/\1/')"
test -n "$version"

stage="$(mktemp -d)"
mkdir -p "$stage/immens-forecast"

cd "$root"
tar --exclude='./.git' \
	--exclude='./.superpowers' \
	--exclude='./.github' \
	--exclude='./.gitignore' \
	--exclude='./.gitattributes' \
	--exclude='./.distignore' \
	--exclude='./composer.json' \
	--exclude='./composer.lock' \
	--exclude='./phpcs.xml.dist' \
	--exclude='./phpunit.xml.dist' \
	--exclude='./tests' \
	--exclude='./docs' \
	--exclude='./tools' \
	--exclude='./vendor' \
	--exclude='./node_modules' \
	--exclude='./build' \
	--exclude='./dist' \
	--exclude='./CHANGELOG.md' \
	--exclude='./README.md' \
	-cf - . | tar -xf - -C "$stage/immens-forecast"

mkdir -p "$root/dist"
(cd "$stage" && zip -qr "$root/dist/immens-forecast-$version.zip" immens-forecast)
rm -rf "$stage"
echo "Built $root/dist/immens-forecast-$version.zip"
```

Add `tools/build-zip.sh` to the Task 16 file list and make it executable (`git update-index --chmod=+x tools/build-zip.sh` after staging on Windows).

- [ ] **Step 5: Build the zip and verify its contents**

```powershell
powershell -ExecutionPolicy Bypass -File tools\build-zip.ps1
Expand-Archive -Path (Get-ChildItem dist\*.zip).FullName -DestinationPath dist\verify -Force
Get-ChildItem dist\verify\immens-forecast | Select-Object -ExpandProperty Name
```
Expected: the listing shows at least `immens-forecast.php`, `includes`, `templates`, `assets`, `uninstall.php`, `readme.txt`, `LICENSE`, `languages` — and no `tests`, `docs`, `tools`, `vendor`, `composer.json`, `README.md`. Clean up with `Remove-Item dist\verify -Recurse -Force`.

- [ ] **Step 6: Lint, run the full suite and commit**

```powershell
tools\php-cli.cmd vendor\squizlabs\php_codesniffer\bin\phpcs --standard=phpcs.xml.dist
tools\php-cli.cmd vendor\phpunit\phpunit\phpunit --configuration phpunit.xml.dist
git add -A
git commit -m "chore(release): add packaging, readme and CI"
```

Expected: `0 errors` and the full unit suite green; the test count must equal the count from the previous task's run plus any tests added in this task.

### Task 17: End-to-end verification on the local site

**Files:**
- No source files. Manual (or Playwright-assisted) checklist on the local WordPress site.

**Interfaces:**
- Consumes: the packaged plugin (Task 16), the local site at `http://localhost:10004` (wp-admin credentials `nat` / `nat`), WooCommerce if active.
- Produces: a filled execution log at the bottom of this plan (Task 18 section) with pass/fail evidence per item.

- [ ] **Step 1: Preparation**

```powershell
Invoke-WebRequest -Uri "http://localhost:10004/wp-login.php" -UseBasicParsing -TimeoutSec 60 | Select-Object -ExpandProperty StatusCode
```
Expected: `200`. If the site is down, start it from the Local app first. The plugin already lives in the site's `wp-content/plugins/immens-forecast` folder because that is this workspace.

- [ ] **Step 2: Activation**

Activate **Immens Forecast** from Plugins. Expected: activation succeeds, a `Forecast` menu appears, and `immens_forecast_settings` exists with the `delete_data_on_uninstall => false` key. Verify the datasets table exists (Local app → Database → Adminer, or `wp db query 'SHOW TABLES LIKE "%immens_forecast%"'` if WP-CLI is available).

- [ ] **Step 3: Baseline forecast smoke test**

Forecast screen → pick the WooCommerce source (revenue, daily, 90 days history, 14 periods) → **Run forecast**. Expected: chart with history + dashed median + P10–P90 band, KPI cards, result table; no PHP notices in `wp-content/debug.log` (enable `WP_DEBUG_LOG` in `wp-config.php` first). If WooCommerce is not active, use a CSV dataset (next step) instead.

- [ ] **Step 4: CSV import round trip**

Settings → Manual data: import a dataset named `demo` (daily) with 60 dated values. Expected: "Dataset imported." notice; the dataset appears in **Stored datasets** and in the forecast screen's dataset select. Run a forecast with source `manual` and that dataset: chart renders. Delete the dataset from Settings: it disappears from both lists.

- [ ] **Step 5: Endpoint settings and error surfaces**

Settings → set URL `https://127.0.0.1:9`, token `test`, timeout 5 → save → **Test connection**. Expected: a clear error message (connection refused/timeout) shown next to the buttons, no fatal error. **Load models** shows the same kind of message. Clear the URL → save → the forecast screen shows the "No external endpoint is configured" notice again and baseline runs locally.

- [ ] **Step 6: Cache and lock behaviour**

Run the same forecast twice within a minute with **Ignore the cache** unchecked: the second run returns quickly (cached). Check the box and run again: a new run is computed. Trigger two very fast consecutive runs (double click submit): no fatal, one of them may report the locked error.

- [ ] **Step 7: Uninstall matrix**

1. Deactivate → reactivate: settings and datasets survive.
2. Uninstall with `delete_data_on_uninstall` unchecked: options, datasets table and transients state — options + table survive, `_transient_immens_forecast_*` rows are gone.
3. Reinstall, enable the flag, uninstall: `immens_forecast_settings`, `immens_forecast_db_version` and the datasets table are gone.

- [ ] **Step 8: Record the results**

Append a short section at the end of this plan (below) with date, WordPress/PHP/WooCommerce versions and pass/fail for Steps 2–7, quoting any error verbatim.

### Task 18: Execution log

<!-- Fill during Task 17. Template:

Date: YYYY-MM-DD
Site: http://localhost:10004 (WP x.y.z, PHP x.y, WooCommerce x.y)
- Activation: PASS/FAIL — notes
- Baseline forecast: PASS/FAIL — notes
- CSV round trip: PASS/FAIL — notes
- Endpoint settings: PASS/FAIL — notes
- Cache/lock: PASS/FAIL — notes
- Uninstall matrix: PASS/FAIL — notes
-->

### Plan self-review

Run this before executing the plan:

- [ ] Every task lists exact file paths, complete code and a run command with an expected result.
- [ ] No placeholders (`TBD`, `implement later`, `similar to Task N`) except the explicit Task 18 template.
- [ ] Interfaces are consistent across tasks: `Engine::run()`/`Result::assemble()` payload keys match the JS reads (`history.dates`, `median`, `quantiles`, `kpis.*`, `fallback`, `fallback_reason`); `Ajax::NONCE` matches `Page::enqueue()` and the nonce passed to `fetch`; `CsvParser::parse()` output `['d','v']` matches `Datasets::create()` expectations.
- [ ] Test expectations match the environment: Brain Monkey stubs cover every WordPress function called before an assertion (notably `apply_filters` in capability paths).
- [ ] Expected suite counts are updated after each task (`OK (N tests)`).
- [ ] Each task ends in one commit with the message quoted in the task.
- [ ] PHPCS gates every task that touches PHP under `includes/`, `templates/` or the root (tests are excluded by `phpcs.xml.dist`).
- [ ] Pro hooks reserved but not implemented in F1 (`immens_forecast_admin_menu`, `immens_forecast_template_path`, `immens_forecast_sources/providers`) are documented in the interfaces so the Pro plan can rely on them.
