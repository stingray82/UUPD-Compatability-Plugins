<?php
/**
 * Front-end license + download views for GetPaid License Manager
 * using GetPaid_Secure_Zip_Uploads::stream_zip_download().
 *
 * Shortcodes:
 *   [my_getpaid_licenses_downloads]
 *       – list view: per item → licenses + downloads
 *
 *   [my_getpaid_license_details]
 *       – detail view, uses:
 *           ?license=1        (license ID)
 *           or ?license_key=... (license key)
 *
 * Secure download handler:
 *   – ?gpd_download=<permission_id>
 *
 * Activation removal handler:
 *   – ?gpd_remove_activation=<domain>&license=<id>&_wpnonce=<nonce>
 */

/* -------------------------------------------------------------------------
 * Helpers: table names / basic fetchers
 * ---------------------------------------------------------------------- */

/**
 * Get table names (with prefix).
 */
function my_gpd_tables() {
    global $wpdb;

    return array(
        'licenses'    => $wpdb->prefix . 'getpaid_license_manager_licenses',
        'permissions' => $wpdb->prefix . 'getpaid_downloadable_item_permissions',
        'invoices'    => $wpdb->prefix . 'getpaid_invoices',
    );
}

/**
 * Check if a user has at least one active, non-expired license
 * for a given item_id.
 *
 * - status must be "active" (case-insensitive)
 * - date_expires empty or "0000-00-00 00:00:00" = never expires
 * - otherwise, must be in the future
 *
 * @param int $user_id
 * @param int $item_id
 * @return bool
 */
function my_gpd_user_has_valid_license_for_item( $user_id, $item_id ) {
    global $wpdb;
    $tables = my_gpd_tables();

    $user_id = (int) $user_id;
    $item_id = (int) $item_id;

    if ( ! $user_id || ! $item_id ) {
        return false;
    }

    $licenses = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT status, date_expires
             FROM {$tables['licenses']}
             WHERE user_id = %d
               AND item_id = %d",
            $user_id,
            $item_id
        )
    );

    if ( empty( $licenses ) ) {
        return false;
    }

    $now_ts = current_time( 'timestamp' );

    foreach ( $licenses as $lic ) {
        $status = isset( $lic->status ) ? strtolower( $lic->status ) : '';

        // Require "active"
        if ( 'active' !== $status ) {
            continue;
        }

        // No expiry or zero date = never expires.
        if ( empty( $lic->date_expires ) || '0000-00-00 00:00:00' === $lic->date_expires ) {
            return true;
        }

        $exp_ts = strtotime( $lic->date_expires );
        if ( false === $exp_ts ) {
            continue;
        }

        if ( $exp_ts >= $now_ts ) {
            // Active and not expired.
            return true;
        }
    }

    return false;
}


/**
 * Get all licenses for current user, grouped by item_id.
 *
 * @param int $user_id
 * @return array [ item_id => array( license_row, ... ), ... ]
 */
function my_gpd_get_user_licenses_grouped_by_item( $user_id ) {
    global $wpdb;
    $tables = my_gpd_tables();

    $licenses = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$tables['licenses']} WHERE user_id = %d",
            $user_id
        )
    );

    if ( empty( $licenses ) ) {
        return array();
    }

    $by_item = array();

    foreach ( $licenses as $lic ) {
        $item_id = (int) $lic->item_id;
        if ( ! $item_id ) {
            continue;
        }
        if ( ! isset( $by_item[ $item_id ] ) ) {
            $by_item[ $item_id ] = array();
        }
        $by_item[ $item_id ][] = $lic;
    }

    return $by_item;
}

/**
 * Get a single license row for current user by ID or key.
 *
 * @param array $args { 'id' => int, 'key' => string }
 * @return object|null
 */
function my_gpd_get_license_for_current_user( $args ) {
    global $wpdb;
    $tables = my_gpd_tables();

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return null;
    }

    if ( ! empty( $args['id'] ) ) {
        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tables['licenses']} WHERE id = %d AND user_id = %d",
                (int) $args['id'],
                $user_id
            )
        );
        return $license ?: null;
    }

    if ( ! empty( $args['key'] ) ) {
        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tables['licenses']} WHERE license_key = %s AND user_id = %d",
                $args['key'],
                $user_id
            )
        );
        return $license ?: null;
    }

    return null;
}

/**
 * Parse activations JSON (activated_on) into [ domain => timestamp ].
 *
 * @param object $license
 * @return array
 */
function my_gpd_get_license_activations( $license ) {
    if ( empty( $license->activated_on ) ) {
        return array();
    }

    $decoded = json_decode( $license->activated_on, true );
    if ( ! is_array( $decoded ) ) {
        return array();
    }

    return $decoded;
}

/**
 * Get downloads permissions for a user, indexed by [ item_id ][ download_id ].
 *
 * @param int $user_id
 * @return array
 */
function my_gpd_get_permissions_map_for_user( $user_id ) {
    global $wpdb;
    $tables = my_gpd_tables();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$tables['permissions']} WHERE user_id = %d",
            $user_id
        )
    );

    if ( empty( $rows ) ) {
        return array();
    }

    $map = array();

    foreach ( $rows as $row ) {
        $item_id     = (int) $row->item_id;
        $download_id = $row->download_id;

        if ( ! $item_id || ! $download_id ) {
            continue;
        }

        // OPTIONAL: only include permissions where there is a valid license.
        if ( ! my_gpd_user_has_valid_license_for_item( $user_id, $item_id ) ) {
            continue;
        }

        if ( ! isset( $map[ $item_id ] ) ) {
            $map[ $item_id ] = array();
        }

        // Keep first permission per item+download.
        if ( ! isset( $map[ $item_id ][ $download_id ] ) ) {
            $map[ $item_id ][ $download_id ] = $row;
        }
    }

    return $map;
}


/**
 * Return the downloadable files a user can access for an item, using the
 * permissions table as the source of access and _getpaid_downloadable_items as
 * the source of current file names/URLs.
 *
 * This avoids relying on _getpaid_item_downloads for shortcode display, because
 * GetPaid variation download_ids point at getpaid_downloadable_items.id while
 * permissions store getpaid_downloadable_items.download_id UUIDs.
 *
 * @param int   $user_id
 * @param int   $item_id
 * @param array $permissions_map Optional result of my_gpd_get_permissions_map_for_user().
 * @return array[] Each row: id, name, url, file, permission, menu_order, row_id.
 */
function my_gpd_get_allowed_downloads_for_user_item( $user_id, $item_id, $permissions_map = null ) {
    $user_id = (int) $user_id;
    $item_id = (int) $item_id;

    if ( ! $user_id || ! $item_id || ! function_exists( 'my_gpd_build_secure_download_url' ) ) {
        return array();
    }

    if ( function_exists( 'my_gpd_get_shortcode_download_archive_for_user_item' ) && function_exists( 'my_gpd_tables' ) ) {
        global $wpdb;
        $tables = my_gpd_tables();
        $licenses_for_item = array();

        if ( ! empty( $tables['licenses'] ) ) {
            $licenses_for_item = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$tables['licenses']} WHERE user_id = %d AND item_id = %d",
                    $user_id,
                    $item_id
                )
            );
        }

        $archive_downloads = my_gpd_get_shortcode_download_archive_for_user_item( $user_id, $item_id, $licenses_for_item );
        if ( ! empty( $archive_downloads ) ) {
            return $archive_downloads;
        }
    }

    if ( null === $permissions_map ) {
        $permissions_map = my_gpd_get_permissions_map_for_user( $user_id );
    }

    if ( empty( $permissions_map[ $item_id ] ) || ! is_array( $permissions_map[ $item_id ] ) ) {
        return array();
    }

    $downloads = array();

    foreach ( $permissions_map[ $item_id ] as $download_uuid => $permission ) {
        if ( empty( $download_uuid ) || empty( $permission ) ) {
            continue;
        }

        $url = my_gpd_build_secure_download_url( $permission );
        if ( ! $url ) {
            continue;
        }

        $name       = '';
        $file       = '';
        $menu_order = 0;
        $row_id     = 0;

        if ( function_exists( 'gpd_uupd_get_downloadable_item_row_by_uuid' ) ) {
            $downloadable_row = gpd_uupd_get_downloadable_item_row_by_uuid( $item_id, $download_uuid );
            if ( $downloadable_row ) {
                $name       = ! empty( $downloadable_row->item_name ) ? (string) $downloadable_row->item_name : '';
                $file       = ! empty( $downloadable_row->item_file ) ? (string) $downloadable_row->item_file : '';
                $menu_order = isset( $downloadable_row->menu_order ) ? (int) $downloadable_row->menu_order : 0;
                $row_id     = isset( $downloadable_row->id ) ? (int) $downloadable_row->id : 0;
            }
        }

        // Legacy fallback for installs/items where the DB table row is absent.
        if ( '' === $file || '' === $name ) {
            $legacy_downloads = get_post_meta( $item_id, '_getpaid_item_downloads', true );
            if ( is_string( $legacy_downloads ) ) {
                $legacy_downloads = maybe_unserialize( $legacy_downloads );
            }
            if ( ! empty( $legacy_downloads ) && is_array( $legacy_downloads ) ) {
                foreach ( $legacy_downloads as $legacy_download ) {
                    if ( ! is_array( $legacy_download ) ) {
                        continue;
                    }
                    $legacy_uuid = isset( $legacy_download['download_id'] ) ? (string) $legacy_download['download_id'] : '';
                    if ( $legacy_uuid !== (string) $download_uuid ) {
                        continue;
                    }
                    if ( '' === $name && ! empty( $legacy_download['name'] ) ) {
                        $name = (string) $legacy_download['name'];
                    }
                    if ( '' === $file && ! empty( $legacy_download['file'] ) ) {
                        $file = (string) $legacy_download['file'];
                    }
                    break;
                }
            }
        }

        if ( '' === $name ) {
            $path = $file ? wp_parse_url( $file, PHP_URL_PATH ) : '';
            $base = $path ? basename( $path ) : basename( (string) $file );
            $name = $base ? preg_replace( '/\.zip$/i', '', $base ) : __( 'Download', 'getpaid' );
        }

        $downloads[] = array(
            'id'         => (string) $download_uuid,
            'name'       => $name,
            'url'        => $url,
            'file'       => $file,
            'permission' => $permission,
            'menu_order' => $menu_order,
            'row_id'     => $row_id,
        );
    }

    usort(
        $downloads,
        function( $a, $b ) {
            $a_menu = isset( $a['menu_order'] ) ? (int) $a['menu_order'] : 0;
            $b_menu = isset( $b['menu_order'] ) ? (int) $b['menu_order'] : 0;
            if ( $a_menu !== $b_menu ) {
                return $b_menu <=> $a_menu;
            }

            $a_id = isset( $a['row_id'] ) ? (int) $a['row_id'] : 0;
            $b_id = isset( $b['row_id'] ) ? (int) $b['row_id'] : 0;
            return $b_id <=> $a_id;
        }
    );

    return $downloads;
}




/**
 * Get a human-friendly variation label for an invoice/item purchase.
 *
 * @param int $invoice_id
 * @param int $item_id
 * @return string
 */
function my_gpd_get_invoice_item_variation_label( $invoice_id, $item_id ) {
    $invoice_id = absint( $invoice_id );
    $item_id    = absint( $item_id );

    if ( ! $invoice_id || ! $item_id || ! function_exists( 'gpd_uupd_get_invoice_item_meta' ) ) {
        return '';
    }

    $meta = gpd_uupd_get_invoice_item_meta( $invoice_id, $item_id );

    if ( ! empty( $meta['variation_name'] ) ) {
        return sanitize_text_field( (string) $meta['variation_name'] );
    }

    if ( ! empty( $meta['variation_data']['name'] ) ) {
        return sanitize_text_field( (string) $meta['variation_data']['name'] );
    }

    if ( ! empty( $meta['variation_id'] ) ) {
        return sanitize_text_field( (string) $meta['variation_id'] );
    }

    return '';
}

/**
 * Build a short-lived secure download URL for a specific downloadable_items row.
 * Used by account/shortcode archive links where we intentionally show more than
 * the single current updater package.
 *
 * @param int $item_id
 * @param int $downloadable_row_id
 * @param int $invoice_id
 * @return string
 */
function my_gpd_build_secure_archive_download_url( $item_id, $downloadable_row_id, $invoice_id = 0 ) {
    $item_id             = absint( $item_id );
    $downloadable_row_id = absint( $downloadable_row_id );
    $invoice_id          = absint( $invoice_id );

    if ( ! $item_id || ! $downloadable_row_id ) {
        return '';
    }

    $expires = time() + DAY_IN_SECONDS;
    $payload = implode( '|', array( $item_id, $downloadable_row_id, $invoice_id, $expires ) );
    $hmac    = hash_hmac( 'sha256', $payload, wp_salt( 'gpd_archive_download' ) );
    $token   = base64_encode( $payload . '|' . $hmac );

    return add_query_arg(
        array(
            'gpd_file_download' => rawurlencode( $token ),
        ),
        home_url( '/' )
    );
}

/**
 * Return downloadable archive rows to show in account shortcodes.
 *
 * Updater endpoint remains single-file. This display helper can show older
 * downloadable rows for non-variation items. For variation items, it shows the
 * customer's purchased variation label and rows currently attached to that
 * variation, plus any purchase-time permission rows for that same invoice.
 *
 * @param int   $user_id
 * @param int   $item_id
 * @param array $licenses_for_item
 * @return array[]
 */
function my_gpd_get_shortcode_download_archive_for_user_item( $user_id, $item_id, $licenses_for_item = array() ) {
    global $wpdb;

    $user_id = absint( $user_id );
    $item_id = absint( $item_id );

    if ( ! $user_id || ! $item_id || ! function_exists( 'gpd_uupd_live_tables' ) || ! function_exists( 'my_gpd_user_has_valid_license_for_item' ) ) {
        return array();
    }

    if ( ! my_gpd_user_has_valid_license_for_item( $user_id, $item_id ) ) {
        return array();
    }

    $tables         = gpd_uupd_live_tables();
    $has_variations = function_exists( 'gpd_uupd_item_has_variations' ) && gpd_uupd_item_has_variations( $item_id );
    $downloads      = array();

    $normalise_row = static function( $row, $invoice_id = 0, $variation_label = '' ) use ( $item_id ) {
        if ( ! $row || empty( $row->item_file ) || empty( $row->id ) ) {
            return null;
        }

        $name = ! empty( $row->item_name ) ? (string) $row->item_name : '';
        if ( '' === $name ) {
            $path = wp_parse_url( (string) $row->item_file, PHP_URL_PATH );
            $base = $path ? basename( $path ) : basename( (string) $row->item_file );
            $name = $base ? preg_replace( '/\.zip$/i', '', $base ) : __( 'Download', 'getpaid' );
        }

        return array(
            'id'              => (string) $row->download_id,
            'row_id'          => (int) $row->id,
            'name'            => $name,
            'file'            => (string) $row->item_file,
            'url'             => my_gpd_build_secure_archive_download_url( $item_id, (int) $row->id, $invoice_id ),
            'invoice_id'      => absint( $invoice_id ),
            'variation_label' => $variation_label,
            'menu_order'      => isset( $row->menu_order ) ? (int) $row->menu_order : 0,
            'date_updated'    => isset( $row->date_updated ) ? (string) $row->date_updated : '',
            'date_created'    => isset( $row->date_created ) ? (string) $row->date_created : '',
        );
    };

    if ( ! $has_variations ) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tables['downloadable_items']}
                 WHERE item_id = %d
                   AND item_file <> ''
                 ORDER BY menu_order DESC, id DESC",
                $item_id
            )
        );

        foreach ( (array) $rows as $row ) {
            $download = $normalise_row( $row, 0, '' );
            if ( $download ) {
                $downloads[ $download['row_id'] ] = $download;
            }
        }

        return array_values( $downloads );
    }

    // Variation products: work per licence/invoice so we can display the selected variation name.
    foreach ( (array) $licenses_for_item as $license ) {
        $invoice_id = ! empty( $license->invoice_id ) ? absint( $license->invoice_id ) : 0;
        if ( ! $invoice_id ) {
            continue;
        }

        $variation_label = my_gpd_get_invoice_item_variation_label( $invoice_id, $item_id );

        // Current rows attached to the purchased variation.
        if ( function_exists( 'gpd_uupd_resolve_live_download_rows' ) ) {
            foreach ( (array) gpd_uupd_resolve_live_download_rows( $item_id, $invoice_id ) as $row ) {
                $download = $normalise_row( $row, $invoice_id, $variation_label );
                if ( $download ) {
                    $downloads[ $invoice_id . '|' . $download['row_id'] ] = $download;
                }
            }
        }

        // Also include purchase-time permission rows for that invoice, so older/stored files remain visible.
        if ( function_exists( 'gpd_uupd_get_permission_download_rows' ) ) {
            foreach ( (array) gpd_uupd_get_permission_download_rows( $item_id, $invoice_id, $user_id ) as $row ) {
                $download = $normalise_row( $row, $invoice_id, $variation_label );
                if ( $download ) {
                    $downloads[ $invoice_id . '|' . $download['row_id'] ] = $download;
                }
            }
        }
    }

    $downloads = array_values( $downloads );

    usort(
        $downloads,
        function( $a, $b ) {
            $a_menu = isset( $a['menu_order'] ) ? (int) $a['menu_order'] : 0;
            $b_menu = isset( $b['menu_order'] ) ? (int) $b['menu_order'] : 0;
            if ( $a_menu !== $b_menu ) {
                return $b_menu <=> $a_menu;
            }
            return ( (int) $b['row_id'] ) <=> ( (int) $a['row_id'] );
        }
    );

    return $downloads;
}

/**
 * Handle secure archive download links: ?gpd_file_download=<token>.
 */
function my_gpd_handle_secure_archive_download() {
    if ( empty( $_GET['gpd_file_download'] ) ) {
        return;
    }

    if ( ! is_user_logged_in() ) {
        wp_die( 'You must be logged in to download this file.', 'Unauthorized', array( 'response' => 401 ) );
    }

    $raw_token = sanitize_text_field( wp_unslash( $_GET['gpd_file_download'] ) );
    $decoded   = base64_decode( rawurldecode( $raw_token ), true );
    if ( ! $decoded ) {
        wp_die( 'Invalid download token.', 'Forbidden', array( 'response' => 403 ) );
    }

    $parts = explode( '|', $decoded );
    if ( 5 !== count( $parts ) ) {
        wp_die( 'Invalid download token.', 'Forbidden', array( 'response' => 403 ) );
    }

    list( $item_id, $downloadable_row_id, $invoice_id, $expires, $sent_hmac ) = $parts;
    $item_id             = absint( $item_id );
    $downloadable_row_id = absint( $downloadable_row_id );
    $invoice_id          = absint( $invoice_id );
    $expires             = absint( $expires );

    if ( ! $item_id || ! $downloadable_row_id || ! $expires || time() > $expires ) {
        wp_die( 'This download link has expired.', 'Forbidden', array( 'response' => 403 ) );
    }

    $payload = implode( '|', array( $item_id, $downloadable_row_id, $invoice_id, $expires ) );
    $calc    = hash_hmac( 'sha256', $payload, wp_salt( 'gpd_archive_download' ) );
    if ( ! hash_equals( $calc, (string) $sent_hmac ) ) {
        wp_die( 'Invalid download token.', 'Forbidden', array( 'response' => 403 ) );
    }

    $user_id = get_current_user_id();
    if ( ! my_gpd_user_has_valid_license_for_item( $user_id, $item_id ) ) {
        wp_die( 'Your license for this download has expired or is inactive.', 'Forbidden', array( 'response' => 403 ) );
    }

    global $wpdb;
    $tables = gpd_uupd_live_tables();
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$tables['downloadable_items']} WHERE id = %d AND item_id = %d LIMIT 1",
            $downloadable_row_id,
            $item_id
        )
    );

    if ( ! $row || empty( $row->item_file ) ) {
        wp_die( 'Download file not found.', 'Error', array( 'response' => 404 ) );
    }

    // Extra guard for variation products: invoice must belong to this user/item, and the file must be either
    // currently assigned to their purchased variation or present in their purchase-time permission rows.
    if ( function_exists( 'gpd_uupd_item_has_variations' ) && gpd_uupd_item_has_variations( $item_id ) ) {
        if ( ! $invoice_id ) {
            wp_die( 'Invalid variation download.', 'Forbidden', array( 'response' => 403 ) );
        }

        global $wpdb;
        $gpd_tables = function_exists( 'my_gpd_tables' ) ? my_gpd_tables() : array();
        if ( empty( $gpd_tables['licenses'] ) ) {
            wp_die( 'Licenses table not available.', 'Error', array( 'response' => 500 ) );
        }
        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$gpd_tables['licenses']} WHERE user_id = %d AND item_id = %d AND invoice_id = %d LIMIT 1",
                $user_id,
                $item_id,
                $invoice_id
            )
        );

        if ( ! $license ) {
            wp_die( 'Invalid variation download.', 'Forbidden', array( 'response' => 403 ) );
        }

        $allowed = false;
        foreach ( (array) gpd_uupd_resolve_live_download_rows( $item_id, $invoice_id ) as $allowed_row ) {
            if ( isset( $allowed_row->id ) && (int) $allowed_row->id === $downloadable_row_id ) {
                $allowed = true;
                break;
            }
        }

        if ( ! $allowed ) {
            foreach ( (array) gpd_uupd_get_permission_download_rows( $item_id, $invoice_id, $user_id ) as $allowed_row ) {
                if ( isset( $allowed_row->id ) && (int) $allowed_row->id === $downloadable_row_id ) {
                    $allowed = true;
                    break;
                }
            }
        }

        if ( ! $allowed ) {
            wp_die( 'You do not have permission to download this variation file.', 'Forbidden', array( 'response' => 403 ) );
        }
    }

    if ( class_exists( 'GetPaid_Secure_Zip_Uploads' ) ) {
        GetPaid_Secure_Zip_Uploads::stream_zip_download( $item_id, $row->item_file );
    } else {
        wp_safe_redirect( $row->item_file );
        exit;
    }
}
add_action( 'template_redirect', 'my_gpd_handle_secure_archive_download' );

/**
 * Build secure download URL for a given permission.
 *
 * We use our own query var (?gpd_download=<permission_id>)
 * and handle it in template_redirect by streaming the file via
 * GetPaid_Secure_Zip_Uploads::stream_zip_download().
 *
 * @param object $permission_row
 * @return string
 */
function my_gpd_build_secure_download_url( $permission_row ) {

    $permission_id = (int) $permission_row->permission_id;
    if ( ! $permission_id ) {
        return '';
    }

    $url = add_query_arg(
        array(
            'gpd_download' => $permission_id,
        ),
        home_url( '/' )
    );

    /**
     * Filter: my_gpd_secure_download_url
     *
     * @param string $url
     * @param object $permission_row
     */
    return apply_filters( 'my_gpd_secure_download_url', $url, $permission_row );
}

/* -------------------------------------------------------------------------
 * Handlers: secure downloads & activation removal
 * ---------------------------------------------------------------------- */

/**
 * Handle secure download link:
 *   ?gpd_download=<permission_id>
 *
 * Looks up permission, finds matching file from _getpaid_item_downloads
 * and streams via GetPaid_Secure_Zip_Uploads::stream_zip_download().
 */
function my_gpd_handle_secure_download() {
    if ( empty( $_GET['gpd_download'] ) ) {
        return;
    }

    if ( ! is_user_logged_in() ) {
        wp_die( 'You must be logged in to download this file.', 'Unauthorized', array( 'response' => 401 ) );
    }

    global $wpdb;
    $tables        = my_gpd_tables();
    $permission_id = (int) $_GET['gpd_download'];
    $user_id       = get_current_user_id();

    $permission = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$tables['permissions']} WHERE permission_id = %d",
            $permission_id
        )
    );

    if ( ! $permission ) {
        wp_die( 'Download permission not found.', 'Error', array( 'response' => 404 ) );
    }

    // Make sure this permission belongs to the current user.
    if ( (int) $permission->user_id !== $user_id ) {
        wp_die( 'You do not have permission to download this file.', 'Forbidden', array( 'response' => 403 ) );
    }

    $item_id     = (int) $permission->item_id;
    $download_id = $permission->download_id;

    if ( ! $item_id || ! $download_id ) {
        wp_die( 'Invalid download record.', 'Error', array( 'response' => 500 ) );
    }

    // NEW: Require at least one active, non-expired license for this item.
    if ( ! my_gpd_user_has_valid_license_for_item( $user_id, $item_id ) ) {
        wp_die(
            'Your license for this download has expired or is inactive.',
            'Forbidden',
            array( 'response' => 403 )
        );
    }

    $file_url = '';

    $sync_latest = get_post_meta( $item_id, '_wpi_sync_latest_download', true );

    // If sync-latest is enabled, resolve the invoice's purchased variation
    // against the CURRENT variation/download table. If disabled, preserve the
    // stored purchase-time permission UUID below.
    if ( $sync_latest && function_exists( 'gpd_uupd_resolve_live_download_rows' ) ) {
        $invoice_id = ! empty( $permission->invoice_id ) ? absint( $permission->invoice_id ) : 0;
        $live_rows  = gpd_uupd_resolve_live_download_rows( $item_id, $invoice_id );
        if ( ! empty( $live_rows ) ) {
            $first = reset( $live_rows );
            if ( is_object( $first ) && ! empty( $first->item_file ) ) {
                $file_url = $first->item_file;
            }
        }
    }

    // Purchase-time path: permissions store the UUID from
    // {prefix}getpaid_downloadable_items.download_id, and the file URL lives
    // in that table's item_file column.
    if ( ! $file_url && function_exists( 'gpd_uupd_get_downloadable_item_row_by_uuid' ) ) {
        $downloadable_row = gpd_uupd_get_downloadable_item_row_by_uuid( $item_id, $download_id );
        if ( $downloadable_row && ! empty( $downloadable_row->item_file ) ) {
            $file_url = $downloadable_row->item_file;
        }
    }

    // Fallback: preserve original _getpaid_item_downloads lookup.
    if ( ! $file_url ) {
        $downloads = get_post_meta( $item_id, '_getpaid_item_downloads', true );
        if ( is_string( $downloads ) ) {
            $downloads = maybe_unserialize( $downloads );
        }

        if ( ! empty( $downloads ) && is_array( $downloads ) ) {
            foreach ( $downloads as $download ) {
                if ( isset( $download['download_id'] ) && $download['download_id'] === $download_id ) {
                    $file_url = isset( $download['file'] ) ? $download['file'] : '';
                    break;
                }
            }
        }
    }

    if ( ! $file_url ) {
        wp_die( 'Download file not found.', 'Error', array( 'response' => 404 ) );
    }

    if ( class_exists( 'GetPaid_Secure_Zip_Uploads' ) ) {
        GetPaid_Secure_Zip_Uploads::stream_zip_download( $item_id, $file_url );
    } else {
        // Fallback: direct URL (not ideal, but avoids a hard error).
        wp_safe_redirect( $file_url );
        exit;
    }
}

add_action( 'template_redirect', 'my_gpd_handle_secure_download' );

/**
 * Handle activation removal:
 *   ?gpd_remove_activation=<domain>&license=<id>&_wpnonce=<nonce>
 */
function my_gpd_handle_activation_removal() {
    if ( empty( $_GET['gpd_remove_activation'] ) || empty( $_GET['license'] ) ) {
        return;
    }

    if ( ! is_user_logged_in() ) {
        return;
    }

    $domain     = sanitize_text_field( wp_unslash( $_GET['gpd_remove_activation'] ) );
    $license_id = (int) $_GET['license'];

    if ( ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? $_GET['_wpnonce'] : '', 'gpd_remove_activation_' . $license_id . '_' . $domain ) ) {
        return;
    }

    $license = my_gpd_get_license_for_current_user( array( 'id' => $license_id ) );
    if ( ! $license ) {
        return;
    }

    $activations = my_gpd_get_license_activations( $license );

    if ( isset( $activations[ $domain ] ) ) {
        unset( $activations[ $domain ] );

        global $wpdb;
        $tables = my_gpd_tables();

        $new_activated_on = wp_json_encode( $activations );
        $new_count        = max( 0, (int) $license->activations - 1 );

        $wpdb->update(
            $tables['licenses'],
            array(
                'activated_on' => $new_activated_on,
                'activations'  => $new_count,
            ),
            array( 'id' => $license->id ),
            array( '%s', '%d' ),
            array( '%d' )
        );
    }

    // Redirect back to license details page without query vars.
    $redirect = remove_query_arg(
        array( 'gpd_remove_activation', '_wpnonce' )
    );

    wp_safe_redirect( $redirect );
    exit;
}
add_action( 'template_redirect', 'my_gpd_handle_activation_removal', 20 );

if ( ! function_exists( 'my_gpd_normalize_url_base' ) ) {
    /**
     * Normalise a user-supplied base URL:
     *  - trims spaces + straight/smart quotes
     *  - if it's relative (/foo or foo), prefixes home_url()
     *  - leaves full http/https URLs alone
     */
    function my_gpd_normalize_url_base( $url ) {
        $url = (string) $url;

        // Trim whitespace + both straight and smart quotes.
        $url = trim( $url, " \t\n\r\0\x0B\"'“”‘’" );

        if ( $url === '' ) {
            return '';
        }

        // Already absolute?
        if ( preg_match( '#^https?://#i', $url ) ) {
            return $url;
        }

        // Treat as path.
        return home_url( '/' . ltrim( $url, '/' ) );
    }
}

if ( ! function_exists( 'my_gpd_simple_add_query_arg' ) ) {
    /**
     * Super simple query arg appender that doesn't get confused by odd bases.
     */
    function my_gpd_simple_add_query_arg( $base, $key, $value ) {
        if ( ! $base ) {
            return '';
        }
        $sep = ( strpos( $base, '?' ) === false ) ? '?' : '&';
        return $base . $sep . rawurlencode( $key ) . '=' . rawurlencode( $value );
    }
}

