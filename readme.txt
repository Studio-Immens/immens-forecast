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
* **Bring your own endpoint** – connect any service exposing the timestral-api style `/v1/forecast`, `/health` and `/v1/models` routes with a bearer token.
* **Caching and safety rails** – results are cached, requests are locked per user and rate-limit errors surface clearly.

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
