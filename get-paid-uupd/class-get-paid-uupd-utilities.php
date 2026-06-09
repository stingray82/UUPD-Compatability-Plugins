<?php

/**
 * Reorder GetPaid downloads so the newest-added file(s) appear at the top of
 * the "Downloadable Files" list for wpi_item.
 *
 * - Runs when _getpaid_item_downloads meta is added/updated (correct timing).
 * - Only runs if the item is marked with _wpi_sync_latest_download.
 * - Detects "new" rows by comparing download_id to a stored previous list.
 * - Moves any newly added rows to the top (preserves relative order).
 * - Uses a recursion guard to avoid infinite loops.
 */

add_action( 'updated_post_meta', 'wpi_reorder_downloads_on_meta_update', 10, 4 );
add_action( 'added_post_meta',   'wpi_reorder_downloads_on_meta_update', 10, 4 );

function wpi_reorder_downloads_on_meta_update( $meta_id, $post_id, $meta_key, $meta_value ) {

    if ( $meta_key !== '_getpaid_item_downloads' ) {
        return;
    }

    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== 'wpi_item' ) {
        return;
    }

    // Only run for items explicitly marked "sync latest".
    $sync_latest = get_post_meta( $post_id, '_wpi_sync_latest_download', true );
    if ( ! $sync_latest ) {
        return;
    }

    // Recursion guard (because we'll update the same meta key).
    static $running = [];
    if ( isset( $running[ $post_id ] ) ) {
        return;
    }
    $running[ $post_id ] = true;

    // Always read the freshest saved value.
    $downloads = get_post_meta( $post_id, '_getpaid_item_downloads', true );

    // If there are no downloads, clean up helper meta and exit.
    if ( empty( $downloads ) || ! is_array( $downloads ) ) {
        delete_post_meta( $post_id, '_wpi_prev_download_ids' );
        unset( $running[ $post_id ] );
        return;
    }

    // If only 0/1 rows, nothing to reorder — but still keep prev ids in sync.
    if ( count( $downloads ) < 2 ) {
        $only_ids = [];
        foreach ( $downloads as $row ) {
            if ( ! empty( $row['download_id'] ) ) $only_ids[] = (string) $row['download_id'];
        }
        update_post_meta( $post_id, '_wpi_prev_download_ids', array_values( array_unique( $only_ids ) ) );
        unset( $running[ $post_id ] );
        return;
    }

    // Build list of current ids in order.
    $current_ids = [];
    foreach ( $downloads as $row ) {
        if ( ! empty( $row['download_id'] ) ) {
            $current_ids[] = (string) $row['download_id'];
        }
    }

    // Previously known ids.
    $prev_ids = get_post_meta( $post_id, '_wpi_prev_download_ids', true );
    if ( ! is_array( $prev_ids ) ) $prev_ids = [];
    $prev_ids = array_map( 'strval', $prev_ids );

    // New ids detected.
    $new_ids = array_values( array_diff( $current_ids, $prev_ids ) );

    // If nothing new, just refresh stored ids and exit.
    if ( empty( $new_ids ) ) {
        update_post_meta( $post_id, '_wpi_prev_download_ids', array_values( array_unique( $current_ids ) ) );
        unset( $running[ $post_id ] );
        return;
    }

    // Move any new rows to the top (preserving relative order of the new rows).
    $new_rows = [];
    $old_rows = [];

    foreach ( $downloads as $row ) {
        $id = ! empty( $row['download_id'] ) ? (string) $row['download_id'] : '';
        if ( $id && in_array( $id, $new_ids, true ) ) {
            $new_rows[] = $row;
        } else {
            $old_rows[] = $row;
        }
    }

    $reordered = array_values( array_merge( $new_rows, $old_rows ) );

    // Only update if changed (prevents pointless writes).
    if ( $reordered !== $downloads ) {
        update_post_meta( $post_id, '_getpaid_item_downloads', $reordered );
    }

    // Update stored ids to the final set.
    $final_ids = [];
    foreach ( $reordered as $row ) {
        if ( ! empty( $row['download_id'] ) ) $final_ids[] = (string) $row['download_id'];
    }
    update_post_meta( $post_id, '_wpi_prev_download_ids', array_values( array_unique( $final_ids ) ) );

    unset( $running[ $post_id ] );
}

/**
 * When a wpi_item is saved AND it's marked as "sync latest",
 * update customer download permissions so they match the *current*
 * _getpaid_item_downloads list for that item.
 *
 * - If downloads were added → add matching permissions.
 * - If downloads were removed → remove matching permissions.
 *
 * This keeps Invoice Downloads (and anything else using permissions)
 * in lock-step with the "live" list of files for that item.
 */
/**
 * Keep customer download permissions for a wpi_item in sync with the
 * current _getpaid_item_downloads list *whenever the item is saved*,
 * but only if the item has the "_wpi_sync_latest_download" flag set.
 *
 * Behaviour:
 *  - If a download_id exists in permissions but not in _getpaid_item_downloads,
 *    it's deleted.
 *  - If a download_id exists in _getpaid_item_downloads but not yet in the
 *    permissions for a given (invoice_id, user_id) pair, it's inserted.
 *
 * This makes invoice "Invoice Downloads" behave like a live view of the
 * current item downloads for these products.
 */
add_action( 'save_post', 'my_gpd_sync_item_permissions_to_current_downloads', 999, 3 );
function my_gpd_sync_item_permissions_to_current_downloads( $post_id, $post, $update ) {

    // Only care about wpi_item posts.
    if ( ! $post || 'wpi_item' !== $post->post_type ) {
        return;
    }

    // Basic safety.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }

    // Keep GetPaid permissions aligned with the live variation download mapping.
    // Variation download_ids map to {prefix}getpaid_downloadable_items.id;
    // permissions store that row's UUID download_id.
    if ( function_exists( 'gpd_uupd_sync_item_permissions_to_live_downloads' ) ) {
        gpd_uupd_sync_item_permissions_to_live_downloads( $post_id );
    }
}




/**
 * Conditionally disable the GetPaid "Invoice Downloads" section
 * based on how recently the invoice was completed.
 *
 * Downloads will ONLY show if the invoice was completed
 * within the last X minutes (default: 60).
 *
 * This was added due to a bug that has been fixed in 2.0.2 so will likely be commented out
 */
add_action( 'getpaid_actions', function() {

    // How many minutes after completion should downloads remain visible?
    $minutes_limit = 60;

    // Only care about frontend.
    if ( is_admin() ) {
        return;
    }

    // Get invoice_key from the URL, e.g. ?invoice_key=invoice_xxx
    $invoice_key = isset( $_GET['invoice_key'] )
        ? sanitize_text_field( wp_unslash( $_GET['invoice_key'] ) )
        : '';

    $show_downloads = false;

    if ( ! empty( $invoice_key ) ) {

        global $wpdb;

        // Works with your prefixed table, e.g. iwp47f5_getpaid_invoices
        $table_name = $wpdb->prefix . 'getpaid_invoices';

        // Get completed_date for this invoice_key.
        $completed_date = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT completed_date FROM {$table_name} WHERE invoice_key = %s LIMIT 1",
                $invoice_key
            )
        );

        if ( ! empty( $completed_date ) ) {

            $completed_ts = strtotime( $completed_date );

            if ( $completed_ts ) {

                // Current time in WP local timezone.
                $now_ts       = current_time( 'timestamp' );
                $diff_minutes = ( $now_ts - $completed_ts ) / 60;

                // Only allow invoices completed in the past X minutes.
                if ( $diff_minutes >= 0 && $diff_minutes <= $minutes_limit ) {
                    $show_downloads = true;
                }
            }
        }
    }

    // If the invoice is NOT within the allowed window, remove downloads output.
    if ( ! $show_downloads ) {

        remove_action(
            'wpinv_receipt_end',
            'GetPaid_Item_Downloads_Receipt_Page::output'
        );

        remove_action(
            'getpaid_invoice_after_line_items',
            'GetPaid_Item_Downloads_Receipt_Page::output'
        );
    }

}, 999 );


/*
* Fix issues with the lincense view 
*/
add_action( 'admin_footer', function () {

    if ( empty( $_GET['page'] ) || $_GET['page'] !== 'wpinv-licenses' ) {
        return;
    }
    ?>
    <script>
        (function () {
            const table = document.querySelector('table.wp-list-table.table-view-list.licenses');
            if (!table) return;

            // ---------- Header / footer: License -> ID, insert User + License Key ----------
            function setupHeader(section) {
                if (!section) return;
                const row = section.querySelector('tr');
                if (!row) return;

                const licenseTh = row.querySelector('#license') || row.querySelector('.column-license');
                if (!licenseTh) return;

                // Rename License -> ID
                licenseTh.id = 'id';
                licenseTh.className = licenseTh.className.replace('column-license', 'column-id');
                const span = licenseTh.querySelector('span');
                if (span) {
                    span.textContent = 'ID';
                } else {
                    licenseTh.textContent = 'ID';
                }

                // User column
                const userTh = document.createElement('th');
                userTh.scope = 'col';
                userTh.id = 'user';
                userTh.className = 'manage-column column-user';
                userTh.textContent = 'User';
                row.insertBefore(userTh, licenseTh.nextSibling);

                // License Key column
                const keyTh = document.createElement('th');
                keyTh.scope = 'col';
                keyTh.id = 'license_key';
                keyTh.className = 'manage-column column-license_key';
                keyTh.textContent = 'License Key';
                row.insertBefore(keyTh, userTh.nextSibling);
            }

            setupHeader(table.tHead);
            setupHeader(table.tFoot);

            const tbody = table.tBodies[0];
            if (!tbody) return;

            // ---------- Body rows ----------
            Array.from(tbody.rows).forEach(function (row) {
                const idTd = row.querySelector('td.license.column-license');
                if (!idTd) return;

                // Repurpose License cell as ID cell
                idTd.classList.remove('license');
                idTd.classList.add('id', 'column-id');
                idTd.dataset.colname = 'ID';

                // --- Extract user link ---
                const userAnchor = idTd.querySelector('a[href^="user-edit.php"]');
                const userTd = document.createElement('td');
                userTd.className = 'user column-user';
                userTd.dataset.colname = 'User';

                if (userAnchor) {
                    const strong = userAnchor.closest('strong') || idTd;
                    Array.from(strong.childNodes).forEach(function (node) {
                        if (node === userAnchor) {
                            strong.removeChild(node);
                        } else if (node.nodeType === Node.TEXT_NODE && node.textContent.includes('for')) {
                            // remove " for " text
                            strong.removeChild(node);
                        }
                    });
                    userTd.appendChild(userAnchor);
                }
                row.insertBefore(userTd, idTd.nextSibling);

                // --- Move license key into its own cell + Copy button ---
                const keySpan = idTd.querySelector('span.text-muted.form-text');
                const keyTd = document.createElement('td');
                keyTd.className = 'license_key column-license_key';
                keyTd.dataset.colname = 'License Key';

                if (keySpan) {
                    const key = keySpan.textContent.trim();
                    keySpan.parentNode.removeChild(keySpan);

                    const wrap = document.createElement('div');
                    wrap.className = 'license-key-wrap';
                    wrap.appendChild(keySpan);

                    const copyBtn = document.createElement('button');
                    copyBtn.type = 'button';
                    copyBtn.className = 'button button-small wpinv-copy-license';
                    copyBtn.dataset.key = key;
                    copyBtn.textContent = 'Copy';

                    wrap.appendChild(copyBtn);
                    keyTd.appendChild(wrap);
                }

                row.insertBefore(keyTd, userTd.nextSibling);
            });

            // ---------- Copy handler ----------
            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.wpinv-copy-license');
                if (!btn) return;

                const key = btn.dataset.key || '';
                if (!key) return;

                navigator.clipboard.writeText(key).then(function () {
                    const oldText = btn.textContent;
                    btn.textContent = 'Copied!';
                    setTimeout(function () {
                        btn.textContent = oldText;
                    }, 1200);
                }).catch(function () {
                    alert('Could not copy license key.');
                });
            });
        })();
    </script>

    <style>
        .wp-list-table.table-view-list.licenses .column-id {
            width: 6%;
        }
        .wp-list-table.table-view-list.licenses .column-user {
            width: 12%;
        }
        .wp-list-table.table-view-list.licenses .column-license_key {
            width: 18%;
        }
        .wp-list-table.table-view-list.licenses .license-key-wrap {
            margin-top: 4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
    </style>
    <?php
});




/* UUPD vendor settings + helpers */
if ( ! defined( 'WPI_UUPD_OPTION_VENDOR' ) ) {
    define( 'WPI_UUPD_OPTION_VENDOR', 'wpi_uupd_global_vendor' );
}

if ( ! function_exists( 'wpi_uupd_get_vendor_slug_raw' ) ) {
    function wpi_uupd_get_vendor_slug_raw() {
        $value = get_option( WPI_UUPD_OPTION_VENDOR, '' );
        return is_string( $value ) ? sanitize_key( $value ) : '';
    }
}

if ( ! function_exists( 'wpi_uupd_get_vendor_slug' ) ) {
    function wpi_uupd_get_vendor_slug( $item = [] ) {
        $vendor = wpi_uupd_get_vendor_slug_raw();
        if ( '' === $vendor ) {
            $vendor = 'yourvendor';
        }
        $vendor = apply_filters( 'wpi_uupd_compat/vendor', $vendor, $item );
        $vendor = sanitize_key( (string) $vendor );
        return '' !== $vendor ? $vendor : 'yourvendor';
    }
}

if ( ! function_exists( 'wpi_uupd_build_vendor_slug_constant_prefix' ) ) {
    function wpi_uupd_build_vendor_slug_constant_prefix( $vendor, $slug, $post_id ) {
        $vendor_base = sanitize_key( (string) $vendor );
        $vendor_base = strtoupper( str_replace( '-', '_', $vendor_base ) );
        $vendor_base = preg_replace( '/[^A-Z0-9_]+/', '_', $vendor_base );
        $vendor_base = preg_replace( '/_+/', '_', $vendor_base );
        $vendor_base = trim( $vendor_base, '_' );
        if ( '' === $vendor_base ) {
            $vendor_base = 'YOURVENDOR';
        }

        $slug_base = sanitize_key( (string) $slug );
        $slug_base = strtoupper( str_replace( '-', '_', $slug_base ) );
        $slug_base = preg_replace( '/[^A-Z0-9_]+/', '_', $slug_base );
        $slug_base = preg_replace( '/_+/', '_', $slug_base );
        $slug_base = trim( $slug_base, '_' );
        if ( '' === $slug_base ) {
            $slug_base = 'ITEM';
        }

        return $vendor_base . '_' . $slug_base;
    }
}

add_action( 'admin_menu', function() {
    add_options_page(
        'UUPD GetPaid Settings',
        'UUPD GetPaid Settings',
        'manage_options',
        'uupd-getpaid-settings',
        'wpi_uupd_render_settings_page'
    );
} );

if ( ! function_exists( 'wpi_uupd_render_settings_page' ) ) {
    function wpi_uupd_render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'default' ) );
        }

        if (
            isset( $_POST['wpi_uupd_vendor_nonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpi_uupd_vendor_nonce'] ) ), 'wpi_uupd_save_vendor' )
        ) {
            $vendor = isset( $_POST['wpi_uupd_vendor'] ) ? sanitize_key( wp_unslash( $_POST['wpi_uupd_vendor'] ) ) : '';
            if ( '' === $vendor ) {
                delete_option( WPI_UUPD_OPTION_VENDOR );
            } else {
                update_option( WPI_UUPD_OPTION_VENDOR, $vendor, false );
            }
            echo '<div class="notice notice-success is-dismissible"><p>Vendor saved.</p></div>';
        }

        $vendor = wpi_uupd_get_vendor_slug_raw();
        ?>
        <div class="wrap">
            <h1>UUPD GetPaid Settings</h1>
            <form method="post">
                <?php wp_nonce_field( 'wpi_uupd_save_vendor', 'wpi_uupd_vendor_nonce' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wpi_uupd_vendor">Global vendor slug</label></th>
                        <td>
                            <input type="text" id="wpi_uupd_vendor" name="wpi_uupd_vendor" value="<?php echo esc_attr( $vendor ); ?>" class="regular-text" placeholder="rup" />
                            <p class="description">Used in generated bootloader constants and vendor-aware UI/updater registration.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save vendor' ); ?>
            </form>
        </div>
        <?php
    }
}

/* Add our Coloums and our Bootstap Copy  */

/**
 * Columns
 */
add_filter( 'manage_edit-wpi_item_columns', function( $columns ) {

    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[$key] = $label;

        if ( 'title' === $key ) {
            $new['wpi_bootloader_copy'] = 'Bootloader';
            $new['wpi_inline_copy']     = 'Inline display';
        }
    }

    if ( ! isset( $new['wpi_bootloader_copy'] ) ) {
        $new['wpi_bootloader_copy'] = 'Bootloader';
        $new['wpi_inline_copy']     = 'Inline display';
    }

    return $new;
}, 20 );


/**
 * Column content
 */
add_action( 'manage_wpi_item_posts_custom_column', function( $column, $post_id ) {

    if ( $column !== 'wpi_bootloader_copy' && $column !== 'wpi_inline_copy' ) {
        return;
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return;
    }

    $friendly = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );

    $slug = get_post_meta( $post_id, '_wpi_plugin_slug', true );
    $slug = ( is_string( $slug ) && $slug !== '' ) ? $slug : $post->post_name;

    $version = get_post_meta( $post_id, 'getpaid_license_manager_item_version', true );
    $version = ( is_string( $version ) && $version !== '' ) ? $version : '1.0';

    $item_number = (int) $post_id;

    $vendor = wpi_uupd_get_vendor_slug(
        [
            'post_id' => $post_id,
            'slug'    => $slug,
            'name'    => $friendly,
        ]
    );

    $prefix = wpi_uupd_build_vendor_slug_constant_prefix( $vendor, $slug, $post_id );

    $server_base = apply_filters( 'wpi_item_copy_server_base', home_url( '/' ), $post_id, $slug, $vendor );

    // Escape values for literal single-quoted PHP output.
    $esc = static function( $value ) {
        return str_replace(
            [ '\\', "'" ],
            [ '\\\\', "\\'" ],
            (string) $value
        );
    };

    $friendly_esc    = $esc( $friendly );
    $slug_esc        = $esc( $slug );
    $version_esc     = $esc( $version );
    $vendor_esc      = $esc( $vendor );
    $server_base_esc = $esc( $server_base );

    $c_vendor    = "UUPD_{$prefix}_VENDOR";
    $c_version   = "UUPD_{$prefix}_VERSION";
    $c_main_file = "UUPD_{$prefix}_MAIN_FILE";
    $c_item_no   = "UUPD_{$prefix}_ITEM_NUMBER";
    $c_name      = "UUPD_{$prefix}_FRIENDLY_NAME";
    $c_slug      = "UUPD_{$prefix}_SLUG";
    $c_server    = "UUPD_{$prefix}_SERVER_BASE";
    $c_menu      = "UUPD_{$prefix}_MENU_SLUG";

    $lines   = [];
    $lines[] = "define( '{$c_vendor}', '{$vendor_esc}' );";
    $lines[] = "define( '{$c_version}', '{$version_esc}' );";
    $lines[] = "define( '{$c_main_file}', __FILE__ );";
    $lines[] = "define( '{$c_item_no}', {$item_number} );";
    $lines[] = "define( '{$c_name}', '{$friendly_esc}' );";
    $lines[] = "define( '{$c_slug}', '{$slug_esc}' );";
    $lines[] = "define( '{$c_server}', '{$server_base_esc}' );";
    $lines[] = "define( '{$c_menu}', {$c_vendor} . '-' . {$c_slug} );";
    $lines[] = "";
    $lines[] = "add_action( 'plugins_loaded', function() {";
    $lines[] = "    require_once __DIR__ . '/inc/updater.php';";
    $lines[] = "    require_once __DIR__ . '/inc/class-uupd-license-ui.php';";
    $lines[] = "";
    $lines[] = "    \\UUPD\\UI\\License\\V1\\UUPD_License_UI::register([";
    $lines[] = "        'vendor'         => {$c_vendor},";
    $lines[] = "        'slug'           => {$c_slug},";
    $lines[] = "        'item_id'        => {$c_item_no},";
    $lines[] = "        'license_server' => {$c_server},";
    $lines[] = "        'metadata_base'  => {$c_server},";
    $lines[] = "        'plugin_name'    => {$c_name},";
    $lines[] = "        'menu_parent'    => 'top_level',";
    $lines[] = "        'menu_slug'      => {$c_menu},";
    $lines[] = "        'page_title'     => {$c_name} . ' License',";
    $lines[] = "        'menu_title'     => {$c_name} . ' License',";
    $lines[] = "        'capability'     => 'manage_options',";
    $lines[] = "        'pass_token'     => false,";
    $lines[] = "        'token_param'    => 'token',";
    $lines[] = "    ]);";
    $lines[] = "";
    $lines[] = "    \\UUPD\\V2\\UUPD_Updater_V2::register([";
    $lines[] = "        'vendor'      => {$c_vendor},";
    $lines[] = "        'plugin_file' => plugin_basename( {$c_main_file} ),";
    $lines[] = "        'slug'        => {$c_slug},";
    $lines[] = "        'name'        => {$c_name},";
    $lines[] = "        'version'     => {$c_version},";
    $lines[] = "        'server'      => '',";
    $lines[] = "    ]);";
    $lines[] = "}, 1 );";

    $bootloader = implode( "\n", $lines );

    $inline = "<?php \$inst = \\UUPD\\UI\\License\\V1\\UUPD_License_UI::get_instance( {$c_vendor}, {$c_slug} ); if ( \$inst ) { \$inst->render_page(); } ?>";

    if ( $column === 'wpi_bootloader_copy' ) {
        printf(
            '<button type="button" class="button button-small wpi-copy-btn" data-wpi-copy="%s">Copy bootloader</button>',
            esc_attr( $bootloader )
        );
    } else {
        printf(
            '<button type="button" class="button button-small wpi-copy-btn" data-wpi-copy="%s">Copy inline</button>',
            esc_attr( $inline )
        );
    }

}, 10, 2 );



/**
 * JS (admin list only)
 */
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook !== 'edit.php' ) return;

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'edit-wpi_item' ) return;

    wp_register_script( 'wpi-item-copy-columns', '', [], '1.0', true );
    wp_enqueue_script( 'wpi-item-copy-columns' );

    $js = "(function(){
      function copyText(text){
        if(navigator.clipboard && window.isSecureContext){
          return navigator.clipboard.writeText(text);
        }
        return new Promise(function(resolve,reject){
          try{
            var ta=document.createElement('textarea');
            ta.value=text;
            ta.setAttribute('readonly','');
            ta.style.position='fixed';
            ta.style.top='-9999px';
            document.body.appendChild(ta);
            ta.select();
            var ok=document.execCommand('copy');
            document.body.removeChild(ta);
            ok?resolve():reject();
          }catch(e){reject(e);}
        });
      }
      document.addEventListener('click',function(e){
        var btn=e.target.closest('.wpi-copy-btn');
        if(!btn) return;
        var payload=btn.getAttribute('data-wpi-copy')||'';
        copyText(payload).then(function(){
          var old=btn.textContent;
          btn.textContent='Copied!';
          setTimeout(function(){ btn.textContent=old; }, 900);
        }).catch(function(){
          alert('Copy failed. Please copy manually.');
        });
      });
    })();";

    wp_add_inline_script( 'wpi-item-copy-columns', $js );
});
