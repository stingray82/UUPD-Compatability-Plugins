# Stackless → UUPD Bridge 1.0

Install `stackless-uupd-bridge.zip` as a WordPress plugin **on the WooCommerce/Stackless store only**. Enable Stackless Software Licensing. **Do not** run `uupd-wooapi.php` at the same time: both own the UUPD compatibility routes.

## WooCommerce product configuration

1. Open Product data → **Licensing**. Enable licence keys; set Software slug, Current version, changelog and package. The optional **WordPress update artwork** fields are at the bottom of this tab when Licensing is enabled. Supply direct HTTPS image URLs for icons 128×128 / 256×256 and banners 772×250 / 1544×500. Use the Media Library picker if preferred; Stackless's product image remains the fallback.
2. Open **UUPD Loader**. Enter Vendor, select plugin/theme and Inline/Menu/Both. Set **Updater include path** and **Licence UI include path**, relative to the main plugin/theme directory (e.g. `includes/updater.php` and `includes/class-uupd-license-ui.php`). Changing these fields regenerates the loader without saving.
3. Copy **Part 1** (constants) near the top of your existing main plugin PHP file or theme `functions.php`; copy **Part 2** after the constants in that file. No `<?php` tags are generated. Put the actual UUPD source files at the selected relative paths. **Part 3**, shown only for Inline/Both, belongs inside your existing admin settings page render callback, NOT the bootloader.
4. Save the WooCommerce product to persist the new settings. Product/software slug and version are read from saved Stackless product metadata; save after changing them.

Your UUPD 2.1 updater and UUPD Licence UI continue to run on customer sites. The bridge mirrors `POST /wp-json/uupd/v1/license/{activate|deactivate|check}` and `GET /wp-json/uupd/v1/metadata` using Stackless's licence server. It preserves the UUPD UI's intentional deactivate-before-reactivate lifecycle. Signed package links remain controlled by Stackless.

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
