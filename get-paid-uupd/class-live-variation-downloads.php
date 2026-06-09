<?php
/**
 * Live variation download resolution for GetPaid/UUPD.
 *
 * Important data model:
 * - _wpinv_item_variations[*]['download_ids'] stores IDs from the DB table
 *   {prefix}getpaid_downloadable_items.id.
 * - getpaid_downloadable_item_permissions.download_id stores the UUID from
 *   {prefix}getpaid_downloadable_items.download_id.
 * - The actual file URL lives in {prefix}getpaid_downloadable_items.item_file.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'gpd_uupd_live_tables' ) ) {
    function gpd_uupd_live_tables() {
        global $wpdb;

        return array(
            'downloadable_items' => $wpdb->prefix . 'getpaid_downloadable_items',
            'permissions'        => $wpdb->prefix . 'getpaid_downloadable_item_permissions',
            'invoice_items'      => $wpdb->prefix . 'getpaid_invoice_items',
        );
    }
}

if ( ! function_exists( 'gpd_uupd_maybe_unserialize_meta' ) ) {
    function gpd_uupd_maybe_unserialize_meta( $value ) {
        if ( is_string( $value ) ) {
            $value = maybe_unserialize( $value );
        }
        return is_array( $value ) ? $value : array();
    }
}

if ( ! function_exists( 'gpd_uupd_get_invoice_item_meta' ) ) {
    function gpd_uupd_get_invoice_item_meta( $invoice_id, $item_id ) {
        global $wpdb;
        $tables = gpd_uupd_live_tables();

        $invoice_id = absint( $invoice_id );
        $item_id    = absint( $item_id );

        if ( ! $invoice_id || ! $item_id ) {
            return array();
        }

        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta FROM {$tables['invoice_items']} WHERE post_id = %d AND item_id = %d LIMIT 1",
                $invoice_id,
                $item_id
            )
        );

        return gpd_uupd_maybe_unserialize_meta( $raw );
    }
}

if ( ! function_exists( 'gpd_uupd_get_invoice_variation_id' ) ) {
    function gpd_uupd_get_invoice_variation_id( $invoice_id, $item_id ) {
        $meta = gpd_uupd_get_invoice_item_meta( $invoice_id, $item_id );

        if ( ! empty( $meta['variation_id'] ) ) {
            return sanitize_key( (string) $meta['variation_id'] );
        }

        if ( ! empty( $meta['variation_data']['id'] ) ) {
            return sanitize_key( (string) $meta['variation_data']['id'] );
        }

        return '';
    }
}

if ( ! function_exists( 'gpd_uupd_get_live_variation_data' ) ) {
    /**
     * Return the current saved variation definition for an item/variation ID.
     */
    function gpd_uupd_get_live_variation_data( $item_id, $variation_id ) {
        $variation_id = sanitize_key( (string) $variation_id );
        if ( '' === $variation_id ) {
            return array();
        }

        $variations = get_post_meta( absint( $item_id ), '_wpinv_item_variations', true );
        $variations = gpd_uupd_maybe_unserialize_meta( $variations );

        foreach ( $variations as $variation ) {
            if ( ! is_array( $variation ) ) {
                continue;
            }

            $id = isset( $variation['id'] ) ? sanitize_key( (string) $variation['id'] ) : '';
            if ( $id === $variation_id ) {
                return $variation;
            }
        }

        return array();
    }
}

if ( ! function_exists( 'gpd_uupd_normalise_downloadable_ids' ) ) {
    function gpd_uupd_normalise_downloadable_ids( $ids ) {
        $ids = array_map( 'absint', (array) $ids );
        return array_values( array_filter( array_unique( $ids ) ) );
    }
}

if ( ! function_exists( 'gpd_uupd_get_live_variation_downloadable_ids' ) ) {
    /**
     * All downloadable item row IDs attached to the selected variation.
     * These IDs map to {prefix}getpaid_downloadable_items.id.
     */
    function gpd_uupd_get_live_variation_downloadable_ids( $item_id, $variation_id ) {
        $variation = gpd_uupd_get_live_variation_data( $item_id, $variation_id );
        $ids       = isset( $variation['download_ids'] ) && is_array( $variation['download_ids'] )
            ? $variation['download_ids']
            : array();

        return gpd_uupd_normalise_downloadable_ids( $ids );
    }
}

if ( ! function_exists( 'gpd_uupd_get_live_variation_update_downloadable_ids' ) ) {
    /**
     * The single downloadable item row ID that should be served to the updater.
     *
     * A variation can contain multiple customer/archive downloads, but the updater
     * needs one ZIP. Prefer variation['update_download_id']; if it is missing or
     * no longer selected, fall back to the first selected download_ids[] value.
     */
    function gpd_uupd_get_live_variation_update_downloadable_ids( $item_id, $variation_id ) {
        $variation    = gpd_uupd_get_live_variation_data( $item_id, $variation_id );
        $download_ids = isset( $variation['download_ids'] ) && is_array( $variation['download_ids'] )
            ? gpd_uupd_normalise_downloadable_ids( $variation['download_ids'] )
            : array();

        if ( empty( $download_ids ) ) {
            return array();
        }

        $update_id = 0;

        // Preferred storage: stable map keyed by variation ID. This avoids GetPaid
        // stripping unknown fields from _wpinv_item_variations during its own save.
        $variation_key = isset( $variation['id'] ) ? sanitize_key( (string) $variation['id'] ) : sanitize_key( (string) $variation_id );
        $update_map = get_post_meta( absint( $item_id ), '_wpi_uupd_variation_update_download_ids', true );
        $update_map = gpd_uupd_maybe_unserialize_meta( $update_map );
        if ( $variation_key && ! empty( $update_map[ $variation_key ] ) ) {
            $update_id = absint( $update_map[ $variation_key ] );
        }

        // Back-compat for early builds that embedded this field directly.
        if ( ! $update_id && ! empty( $variation['update_download_id'] ) ) {
            $update_id = absint( $variation['update_download_id'] );
        }

        if ( $update_id && in_array( $update_id, $download_ids, true ) ) {
            return array( $update_id );
        }

        return array( (int) reset( $download_ids ) );
    }
}

if ( ! function_exists( 'gpd_uupd_get_downloadable_item_rows_by_ids' ) ) {
    function gpd_uupd_get_downloadable_item_rows_by_ids( $item_id, $downloadable_item_ids ) {
        global $wpdb;
        $tables = gpd_uupd_live_tables();

        $item_id = absint( $item_id );
        $ids     = array_map( 'absint', (array) $downloadable_item_ids );
        $ids     = array_values( array_filter( array_unique( $ids ) ) );

        if ( ! $item_id || empty( $ids ) ) {
            return array();
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = $wpdb->prepare(
            "SELECT * FROM {$tables['downloadable_items']} WHERE item_id = %d AND id IN ({$placeholders})",
            array_merge( array( $item_id ), $ids )
        );

        $rows = $wpdb->get_results( $sql );
        if ( empty( $rows ) ) {
            return array();
        }

        $by_id = array();
        foreach ( $rows as $row ) {
            $by_id[ (int) $row->id ] = $row;
        }

        $ordered = array();
        foreach ( $ids as $id ) {
            if ( isset( $by_id[ $id ] ) ) {
                $ordered[] = $by_id[ $id ];
            }
        }

        return $ordered;
    }
}

if ( ! function_exists( 'gpd_uupd_get_downloadable_item_row_by_uuid' ) ) {
    function gpd_uupd_get_downloadable_item_row_by_uuid( $item_id, $download_uuid ) {
        global $wpdb;
        $tables = gpd_uupd_live_tables();

        $item_id       = absint( $item_id );
        $download_uuid = sanitize_text_field( (string) $download_uuid );

        if ( ! $item_id || '' === $download_uuid ) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tables['downloadable_items']} WHERE item_id = %d AND download_id = %s LIMIT 1",
                $item_id,
                $download_uuid
            )
        );

        return $row ?: null;
    }
}


if ( ! function_exists( 'gpd_uupd_item_has_variations' ) ) {
    function gpd_uupd_item_has_variations( $item_id ) {
        $item_id = absint( $item_id );
        if ( ! $item_id ) {
            return false;
        }

        $has_variations = get_post_meta( $item_id, '_wpinv_has_variations', true );
        if ( empty( $has_variations ) || '0' === (string) $has_variations ) {
            return false;
        }

        $variations = get_post_meta( $item_id, '_wpinv_item_variations', true );
        $variations = gpd_uupd_maybe_unserialize_meta( $variations );

        return ! empty( $variations );
    }
}

if ( ! function_exists( 'gpd_uupd_sort_downloadable_rows_latest_first' ) ) {
    function gpd_uupd_sort_downloadable_rows_latest_first( $rows ) {
        $rows = array_values( array_filter( (array) $rows ) );

        usort(
            $rows,
            function( $a, $b ) {
                $a_menu = isset( $a->menu_order ) ? (int) $a->menu_order : 0;
                $b_menu = isset( $b->menu_order ) ? (int) $b->menu_order : 0;

                if ( $a_menu !== $b_menu ) {
                    return $b_menu <=> $a_menu;
                }

                $a_id = isset( $a->id ) ? (int) $a->id : 0;
                $b_id = isset( $b->id ) ? (int) $b->id : 0;

                return $b_id <=> $a_id;
            }
        );

        return $rows;
    }
}

if ( ! function_exists( 'gpd_uupd_normalise_download_meta_rows' ) ) {
    function gpd_uupd_normalise_download_meta_rows( $item_id ) {
        $raw = get_post_meta( absint( $item_id ), '_getpaid_item_downloads', true );
        $raw = gpd_uupd_maybe_unserialize_meta( $raw );

        if ( empty( $raw ) ) {
            return array();
        }

        if ( isset( $raw['file'] ) || isset( $raw['download_id'] ) || isset( $raw['file_url'] ) ) {
            $raw = array( $raw );
        }

        $rows = array();
        foreach ( $raw as $key => $download ) {
            if ( ! is_array( $download ) ) {
                continue;
            }

            $downloadable_id = 0;
            foreach ( array( 'file_id', 'id', 'downloadable_id' ) as $id_key ) {
                if ( ! empty( $download[ $id_key ] ) ) {
                    $downloadable_id = absint( $download[ $id_key ] );
                    break;
                }
            }
            if ( ! $downloadable_id && is_numeric( $key ) ) {
                $downloadable_id = absint( $key );
            }

            $rows[] = array(
                'downloadable_id' => $downloadable_id,
                'download_uuid'   => isset( $download['download_id'] ) ? (string) $download['download_id'] : '',
                'name'            => isset( $download['name'] ) ? (string) $download['name'] : ( isset( $download['file_name'] ) ? (string) $download['file_name'] : '' ),
                'file'            => isset( $download['file'] ) ? (string) $download['file'] : ( isset( $download['file_url'] ) ? (string) $download['file_url'] : '' ),
            );
        }

        return $rows;
    }
}

if ( ! function_exists( 'gpd_uupd_get_downloadable_item_row_from_meta_reference' ) ) {
    function gpd_uupd_get_downloadable_item_row_from_meta_reference( $item_id, $meta_row ) {
        global $wpdb;
        $tables = gpd_uupd_live_tables();

        $item_id = absint( $item_id );
        if ( ! $item_id || ! is_array( $meta_row ) ) {
            return null;
        }

        if ( ! empty( $meta_row['downloadable_id'] ) ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$tables['downloadable_items']} WHERE item_id = %d AND id = %d LIMIT 1",
                    $item_id,
                    absint( $meta_row['downloadable_id'] )
                )
            );
            if ( $row ) {
                return $row;
            }
        }

        if ( ! empty( $meta_row['download_uuid'] ) ) {
            $row = gpd_uupd_get_downloadable_item_row_by_uuid( $item_id, $meta_row['download_uuid'] );
            if ( $row ) {
                return $row;
            }
        }

        if ( ! empty( $meta_row['file'] ) ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$tables['downloadable_items']} WHERE item_id = %d AND item_file = %s LIMIT 1",
                    $item_id,
                    $meta_row['file']
                )
            );
            if ( $row ) {
                return $row;
            }
        }

        if ( ! empty( $meta_row['file'] ) || ! empty( $meta_row['name'] ) || ! empty( $meta_row['download_uuid'] ) ) {
            return (object) array(
                'id'          => ! empty( $meta_row['downloadable_id'] ) ? absint( $meta_row['downloadable_id'] ) : 0,
                'item_id'     => $item_id,
                'download_id' => ! empty( $meta_row['download_uuid'] ) ? (string) $meta_row['download_uuid'] : '',
                'item_name'   => ! empty( $meta_row['name'] ) ? (string) $meta_row['name'] : basename( (string) $meta_row['file'] ),
                'item_file'   => ! empty( $meta_row['file'] ) ? (string) $meta_row['file'] : '',
            );
        }

        return null;
    }
}

if ( ! function_exists( 'gpd_uupd_get_current_non_variation_download_rows' ) ) {
    function gpd_uupd_get_current_non_variation_download_rows( $item_id, $limit = 1 ) {
        global $wpdb;
        $tables = gpd_uupd_live_tables();

        $item_id = absint( $item_id );
        $limit   = max( 1, absint( $limit ) );

        if ( ! $item_id ) {
            return array();
        }

        // For non-variation products, GetPaid's Downloadable Files metabox order is
        // the source of truth. The first row in _getpaid_item_downloads is the file
        // shown as the current/top update file in the admin UI.
        $ordered_meta_rows = gpd_uupd_normalise_download_meta_rows( $item_id );
        $rows              = array();

        foreach ( $ordered_meta_rows as $meta_row ) {
            $row = gpd_uupd_get_downloadable_item_row_from_meta_reference( $item_id, $meta_row );
            if ( $row && ! empty( $row->item_file ) ) {
                $rows[] = $row;
            }

            if ( count( $rows ) >= $limit ) {
                return $rows;
            }
        }

        // Fallback if the post meta is empty or temporarily out of sync with the
        // downloadable-items table.
        $db_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tables['downloadable_items']}
                 WHERE item_id = %d
                   AND item_file <> ''
                 ORDER BY menu_order ASC, id ASC
                 LIMIT %d",
                $item_id,
                $limit
            )
        );

        if ( ! empty( $db_rows ) ) {
            return $db_rows;
        }

        return array_slice( gpd_uupd_get_base_download_rows_from_meta( $item_id ), 0, $limit );
    }
}


if ( ! function_exists( 'gpd_uupd_get_permission_download_rows' ) ) {
    function gpd_uupd_get_permission_download_rows( $item_id, $invoice_id, $user_id = 0 ) {
        global $wpdb;
        $tables = gpd_uupd_live_tables();

        $item_id    = absint( $item_id );
        $invoice_id = absint( $invoice_id );
        $user_id    = absint( $user_id );

        if ( ! $item_id || ! $invoice_id ) {
            return array();
        }

        $where  = "item_id = %d AND invoice_id = %d";
        $params = array( $item_id, $invoice_id );

        if ( $user_id ) {
            $where   .= " AND user_id = %d";
            $params[] = $user_id;
        }

        $permissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tables['permissions']} WHERE {$where}",
                $params
            )
        );

        if ( empty( $permissions ) ) {
            return array();
        }

        $rows = array();
        foreach ( $permissions as $permission ) {
            if ( empty( $permission->download_id ) ) {
                continue;
            }

            $downloadable_row = gpd_uupd_get_downloadable_item_row_by_uuid( $item_id, $permission->download_id );
            if ( $downloadable_row && ! empty( $downloadable_row->item_file ) ) {
                $rows[] = $downloadable_row;
            }
        }

        return gpd_uupd_sort_downloadable_rows_latest_first( $rows );
    }
}

if ( ! function_exists( 'gpd_uupd_resolve_download_rows_for_purchase' ) ) {
    function gpd_uupd_resolve_download_rows_for_purchase( $item_id, $invoice_id = 0, $user_id = 0 ) {
        $item_id    = absint( $item_id );
        $invoice_id = absint( $invoice_id );
        $user_id    = absint( $user_id );

        if ( ! $item_id ) {
            return array();
        }

        $sync_latest    = get_post_meta( $item_id, '_wpi_sync_latest_download', true );
        $has_variations = gpd_uupd_item_has_variations( $item_id );

        // Non-variation updater behaviour is explicit: serve the current top/latest
        // downloadable item. If sync-latest is enabled, permission sync also writes
        // customers to this UUID on save. If disabled, invoice permissions remain
        // untouched, but the updater still has one reliable current package.
        if ( ! $has_variations ) {
            return gpd_uupd_get_current_non_variation_download_rows( $item_id, 1 );
        }

        // Variation items with sync-latest enabled resolve the purchased variation
        // against the CURRENT item variation/download table, but the updater
        // receives only the variation's selected update file.
        if ( $sync_latest ) {
            return gpd_uupd_resolve_live_update_download_rows( $item_id, $invoice_id );
        }

        // Variation items with sync-latest disabled preserve the purchase-time
        // permission UUID, so customers keep the exact file they bought.
        if ( $invoice_id ) {
            $permission_rows = gpd_uupd_get_permission_download_rows( $item_id, $invoice_id, $user_id );
            if ( ! empty( $permission_rows ) ) {
                return $permission_rows;
            }
        }

        // Final fallback for installs/items without permission rows.
        return gpd_uupd_get_base_download_rows_from_meta( $item_id );
    }
}

if ( ! function_exists( 'gpd_uupd_get_base_download_rows_from_meta' ) ) {
    function gpd_uupd_get_base_download_rows_from_meta( $item_id ) {
        $raw = get_post_meta( absint( $item_id ), '_getpaid_item_downloads', true );
        $raw = gpd_uupd_maybe_unserialize_meta( $raw );

        if ( empty( $raw ) ) {
            return array();
        }

        if ( isset( $raw['file'] ) || isset( $raw['download_id'] ) ) {
            $raw = array( $raw );
        }

        $rows = array();
        foreach ( $raw as $download ) {
            if ( ! is_array( $download ) ) {
                continue;
            }
            $rows[] = (object) array(
                'id'          => 0,
                'item_id'     => absint( $item_id ),
                'download_id' => isset( $download['download_id'] ) ? (string) $download['download_id'] : '',
                'item_name'   => isset( $download['name'] ) ? (string) $download['name'] : '',
                'item_file'   => isset( $download['file'] ) ? (string) $download['file'] : '',
            );
        }

        return $rows;
    }
}

if ( ! function_exists( 'gpd_uupd_resolve_live_download_rows' ) ) {
    function gpd_uupd_resolve_live_download_rows( $item_id, $invoice_id = 0 ) {
        $item_id    = absint( $item_id );
        $invoice_id = absint( $invoice_id );

        if ( ! $item_id ) {
            return array();
        }

        if ( $invoice_id ) {
            $variation_id = gpd_uupd_get_invoice_variation_id( $invoice_id, $item_id );
            if ( '' !== $variation_id ) {
                $downloadable_ids = gpd_uupd_get_live_variation_downloadable_ids( $item_id, $variation_id );
                $rows             = gpd_uupd_get_downloadable_item_rows_by_ids( $item_id, $downloadable_ids );

                if ( ! empty( $rows ) ) {
                    return $rows;
                }
            }
        }

        // Non-variation fallback: use the current top/latest downloadable item.
        return gpd_uupd_get_current_non_variation_download_rows( $item_id, 1 );
    }
}


if ( ! function_exists( 'gpd_uupd_resolve_live_update_download_rows' ) ) {
    /**
     * Resolve the single row that should be served to an updater request.
     *
     * This intentionally differs from gpd_uupd_resolve_live_download_rows(),
     * which may return every file attached to a variation for customer archive
     * downloads and permissions.
     */
    function gpd_uupd_resolve_live_update_download_rows( $item_id, $invoice_id = 0 ) {
        $item_id    = absint( $item_id );
        $invoice_id = absint( $invoice_id );

        if ( ! $item_id ) {
            return array();
        }

        if ( $invoice_id && gpd_uupd_item_has_variations( $item_id ) ) {
            $variation_id = gpd_uupd_get_invoice_variation_id( $invoice_id, $item_id );
            if ( '' !== $variation_id ) {
                $downloadable_ids = gpd_uupd_get_live_variation_update_downloadable_ids( $item_id, $variation_id );
                $rows             = gpd_uupd_get_downloadable_item_rows_by_ids( $item_id, $downloadable_ids );

                if ( ! empty( $rows ) ) {
                    return $rows;
                }
            }
        }

        return gpd_uupd_get_current_non_variation_download_rows( $item_id, 1 );
    }
}

if ( ! function_exists( 'gpd_uupd_sync_item_permissions_to_live_downloads' ) ) {
    function gpd_uupd_sync_item_permissions_to_live_downloads( $item_id ) {
        global $wpdb;
        $tables = gpd_uupd_live_tables();

        $item_id = absint( $item_id );
        if ( ! $item_id ) {
            return;
        }

        $sync_latest = get_post_meta( $item_id, '_wpi_sync_latest_download', true );
        if ( ! $sync_latest ) {
            return;
        }

        $permissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tables['permissions']} WHERE item_id = %d",
                $item_id
            )
        );

        if ( empty( $permissions ) ) {
            return;
        }

        $groups = array();
        foreach ( $permissions as $row ) {
            $invoice_id = isset( $row->invoice_id ) ? absint( $row->invoice_id ) : 0;
            $user_id    = isset( $row->user_id ) ? absint( $row->user_id ) : 0;
            $key        = $invoice_id . '|' . $user_id;

            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array();
            }
            $groups[ $key ][] = $row;
        }

        foreach ( $groups as $rows ) {
            if ( empty( $rows ) ) {
                continue;
            }

            $template   = $rows[0];
            $invoice_id = isset( $template->invoice_id ) ? absint( $template->invoice_id ) : 0;
            $user_id    = isset( $template->user_id ) ? absint( $template->user_id ) : 0;

            $live_rows = gpd_uupd_resolve_live_download_rows( $item_id, $invoice_id );
            if ( empty( $live_rows ) ) {
                continue;
            }

            $expected_ids = array();
            foreach ( $live_rows as $live_row ) {
                if ( ! empty( $live_row->download_id ) ) {
                    $expected_ids[] = (string) $live_row->download_id;
                }
            }
            $expected_ids = array_values( array_unique( $expected_ids ) );
            if ( empty( $expected_ids ) ) {
                continue;
            }

            $existing_ids = array();
            foreach ( $rows as $row ) {
                if ( ! empty( $row->download_id ) ) {
                    $existing_ids[] = (string) $row->download_id;
                }
            }
            $existing_ids = array_values( array_unique( $existing_ids ) );

            $to_delete = array_diff( $existing_ids, $expected_ids );
            $to_add    = array_diff( $expected_ids, $existing_ids );

            if ( ! empty( $to_delete ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $to_delete ), '%s' ) );
                $sql = $wpdb->prepare(
                    "DELETE FROM {$tables['permissions']}
                     WHERE item_id = %d
                       AND invoice_id = %d
                       AND user_id = %d
                       AND download_id IN ({$placeholders})",
                    array_merge( array( $item_id, $invoice_id, $user_id ), array_values( $to_delete ) )
                );
                $wpdb->query( $sql );
            }

            foreach ( $to_add as $new_download_id ) {
                $data = (array) $template;
                unset( $data['permission_id'] );

                $data['item_id']     = $item_id;
                $data['invoice_id']  = $invoice_id;
                $data['user_id']     = $user_id;
                $data['download_id'] = $new_download_id;

                $wpdb->insert( $tables['permissions'], $data );
            }
        }
    }
}

if ( ! function_exists( 'gpd_uupd_sync_permissions_on_variation_meta_update' ) ) {
    function gpd_uupd_sync_permissions_on_variation_meta_update( $meta_id, $post_id, $meta_key, $meta_value ) {
        if ( ! in_array( $meta_key, array( '_wpinv_item_variations', '_getpaid_item_downloads' ), true ) ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post || 'wpi_item' !== $post->post_type ) {
            return;
        }

        gpd_uupd_sync_item_permissions_to_live_downloads( $post_id );
    }
}

add_action( 'updated_post_meta', 'gpd_uupd_sync_permissions_on_variation_meta_update', 20, 4 );
add_action( 'added_post_meta', 'gpd_uupd_sync_permissions_on_variation_meta_update', 20, 4 );
