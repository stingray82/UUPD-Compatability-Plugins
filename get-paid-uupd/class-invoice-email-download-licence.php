<?php
/**
 * Add license keys + downloads inside the item name column
 * in GetPaid invoice emails.
 *
 * Variation-aware notes:
 * - Licences store item_id + invoice_id, not the selected variation directly.
 * - The selected variation is read from the invoice item meta.
 * - Variation download_ids point to {prefix}getpaid_downloadable_items.id.
 * - Download permissions store {prefix}getpaid_downloadable_items.download_id UUIDs.
 */

add_action( 'getpaid_email_line_item_name', 'my_gpd_email_line_item_name_license_downloads', 20, 2 );

function my_gpd_email_line_item_name_license_downloads( $item, $invoice ) {

    if ( empty( $invoice ) || empty( $item ) || ! is_object( $invoice ) || ! is_object( $item ) ) {
        return;
    }

    if ( ! function_exists( 'my_gpd_tables' ) ) {
        return;
    }

    global $wpdb;
    $tables = my_gpd_tables();

    $invoice_id = 0;
    if ( method_exists( $invoice, 'get_id' ) ) {
        $invoice_id = (int) $invoice->get_id();
    }

    $item_id = 0;
    if ( method_exists( $item, 'get_id' ) ) {
        $item_id = (int) $item->get_id();
    }

    if ( ! $invoice_id || ! $item_id ) {
        return;
    }

    $user_id = 0;

    if ( method_exists( $invoice, 'get_user_id' ) ) {
        $user_id = (int) $invoice->get_user_id();
    }

    if ( ! $user_id && method_exists( $invoice, 'get_customer_id' ) ) {
        $user_id = (int) $invoice->get_customer_id();
    }

    if ( ! $user_id ) {
        $user_id = (int) get_post_field( 'post_author', $invoice_id );
    }

    if ( ! $user_id ) {
        return;
    }

    $licenses = array();
    if ( ! empty( $tables['licenses'] ) ) {
        $licenses = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tables['licenses']} WHERE invoice_id = %d AND item_id = %d ORDER BY id ASC",
                $invoice_id,
                $item_id
            )
        );
    }

    $variation_label = '';
    if ( function_exists( 'my_gpd_get_invoice_item_variation_label' ) ) {
        $variation_label = my_gpd_get_invoice_item_variation_label( $invoice_id, $item_id );
    }

    $downloads = my_gpd_email_get_download_links_for_invoice_item( $user_id, $invoice_id, $item_id );

    if ( empty( $licenses ) && empty( $downloads ) && '' === $variation_label ) {
        return;
    }

    echo '<div class="wpinv-cart-sub-desc" style="display:block; max-width:100%; float:none; text-align:left; font-size:90%; margin-top:6px;">';

    if ( '' !== $variation_label ) {
        echo '<div style="margin:0 0 4px;">';
        echo '<span class="label-primary" style="font-weight:bold;">' . esc_html__( 'Variation:', 'getpaid' ) . '</span> ';
        echo esc_html( $variation_label );
        echo '</div>';
    }

    if ( ! empty( $licenses ) ) {
        echo '<div style="margin:0 0 4px;">';
        echo '<span class="label-primary" style="font-weight:bold;">' . esc_html__( 'License:', 'getpaid' ) . '</span> ';

        $license_lines = array();

        foreach ( $licenses as $license ) {
            $line = esc_html( $license->license_key );

            if ( ! empty( $license->status ) ) {
                $line .= ' <span class="small">(' . esc_html( ucfirst( $license->status ) ) . ')</span>';
            }

            $license_lines[] = $line;
        }

        echo wp_kses_post( implode( '<br>', $license_lines ) );
        echo '</div>';
    }

    if ( ! empty( $downloads ) ) {
        echo '<div>';
        echo '<span class="label-primary" style="font-weight:bold;">' . esc_html__( 'Download:', 'getpaid' ) . '</span> ';

        $download_links = array();

        foreach ( $downloads as $download ) {
            $download_links[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url( $download['url'] ),
                esc_html( $download['name'] )
            );
        }

        echo wp_kses_post( implode( '<br>', $download_links ) );
        echo '</div>';
    }

    echo '</div>';
}

/**
 * Build invoice-email download links using the same data model as the updater
 * and customer archive screens.
 *
 * Non-variation products:
 * - Prefer purchase-time permission rows for this invoice/user.
 * - If no permission rows exist and sync-latest is enabled, fall back to the current top file.
 *
 * Variation products:
 * - If sync-latest is enabled, show the current files attached to the purchased variation.
 * - If sync-latest is disabled, preserve purchase-time permission rows.
 * - Fallback to current variation rows if older installs have no permission rows.
 *
 * @param int $user_id
 * @param int $invoice_id
 * @param int $item_id
 * @return array[]
 */
function my_gpd_email_get_download_links_for_invoice_item( $user_id, $invoice_id, $item_id ) {
    $user_id    = absint( $user_id );
    $invoice_id = absint( $invoice_id );
    $item_id    = absint( $item_id );

    if ( ! $user_id || ! $invoice_id || ! $item_id ) {
        return array();
    }

    $sync_latest    = get_post_meta( $item_id, '_wpi_sync_latest_download', true );
    $has_variations = function_exists( 'gpd_uupd_item_has_variations' ) && gpd_uupd_item_has_variations( $item_id );
    $rows           = array();

    if ( $has_variations && $sync_latest && function_exists( 'gpd_uupd_resolve_live_download_rows' ) ) {
        $rows = gpd_uupd_resolve_live_download_rows( $item_id, $invoice_id );
    }

    if ( empty( $rows ) && function_exists( 'gpd_uupd_get_permission_download_rows' ) ) {
        $rows = gpd_uupd_get_permission_download_rows( $item_id, $invoice_id, $user_id );
    }

    if ( empty( $rows ) && $has_variations && function_exists( 'gpd_uupd_resolve_live_download_rows' ) ) {
        $rows = gpd_uupd_resolve_live_download_rows( $item_id, $invoice_id );
    }

    if ( empty( $rows ) && ! $has_variations && $sync_latest && function_exists( 'gpd_uupd_get_current_non_variation_download_rows' ) ) {
        $rows = gpd_uupd_get_current_non_variation_download_rows( $item_id, 10 );
    }

    if ( empty( $rows ) ) {
        return my_gpd_email_get_legacy_download_links_for_invoice_item( $user_id, $item_id );
    }

    $downloads = array();

    foreach ( (array) $rows as $row ) {
        $download = my_gpd_email_normalise_downloadable_row( $row, $item_id, $invoice_id );
        if ( ! $download ) {
            continue;
        }

        $hash = md5( $download['name'] . '|' . $download['url'] );
        $downloads[ $hash ] = $download;
    }

    return array_values( $downloads );
}

/**
 * Convert a downloadable_items row into an email link.
 *
 * @param object $row
 * @param int    $item_id
 * @param int    $invoice_id
 * @return array|null
 */
function my_gpd_email_normalise_downloadable_row( $row, $item_id, $invoice_id ) {
    if ( ! is_object( $row ) || empty( $row->item_file ) ) {
        return null;
    }

    $item_id    = absint( $item_id );
    $invoice_id = absint( $invoice_id );

    $name = ! empty( $row->item_name ) ? (string) $row->item_name : '';

    if ( '' === $name ) {
        $path = wp_parse_url( (string) $row->item_file, PHP_URL_PATH );
        $base = $path ? basename( $path ) : basename( (string) $row->item_file );
        $name = $base ? preg_replace( '/\.zip$/i', '', $base ) : __( 'Download', 'getpaid' );
    }

    $url = '';

    if ( ! empty( $row->id ) && function_exists( 'my_gpd_build_secure_archive_download_url' ) ) {
        $url = my_gpd_build_secure_archive_download_url( $item_id, absint( $row->id ), $invoice_id );
    }

    if ( ! $url && ! empty( $row->download_id ) && function_exists( 'my_gpd_get_permissions_map_for_user' ) && function_exists( 'my_gpd_build_secure_download_url' ) ) {
        // Last resort: use the original permission-token link if we can find the permission row.
        $permissions_map = my_gpd_get_permissions_map_for_user( get_current_user_id() );
        if ( ! empty( $permissions_map[ $item_id ][ $row->download_id ] ) ) {
            $url = my_gpd_build_secure_download_url( $permissions_map[ $item_id ][ $row->download_id ] );
        }
    }

    if ( ! $url ) {
        return null;
    }

    return array(
        'name' => $name,
        'url'  => $url,
    );
}

/**
 * Preserve the original _getpaid_item_downloads + permission-map behaviour as a
 * compatibility fallback for installs without the newer helper functions/table.
 *
 * @param int $user_id
 * @param int $item_id
 * @return array[]
 */
function my_gpd_email_get_legacy_download_links_for_invoice_item( $user_id, $item_id ) {
    if ( ! function_exists( 'my_gpd_build_secure_download_url' ) || ! function_exists( 'my_gpd_get_permissions_map_for_user' ) ) {
        return array();
    }

    $permissions_map = my_gpd_get_permissions_map_for_user( absint( $user_id ) );
    $downloads_meta  = get_post_meta( absint( $item_id ), '_getpaid_item_downloads', true );
    $downloads       = array();

    if ( empty( $downloads_meta ) || ! is_array( $downloads_meta ) ) {
        return array();
    }

    foreach ( $downloads_meta as $download ) {
        $download_id = isset( $download['download_id'] ) ? $download['download_id'] : '';

        if (
            empty( $download_id ) ||
            empty( $permissions_map[ $item_id ] ) ||
            empty( $permissions_map[ $item_id ][ $download_id ] )
        ) {
            continue;
        }

        $permission = $permissions_map[ $item_id ][ $download_id ];
        $url        = my_gpd_build_secure_download_url( $permission );

        if ( ! $url ) {
            continue;
        }

        $name = isset( $download['name'] ) ? $download['name'] : '';

        if ( ! $name ) {
            $file = isset( $download['file'] ) ? $download['file'] : '';
            $path = wp_parse_url( $file, PHP_URL_PATH );
            $base = $path ? basename( $path ) : $file;
            $name = preg_replace( '/\.zip$/i', '', $base );
        }

        if ( ! $name ) {
            $name = __( 'Download', 'getpaid' );
        }

        $hash = md5( $name . '|' . $url );
        $downloads[ $hash ] = array(
            'name' => $name,
            'url'  => $url,
        );
    }

    return array_values( $downloads );
}
