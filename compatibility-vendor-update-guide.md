# Compatibility plugin vendor update pattern

Use this checklist for each compatibility plugin that generates a bootloader or inline snippet.

## Goal
Make generated code match the new pair:
- `\UUPD\V1\UUPD_License_UI`
- `\UUPD\V2\UUPD_Updater_V2`
- vendor-aware constants and inline rendering

## Required changes

### 1. Add a global vendor option
Add a class constant like:

```php
const OPTION_VENDOR = 'your_plugin_global_vendor';
```

Add a helper:

```php
protected static function get_vendor_slug() {
    $value = get_option( self::OPTION_VENDOR, '' );
    return is_string( $value ) ? sanitize_key( $value ) : '';
}
```

### 2. Save/update the vendor setting
If the plugin already has a generator page or admin action hook, save the vendor there.
A minimal pattern is:

```php
public static function maybe_handle_copy() {
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( ! isset( $_GET['your_vendor_param'] ) ) {
        return;
    }

    $vendor = sanitize_key( wp_unslash( $_GET['your_vendor_param'] ) );

    if ( '' === $vendor ) {
        delete_option( self::OPTION_VENDOR );
    } else {
        update_option( self::OPTION_VENDOR, $vendor, false );
    }
}
```

### 3. Build the constant base from vendor + slug
Replace slug-only constant stem generation with a helper like:

```php
protected static function build_vendor_slug_constant_base( $vendor, array $product ) {
    $slug   = isset( $product['slug'] ) ? (string) $product['slug'] : '';
    $source = trim( (string) $vendor ) . '_' . trim( $slug );

    $source = strtoupper( remove_accents( $source ) );
    $source = preg_replace( '/[^A-Z0-9]+/', '_', $source );
    $source = preg_replace( '/_+/', '_', (string) $source );
    $source = trim( (string) $source, '_' );

    return $source !== '' ? $source : 'PLUGIN';
}
```

### 4. Update bootloader generation
Generated constants should now include vendor in the constant base and define a `..._VENDOR` constant.

Expected generated constants:

```php
define( 'UUPD_RUP_MY_PLUGIN_VENDOR', 'rup' );
define( 'UUPD_RUP_MY_PLUGIN_VERSION', '1.0.0' );
define( 'UUPD_RUP_MY_PLUGIN_MAIN_FILE', __FILE__ );
define( 'UUPD_RUP_MY_PLUGIN_ITEM_NUMBER', 123 );
define( 'UUPD_RUP_MY_PLUGIN_FRIENDLY_NAME', 'My Plugin' );
define( 'UUPD_RUP_MY_PLUGIN_SLUG', 'my-plugin' );
define( 'UUPD_RUP_MY_PLUGIN_SERVER_BASE', 'https://example.com/' );
```

Generated bootloader should use:

```php
\UUPD\V1\UUPD_License_UI::register([
    'vendor'         => UUPD_..._VENDOR,
    'slug'           => UUPD_..._SLUG,
    'item_id'        => UUPD_..._ITEM_NUMBER,
    'license_server' => UUPD_..._SERVER_BASE,
    'metadata_base'  => UUPD_..._SERVER_BASE,
    'plugin_name'    => UUPD_..._FRIENDLY_NAME,
    'menu_parent'    => 'top_level',
    'page_title'     => UUPD_..._FRIENDLY_NAME . ' License',
    'menu_title'     => UUPD_..._FRIENDLY_NAME . ' License',
    'capability'     => 'manage_options',
]);

\UUPD\V2\UUPD_Updater_V2::register([
    'vendor'      => UUPD_..._VENDOR,
    'plugin_file' => plugin_basename( UUPD_..._MAIN_FILE ),
    'slug'        => UUPD_..._SLUG,
    'name'        => UUPD_..._FRIENDLY_NAME,
    'version'     => UUPD_..._VERSION,
    'server'      => '',
    'license_key_transport' => 'header', // UUPD 2.2: use Authorization: Bearer for metadata.
]);
```

Do **not** generate old fields like:
- `cache_prefix => 'upd_'`
- manual `menu_slug`
- `\UUPD\V1\UUPD_Updater_V1::register(...)`

### 5. Update inline snippet generation
Generated inline snippet should be:

```php
<?php \UUPD\V1\UUPD_License_UI::render_box_for( UUPD_..._SLUG, UUPD_..._VENDOR ); ?>
```

### 6. Show vendor in generator UI or list table
Where possible, show the currently active vendor next to copy buttons or on the generator page to make debugging easier.

## Quick verification checklist

After updating a compatibility plugin, confirm:

1. A vendor can be set and saved
2. Copy bootloader includes `..._VENDOR` constant
3. Bootloader uses `UUPD_License_UI::register([ 'vendor' => ... ])`
4. Bootloader uses `\UUPD\V2\UUPD_Updater_V2::register(...)`
5. Inline snippet uses `render_box_for( SLUG_CONST, VENDOR_CONST )`
6. Constant stem includes vendor + slug, not slug alone

## Hoster-specific note
The updated Hoster file stores the vendor via `uupd_hoster_vendor` in the query string because it does not currently have a dedicated generator page.
Example admin URL:

```
/wp-admin/edit.php?post_type=downloads&uupd_hoster_vendor=rup
```

That will persist the vendor in the option used by the bootloader generator.
