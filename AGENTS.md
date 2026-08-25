# AGENTS.md

WordPress/WooCommerce payment gateway plugin for Bancard (Paraguay vPOS eCommerce API v0.3). Pure PHP plugin — there is no composer/npm, no build, no test suite, no lint config. Verification is manual: activate in a WP+WooCommerce install and exercise checkout/callback flows.

## Loading & entrypoints

- Entrypoint is `woocommerce-bancard.php`. It registers 3 gateways: `WC_Gateway_Bancard`, `WC_Gateway_Bancard_Zimple`, `WC_Gateway_Bancard_Tokens`.
- All classes load via `include_once` on `plugins_loaded` at priority 11, and only if `WC_Payment_Gateway` exists. Do not reference gateway classes before that hook fires.
- Class layout (`includes/`): `class-wc-gateway-bancard-api.php` (`WC_Bancard_API` HTTP client), `-base.php` (abstract shared settings/helpers), `-hooks.php` (admin metabox, order actions, health notices), plus one file per gateway and `-subscriptions.php`.

## Fragile couplings

- The confirmation callback URL `/wc-api/wc_gateway_bancard` is derived from the class name via `add_action('woocommerce_api_' . strtolower(get_class($this)))`. Renaming `WC_Gateway_Bancard` breaks the URL merchants configure in Bancard's portal.
- Version lives in 3 places and must be bumped together: plugin header `Version:` in `woocommerce-bancard.php`, the `WC_BANCARD_VERSION` constant (also used for asset cache-busting), and the badge/heading in `readme.md`.
- The `bancard-payment` page (created on activation, contains `[woocommerce_checkout]`) hosts the embedded iframe template `templates/bancard-payment-page.php` used for Checkout, Zimple and Charge3DS. Code assumes this page slug exists; missing page triggers an admin warning.
- Staging/production vPOS base URLs are hardcoded constants in `WC_Bancard_API` (`vpos.infonet.com.py`).

## Bancard API specifics

- Signatures are `md5()` of the private key concatenated with fields in exact order (`generate_hash()` uses `func_get_args()`) — argument order matters.
- Amounts must be formatted `number_format($amount, 2, '.', '')` before signing/sending.
- Every request/response passes through `sanitize_log_data()` before logging. Never log full private keys, tokens, or alias tokens.
- Callback notifications can be retried by Bancard — keep callback handling idempotent (no duplicate order notes/status changes).

## Conventions

- Order/subscription/user state persists in `_bancard_*` post/user meta keys (e.g. `_bancard_alias_token`, `_bancard_process_id`, `_bancard_is_preauthorization`). Reuse these keys instead of inventing new ones; the admin metabox reads them.
- Extensibility filters are prefixed `woocommerce_bancard_*` (e.g. `woocommerce_bancard_additional_data`, `woocommerce_bancard_billing_data`).
- Style: classic WordPress PHP — `array()` syntax (not `[]`), no namespaces, ABSPATH guard at top of every file, 4-space indent.
- All UI strings and docs are Spanish; text domain is `woocommerce-bancard`.

## Releases

- Pushing any git tag triggers `.github/workflows/main.yml`, which zips the repo and creates a GitHub release. Releases = tags; there is no other deploy pipeline.
