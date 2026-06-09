# UUPD Compatibility Layers

Compatibility plugins and integration helpers for connecting third-party licensing, checkout, membership, and product systems to the UUPD 2.0 updater and license UI flow.

This repository is intended to hold small compatibility layers that expose a consistent UUPD-style license contract to bundled WordPress plugins using `UUPD\UI\License\V1\UUPD_License_UI`.

## Contents

Current compatibility/plugin directories:

| Directory                    | Purpose                                                      |
| ---------------------------- | ------------------------------------------------------------ |
| `get-paid-uupd`              | Compatibility layer for GetPaid-backed licensing/products.   |
| `theme-bootloader-generator` | Helper/generator for theme bootloader packaging or integration. |
| `uupd-edd`                   | Compatibility layer for Easy Digital Downloads-style licensing. |
| `uupd-fluentcart`            | Compatibility layer for FluentCart-style licensing/products. |
| `uupd-hoster`                | Host/server-side UUPD compatibility or hosting support layer. |
| `uupd-wooapi`                | Compatibility layer for WooCommerce/API-backed licensing.    |
| `uupd-wsl`                   | Compatibility/support layer for WSL/local development workflows. |

## UUPD 2.0 context

UUPD 2.0 uses a vendor-aware identity model. Integrations should treat `vendor + slug` as the updater identity, not just `slug`.

UUPD 2.0 release notes describe the V2 model as using `vendor + slug` to avoid collisions across filters, cache keys, and update injections. Scoped filters use the hierarchy `uupd/<filter>`, `uupd/<filter>/<vendor>`, and `uupd/<filter>/<vendor>/<slug>`. The standard callback signature is:

```php
$value, $vendor, $slug, $instance_key
```

Cache keys are vendor-aware and use the form:

```text
uupd_<vendor>__<slug>
```

For channel-aware JSON/static metadata mode, UUPD 2.0 can also include release channel state such as `stable`, `dev`, `alpha`, `beta`, `rc`, or `prerelease`.

## What the license UI class expects

The bundled UI class communicates with a UUPD-compatible license server through three REST endpoints:

```text
POST /wp-json/uupd/v1/license/activate
POST /wp-json/uupd/v1/license/deactivate
POST /wp-json/uupd/v1/license/check
```

Every compatibility plugin in this repository should either expose these endpoints directly or adapt another licensing system so that these endpoints behave the same way from the UI class point of view.

## Request contract

For all three actions, the UI class sends a JSON request body:

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX",
  "slug": "example-plugin",
  "domain": "https://example.com"
}
```

### Fields

| Field         |   Type | Required | Description                                                  |
| ------------- | -----: | -------: | ------------------------------------------------------------ |
| `license_key` | string |      Yes | The raw license key entered by the customer or resolved from `wp-config.php`. |
| `slug`        | string |      Yes | The product/plugin slug. This must match the slug registered with UUPD. |
| `domain`      | string |      Yes | The WordPress site URL from `home_url()`. Used for activation records, limits, and checks. |

The UI class currently sends `slug`, not `vendor`, in the REST payload. The vendor is used locally by the UI/updater registration, filters, options, constants, and cache/transient names.

## Response contract

The compatibility endpoint should return JSON. The UI class is intentionally tolerant, but the safest response shape is:

```json
{
  "success": true,
  "status": "active",
  "license_id": 123,
  "message": "License activated.",
  "date_expires": "2027-06-04",
  "activations": 1,
  "max_activations": 5
}
```

### Supported response fields

| Field              |    Type |    Required | Notes                                                        |
| ------------------ | ------: | ----------: | ------------------------------------------------------------ |
| `success`          | boolean | Recommended | Used with the HTTP status code to infer active/inactive state. |
| `status`           |  string | Recommended | Prefer `active` or `inactive`. `valid` is also accepted and normalized to `active`. |
| `license_status`   |  string |    Optional | Alternate status field. Used if `status` is missing.         |
| `id`               | integer |    Optional | Alternate license ID field.                                  |
| `license_id`       | integer |    Optional | Stored as the license ID.                                    |
| `message`          |  string | Recommended | Human-readable success or error message.                     |
| `error`            |  string |    Optional | Alternate error message field. Used if `message` is missing. |
| `date_expires`     |  string | Recommended | Expiry date displayed in the UI.                             |
| `expiry`           |  string |    Optional | Alternate expiry field. Normalized to `date_expires`.        |
| `expiration_date`  |  string |    Optional | Alternate expiry field. Normalized to `date_expires`.        |
| `activations`      | integer |    Optional | Current number of activations.                               |
| `max_activations`  | integer |    Optional | Maximum activations allowed.                                 |
| `activation_limit` | integer |    Optional | Alternate maximum activations field. Normalized to `max_activations`. |

### Status handling

The UI class determines stored status like this:

1. If `status` exists, it is lowercased and used.
2. If `license_status` exists and `status` does not, it is lowercased and used.
3. If the status is `valid`, it is normalized to `active`.
4. If no known status exists, but the HTTP status is `2xx` and `success` exists, `success: true` becomes `active`; `success: false` becomes `inactive`.
5. If the request fails or the HTTP status is not `2xx`, the response is stored with an error message where available.

For maximum compatibility, always return both `success` and `status`.

## Endpoint behaviour

### Activate

```text
POST /wp-json/uupd/v1/license/activate
```

Expected behaviour:

- Validate the license key.
- Match the key to the requested `slug`.
- Register or update the current `domain` as an activation.
- Enforce activation limits where applicable.
- Return an active response if the site is allowed to receive updates.

Recommended success response:

```json
{
  "success": true,
  "status": "active",
  "license_id": 123,
  "message": "License activated.",
  "date_expires": "2027-06-04",
  "activations": 1,
  "max_activations": 5
}
```

Recommended failure response:

```json
{
  "success": false,
  "status": "inactive",
  "message": "Invalid license key."
}
```

Typical failure reasons:

- Missing license key.
- Unknown license key.
- Product/slug mismatch.
- Expired license.
- Disabled/refunded/cancelled order.
- Activation limit reached.
- Domain is blocked.

### Deactivate

```text
POST /wp-json/uupd/v1/license/deactivate
```

Expected behaviour:

- Find the activation for `license_key + slug + domain`.
- Remove or mark that activation inactive.
- Return an inactive/deactivated response.
- Treat repeated deactivation as safe and idempotent where possible.

Recommended success response:

```json
{
  "success": true,
  "status": "inactive",
  "message": "License deactivated.",
  "activations": 0,
  "max_activations": 5
}
```

Recommended failure response:

```json
{
  "success": false,
  "status": "inactive",
  "message": "License activation was not found for this domain."
}
```

The UI class deletes its local license option after a successful HTTP `2xx` deactivation. If the endpoint returns a non-`2xx` response, the UI stores the response and displays the error.

### Check

```text
POST /wp-json/uupd/v1/license/check
```

Expected behaviour:

- Validate the license key.
- Confirm the key still belongs to the requested `slug`.
- Confirm the current `domain` is activated or otherwise allowed.
- Return current license state, expiry, activation counts, and any status messages.

Recommended active response:

```json
{
  "success": true,
  "status": "active",
  "license_id": 123,
  "message": "License is active.",
  "date_expires": "2027-06-04",
  "activations": 1,
  "max_activations": 5
}
```

Recommended inactive/invalid response:

```json
{
  "success": false,
  "status": "inactive",
  "message": "License is not active for this domain."
}
```

Check should not create a new activation unless the compatibility layer explicitly chooses to support auto-healing. Activation should normally happen only through the activate endpoint.

## HTTP status guidance

| Situation                         | Recommended HTTP status | JSON status           |
| --------------------------------- | ----------------------: | --------------------- |
| Valid activation/check            |                   `200` | `active`              |
| Successful deactivation           |                   `200` | `inactive`            |
| Invalid request payload           |                   `400` | `inactive`            |
| Invalid/unknown license           |          `404` or `200` | `inactive`            |
| Expired/disabled/refunded license |          `403` or `200` | `inactive`            |
| Activation limit reached          |          `403` or `200` | `inactive`            |
| Server or upstream error          |                   `500` | `inactive` or omitted |

A strict REST API may use `4xx` for business-rule failures. A more UI-friendly compatibility layer may return `200` with `success: false` and `status: inactive`. The UI class supports both, but non-`2xx` responses are treated as failed requests and will populate `last_error`.

## Local option storage used by the UI

The UI stores license state in `wp_options` under:

```text
uupd_license_{vendor}__{slug}
```

Example:

```text
uupd_license_tdlab__example_plugin
```

Stored shape:

```php
[
    'vendor'        => 'tdlab',
    'slug'          => 'example-plugin',
    'instance_key'  => 'tdlab__example_plugin',
    'license_key'   => 'XXXX-XXXX-XXXX-XXXX',
    'status'        => 'active',
    'license_id'    => 123,
    'item_id'       => 7,
    'last_response' => [ /* raw endpoint response */ ],
    'last_check'    => 1717500000,
    'last_error'    => '',
    'last_action'   => 'activate',
]
```

When the license is managed by a `wp-config.php` constant, the raw license key is not persisted to the option.

## wp-config.php managed licenses

The UI supports code-managed licenses through a constant:

```php
define( 'UUPD_VENDOR_SLUG_LICENSE_KEY', 'XXXX-XXXX-XXXX-XXXX' );
```

Default constant format:

```text
UUPD_{VENDOR}_{SLUG}_LICENSE_KEY
```

Example:

```php
define( 'UUPD_TDLAB_EXAMPLE_PLUGIN_LICENSE_KEY', 'XXXX-XXXX-XXXX-XXXX' );
```

When a constant-managed key is present:

- The admin UI becomes read-only.
- Activation/deactivation buttons are hidden.
- Admin nags are suppressed.
- The key is injected into the updater config only after the stored status is active.
- The UI will opportunistically activate/check the key on admin requests.

## UUPD license UI registration example

```php
use UUPD\UI\License\V1\UUPD_License_UI;

UUPD_License_UI::register( [
    'vendor'         => 'tdlab',
    'slug'           => 'example-plugin',
    'item_id'        => 7,
    'plugin_name'    => 'Example Plugin',
    'license_server' => 'https://updates.example.com',
    'metadata_base'  => 'https://updates.example.com',
] );
```

Required config:

| Key              | Description                                 |
| ---------------- | ------------------------------------------- |
| `vendor`         | Vendor namespace used by UUPD 2.0.          |
| `slug`           | Product/plugin slug.                        |
| `item_id`        | Internal product/item ID.                   |
| `license_server` | Base URL for license REST endpoints.        |
| `metadata_base`  | Base URL used by the updater metadata flow. |

Common optional config:

| Key                | Description                                                  |
| ------------------ | ------------------------------------------------------------ |
| `plugin_name`      | Display name in the license UI.                              |
| `option_name`      | Override the default option key.                             |
| `menu_parent`      | Parent admin menu, `options-general.php` by default. Use `false` for inline-only usage. |
| `menu_slug`        | Override the license page slug.                              |
| `page_title`       | Admin page title.                                            |
| `menu_title`       | Admin menu title.                                            |
| `capability`       | Required capability, default `manage_options`.               |
| `cache_prefix`     | Transient prefix to flush after license changes.             |
| `license_constant` | Override the default wp-config constant.                     |
| `prefer_constant`  | Whether constant value overrides stored option, default `true`. |
| `cron_hook`        | Override scheduled check hook name.                          |

## Updater integration contract

The UI class injects the resolved license into UUPD through V2 scoped filters:

```text
uupd/server_url/{vendor}/{slug}
uupd/filter_config/{vendor}/{slug}
```

The injected updater config includes:

```php
$updater_config['server'] = $metadata_base_or_license_server;
$updater_config['key']    = $active_license_key_or_empty_string;
```

The key is only injected when the stored license status is `active` and a key exists.

Slug-only compatibility filters are intentionally not used by this UI class. Compatibility plugins should target the UUPD 2.0 vendor-aware model.

## Compatibility plugin implementation checklist

Each compatibility layer should:

- Register the three UUPD license endpoints.
- Accept JSON request bodies with `license_key`, `slug`, and `domain`.
- Validate the requested `slug` against the mapped product.
- Normalize upstream license/order/subscription states into `active` or `inactive`.
- Return `success`, `status`, `message`, and where available `license_id`, expiry, and activation counts.
- Keep activation and check behaviour separate.
- Make deactivation idempotent where possible.
- Never return raw secrets other than what the client already submitted.
- Avoid leaking customer/order data in public REST responses.
- Log enough detail for administrators, but mask license keys in logs.

## Security expectations

Compatibility endpoints should be public enough for licensed client sites to call them, but still defensive:

- Require HTTPS in production.
- Sanitize and validate all request fields.
- Rate-limit repeated failed activation/check attempts.
- Store license keys hashed where possible in the compatibility plugin’s own data model.
- Mask license keys in logs and admin UI.
- Do not expose customer PII in endpoint responses.
- Consider domain normalization before comparing activation domains.

## Domain matching guidance

The UI sends `home_url()` as `domain`. Compatibility layers should normalize consistently, for example:

- Lowercase hostnames.
- Ignore trailing slashes.
- Decide whether `http://` and `https://` should be treated as the same site.
- Decide whether `www.example.com` and `example.com` should be treated as the same site.
- Store both the original URL and normalized host where useful.

## Recommended adapter response normalizer

A compatibility plugin can normalize any upstream response to this final shape:

```php
return [
    'success'         => $is_active,
    'status'          => $is_active ? 'active' : 'inactive',
    'license_id'      => $license_id,
    'message'         => $message,
    'date_expires'    => $expiry_date,
    'activations'     => $activation_count,
    'max_activations' => $activation_limit,
];
```

## Development and testing

Suggested local test flow:

1. Install WordPress locally.
2. Install UUPD 2.0 and one compatibility plugin from this repository.
3. Register a test plugin using `UUPD_License_UI::register()` with matching `vendor`, `slug`, `license_server`, and `metadata_base`.
4. Activate a valid test license from the admin UI.
5. Confirm the stored option status becomes `active`.
6. Confirm UUPD receives the key through `uupd/filter_config/{vendor}/{slug}`.
7. Run a check request and confirm status remains accurate.
8. Deactivate and confirm the local option is removed or marked inactive.

Useful debug hook:

```php
add_filter( 'updater_enable_debug', '__return_true' );

add_action( 'uupd/log', function ( $message, $slug, $context ) {
    error_log( '[UUPD] ' . $slug . ' ' . $message . ' ' . wp_json_encode( $context ) );
}, 10, 3 );
```

## Notes for maintainers

- Keep the UUPD-facing contract stable even when upstream platforms differ.
- Add platform-specific behaviour inside the compatibility layer, not inside client plugins.
- Prefer explicit mapping tables for product IDs, download IDs, plan IDs, or SKU values.
- Document each compatibility plugin’s upstream requirements in its own subdirectory README.
- Use the same words for statuses across all adapters: `active`, `inactive`, `expired`, `disabled`, `refunded`, `cancelled`, and normalize to `active`/`inactive` before returning to the UI.

## License

GPL 3.0
