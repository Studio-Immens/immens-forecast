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

Creates `dist/immens-forecast-<version>.zip`. Build exclusions are defined in the build scripts, mirroring `.distignore`.

## Translations

Strings use the `immens-forecast` text domain. Generate the template with:

```bash
wp i18n make-pot . languages/immens-forecast.pot --skip-audit
```

## Third-party assets

* Chart.js 3.9.1 (MIT) is vendored in `assets/vendor/chart.umd.min.js`; its SHA-256 is pinned in `assets/vendor/chart.umd.min.js.sha256` and verified in CI.

## License

GPL-2.0-or-later.
