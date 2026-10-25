# Stackless → UUPD Bridge 1.1.0

Install `stackless-uupd-bridge.zip` as a WordPress plugin **on the WooCommerce/Stackless store only**. Enable Stackless Software Licensing. **Do not** run `uupd-wooapi.php` at the same time: both own the UUPD compatibility routes.

## WooCommerce product configuration

1. Open Product data → **Licensing**. Enable licence keys; set Software slug, Current version, changelog and package. The optional **WordPress update artwork** fields are at the bottom of this tab when Licensing is enabled. Supply direct HTTPS image URLs for icons 128×128 / 256×256 and banners 772×250 / 1544×500. Use the Media Library picker if preferred; Stackless's product image remains the fallback.
2. Open **UUPD Loader**. Enter Vendor, select plugin/theme and Inline/Menu/Both. Configure **Menu placement**, parent slug, target/new page slug, and (for newly created menus) menu/page titles. Set **Updater include path** and **Licence UI include path**, relative to the main plugin/theme directory (e.g. `includes/updater.php` and `includes/class-uupd-license-ui.php`). Changing these fields regenerates the loader without saving.
3. Copy **Part 1** (constants) near the top of your existing main plugin PHP file or theme `functions.php`; copy **Part 2** after the constants in that file. No `<?php` tags are generated. Put the actual UUPD source files at the selected relative paths. **Part 3**, shown only for Inline/Both, belongs inside your existing admin settings page render callback, NOT the bootloader.
4. Save the WooCommerce product to persist the new settings. Product/software slug and version are read from saved Stackless product metadata; save after changing them.

Your UUPD 2.1 updater and UUPD Licence UI continue to run on customer sites. The bridge mirrors `POST /wp-json/uupd/v1/license/{activate|deactivate|check}` and `GET /wp-json/uupd/v1/metadata` using Stackless's licence server. It preserves the UUPD UI's intentional deactivate-before-reactivate lifecycle. Signed package links remain controlled by Stackless.

## Admin menu placement and activation links (unreleased 1.1.0 corrections)

**No client library edits are required:** continue packaging the existing `class-uupd-license-ui.php` and `updater.php` unchanged. The generator produces the correct License UI registration arguments and, for existing custom admin pages, an isolated compatibility notice.

| Menu placement | What the generated loader does | Setup |
| --- | --- | --- |
| **Use existing settings page** | Sets `menu_parent => false` and points `menu_slug` at that page. Does **not** register another menu or submenu. | Choose **Inline only**; enter exact existing page slug; insert Part 3 in its settings callback. |
| **Create new top-level menu** | Sets `menu_parent => 'top_level'` so the existing License UI registers `add_menu_page()`. | Choose **Menu only** or **Inline + menu**; enter unique new page slug and optional menu/page titles. |
| **Create submenu under parent** | Passes the parent slug to the existing License UI so it registers `add_submenu_page()`. | Choose **Menu only** or **Inline + menu**; enter the parent slug and a unique child page slug. |

For **existing pages** under WordPress Settings (`options-general.php`), the original License UI link resolves to `options-general.php?page=TARGET_SLUG`. For **existing custom top-level pages** (parent slug such as `my-plugin`, or `admin.php`), its inline default would point incorrectly to Settings; the generated bootloader therefore suppresses **only that licence's** built-in activation notice via the namespaced `uupd/license_ui/show_notice/VENDOR/SLUG` filter and inserts a notice targeting `admin.php?page=TARGET_SLUG`. It does not alter the UUPD files. The inline form supplies its own return URL for activation; the library's default fallback still uses Settings, so test unusual error and clear-local flows on staging.

### Example: Example Plugin

- Licence UI: `Inline only`
- Menu placement: `Use existing settings page`
- Parent menu slug: `options-general.php`
- Target page slug: `esp-example-setting-page`
- Expected activation destination: `https://test.wpcode.dev/wp-admin/options-general.php?page=esp-example-setting-page`
- Place generated Part 3 into `esp_render_settings_page()`. Do **not** create a second menu page.

The page slug is the WordPress `add_menu_page()` / `add_submenu_page()` / `add_options_page()` slug, **not** its visible title. For existing pages the target slug is REQUIRED. A missing slug now produces a clear error marker in the generated constants, rather than silently falling back to `vendor-software-slug`. Enter it in the Bridge settings, not by editing generated code. New menus may leave the slug blank to use the `vendor-software-slug` default. The generator forces inline-only configurations to avoid registering a menu. Prior products without new configuration fields retain their old default parent behaviour; a previous `top_level` setting is interpreted as new top-level placement. Existing saved configurations, REST routes and release features remain supported.

### Unreleased change list — 1.1.0 (version unchanged)

- Clarified the inline-only / existing-page selection and refreshed the preview on initial form load.
- Explicitly flagged missing existing-page slugs in generated constants. No edits to generated PHP required after selecting the correct page in the Bridge.
- Added an updated Example Plugin test package configured with `esp-example-setting-page`.

- Fixed generated **Activate License** destinations for inline licence boxes on existing settings pages.
- Added configurable **menu placement**: reuse existing page without registration, create top-level licence menu, or add licence submenu under an existing parent.
- Added persisted and live-previewed **target/new page slug**, **menu title** and **page title** settings.
- Supported custom parent menu slugs, with a generated activation-notice compatibility link for existing pages under custom top-level menus.
- Preserved original UUPD updater and Licence UI library files and backward-compatible slug fallback.
- Retained plugin header version and `Stackless_UUPD_Bridge::VERSION` at **1.1.0** for this unreleased test build.

## Release API

`POST https://YOUR-STORE/wp-json/uupd/v1/products/SOFTWARE-SLUG/release`

Authenticate with a **WordPress Application Password**, from a user with `manage_woocommerce` and edit access to the product (`publish_products` if passing `publish=1`). Use HTTPS. This is an administrator deploy endpoint, never distribute credentials to customer plugins.

Send `multipart/form-data` fields:

| Field | Description |
| --- | --- |
| `version` | **Required**, for example `1.4.0` or `v1.4.0` |
| `package` | Optional ZIP file upload with top-level folder matching software slug |
| `download_url` | Alternatively an HTTPS ZIP URL, not together with `package` |
| `changelog` | Optional HTML **prepended** to the product's changelog with a version heading |
| `requires_wp`, `tested_wp`, `requires_php` | Optional version constraints, updates Stackless licensing fields |
| `upgrade_notice` | Optional upgrade message returned in UUPD metadata |
| `icon_1x`, `icon_2x`, `banner_low`, `banner_high` | Optional direct HTTPS artwork URLs; also editable in Licensing |
| `publish` | Optional `1` to publish the product if user has permission |

Neither `package` nor `download_url` means retain the existing Stackless package. The first release requires a package. The request updates `_stackless_sl_version`, requirements, changelog and the selected download source. For uploads, the server stores the ZIP in WooCommerce's protected downloads directory and sets that file as the selected downloadable product file. **Confirm nginx or proxy rules deny direct HTTP access to WooCommerce protected uploads** before using uploaded ZIPs; `.htaccess` alone is not effective on nginx. Verify `upload_max_filesize` and `post_max_size` allow your ZIP.

Example:

```bash
curl --fail-with-body -u "$WOO_USER:$WOO_APP_PASSWORD" \
  -F version=1.4.0 -F package=@example-plugin.zip \
  --form-string 'changelog=<ul><li>Fixes</li></ul>' \
  https://your-store.example/wp-json/uupd/v1/products/example-plugin/release
```

The upload ZIP must contain `example-plugin/` as a top-level folder; avoid archiving just its contents. The API validates input before writing the updated product. It is not a transactional, roll-back-capable release system; keep backups, especially for failed WooCommerce saves.

## Deployment examples

The `examples/` folder includes `deploy.sh`, `deploy.cfg`, and copies of the supplied `generate_index.php` and interactive `myplugin_headers_rup_gpl3.0.php` (retained for familiarity). Configure `DEPLOY_TARGET` as:

- `github` — build ZIP + JSON index, publish GitHub release.
- `local` — build ZIP and copy it to `DEST_DIR`.
- `woo` — build ZIP and upload via release API.
- `github+woo` — publish the same ZIP to GitHub and the Stackless release API.
- `local+woo` — copy the ZIP locally and publish to Stackless.

Export credentials in your shell/CI secrets (`WOO_USER`, `WOO_APP_PASSWORD`, `GITHUB_TOKEN`), **not the cfg file**. The config parser does not `eval` values. The example `deploy.sh` is for Bash, with `curl`, `php`, `python3` (GitHub JSON), and `zip` or `7z`; test paths on your environment. The supplied header editor is interactive and skipped if stdin is noninteractive: for CI, pre-update your plugin headers/version first. GitHub static metadata still uses your original `generate_index.php`; Woo mode uses Stackless metadata instead. GitHub deployment in this example does not automatically commit/push your source tree.

## Compatibility and limitations

- PHP >= 7.4, WooCommerce, the supplied Stackless licensing module, UUPD v2.1, and UUPD Licence UI.
- Plugin paths are relative PHP files, not arbitrary code expressions or absolute filesystem paths.
- Product artwork overrides the icon/banner values returned by Stackless and appears in the UUPD metadata response.
- The bridge exposes the update/activation API and a deployment route; it does not replace the UUPD client source files or modify Stackless core.
- Staging test needed for licence renewal, uploaded/downloaded packages, WordPress updater, nginx protected-folder access, and the deployment script. The release route uses WordPress authentication and role capabilities; restrict issuance and handling of Application Passwords.


## Optional release management (v1.1.0)

In **Products → Edit → UUPD Loader**, enable **Optional release management** and select the **Automatic deployment target**:

- **Product only** (default): the original WooCommerce product download behaviour, with opt-in release history.
- **Product + all variations**: the same release file is inserted as Download #1 on the parent and every variation; `download_mode=append` preserves older downloads, `download_mode=replace` replaces them.

Until the checkbox is enabled, the existing route remains unchanged and no bulk variation behaviour is activated. This is configured per product.

The **Stackless Manual Release** sidebar box appears on enabled variable products. Select a ZIP from the Media Library, enter a release name, choose product-only or product + all variations, choose append/replace and optionally grant existing purchasers the new download (batched via WooCommerce Action Scheduler). Manual ZIPs are copied to the protected WooCommerce uploads release directory (configure webserver access restrictions for non-Apache servers).

**Existing deployment scripts do not change.** `deploy.sh` continues using `POST /wp-json/uupd/v1/products/{slug}/release` with its existing multipart `version`, `download_mode` and `package` fields. The saved product setting decides the scope. Optional request overrides for API clients:

- `release_scope=product|all_variations` (only applies to products that opted in)
- `grant_existing=true` (explicit opt-in; false by default)

When enabled, API releases are recorded in a per-product history in the manual release sidebar box. Grants are queued in 40-order batches, scanning qualifying completed orders (and processing orders if the store grants downloads after payment). A historical grant does not send a customer notification. Test on staging, particularly where download permissions or variation-specific restrictions are customized.
