<?php
/**
 * Meta box + save handler for wpi_item
 */

/**
 * Register meta box
 */
add_action( 'add_meta_boxes', 'wpi_item_register_meta_box' );
function wpi_item_register_meta_box() {
    add_meta_box(
        'wpi_item_meta_box',
        'WPI Item Details',
        'wpi_item_meta_box_callback',
        'wpi_item',
        'normal',
        'high'
    );
}

/**
 * Keep our WPI Item Details meta box at the top of the normal column by default.
 */
add_filter( 'get_user_option_meta-box-order_wpi_item', 'wpi_item_metabox_force_top' );
function wpi_item_metabox_force_top( $order ) {

    if ( empty( $order['normal'] ) ) {
        return $order;
    }

    $boxes     = explode( ',', $order['normal'] );
    $preferred = array( 'wpi_item_meta_box' );

    // Remove our box if present, then prepend it.
    $boxes           = array_diff( $boxes, $preferred );
    $order['normal'] = implode( ',', array_merge( $preferred, $boxes ) );

    return $order;
}

/**
 * Enqueue admin JS for meta syncing (version + changelog)
 */
add_action( 'admin_enqueue_scripts', 'wpi_item_enqueue_meta_sync_js' );
function wpi_item_enqueue_meta_sync_js( $hook ) {

    // Only on post edit/new screens
    if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
        return;
    }

    $screen = get_current_screen();
    if ( empty( $screen ) || 'wpi_item' !== $screen->post_type ) {
        return;
    }

    // Adjust path if this file is inside an /includes folder (see note below)
    wp_enqueue_script(
        'gp-uupd-meta-sync',
        plugins_url( 'assets/js/gp-uupd-meta-sync.js', __FILE__ ),
        array( 'jquery' ),
        '1.0.0',
        true
    );
}


/**
 * Is this item a variation product?
 */
function wpi_uupd_meta_item_has_variations( $item_id ) {
    if ( function_exists( 'gpd_uupd_item_has_variations' ) ) {
        return gpd_uupd_item_has_variations( $item_id );
    }

    $has_variations = get_post_meta( absint( $item_id ), '_wpinv_has_variations', true );
    if ( empty( $has_variations ) || '0' === (string) $has_variations ) {
        return false;
    }

    $variations = get_post_meta( absint( $item_id ), '_wpinv_item_variations', true );
    $variations = maybe_unserialize( $variations );

    return is_array( $variations ) && ! empty( $variations );
}

/**
 * Current update row for a non-variation item.
 *
 * This intentionally follows the same source of truth as the updater: the first
 * row in GetPaid's Downloadable Files metabox / _getpaid_item_downloads order.
 */
function wpi_uupd_meta_get_current_update_file_row( $item_id ) {
    if ( function_exists( 'gpd_uupd_get_current_non_variation_download_rows' ) ) {
        $rows = gpd_uupd_get_current_non_variation_download_rows( $item_id, 1 );
        if ( ! empty( $rows[0] ) ) {
            return $rows[0];
        }
    }

    $downloads = get_post_meta( absint( $item_id ), '_getpaid_item_downloads', true );
    $downloads = maybe_unserialize( $downloads );
    if ( empty( $downloads ) || ! is_array( $downloads ) ) {
        return null;
    }

    $first_key = array_key_first( $downloads );
    $first     = is_array( $downloads[ $first_key ] ) ? $downloads[ $first_key ] : array();

    return (object) array(
        'id'          => is_numeric( $first_key ) ? absint( $first_key ) : 0,
        'item_name'   => isset( $first['name'] ) ? (string) $first['name'] : ( isset( $first['file_name'] ) ? (string) $first['file_name'] : '' ),
        'item_file'   => isset( $first['file'] ) ? (string) $first['file'] : ( isset( $first['file_url'] ) ? (string) $first['file_url'] : '' ),
        'download_id' => isset( $first['download_id'] ) ? (string) $first['download_id'] : '',
    );
}

/**
 * Persist a small diagnostic pointer to the current non-variation update file.
 * This is for admin clarity only; the updater still resolves from the live data.
 */
function wpi_uupd_meta_update_current_update_file_meta( $item_id ) {
    $item_id = absint( $item_id );
    if ( ! $item_id || wpi_uupd_meta_item_has_variations( $item_id ) ) {
        delete_post_meta( $item_id, '_wpi_current_update_file_id' );
        delete_post_meta( $item_id, '_wpi_current_update_file_name' );
        delete_post_meta( $item_id, '_wpi_current_update_file_url' );
        delete_post_meta( $item_id, '_wpi_current_update_download_id' );
        return;
    }

    $row = wpi_uupd_meta_get_current_update_file_row( $item_id );
    if ( ! $row || empty( $row->item_file ) ) {
        delete_post_meta( $item_id, '_wpi_current_update_file_id' );
        delete_post_meta( $item_id, '_wpi_current_update_file_name' );
        delete_post_meta( $item_id, '_wpi_current_update_file_url' );
        delete_post_meta( $item_id, '_wpi_current_update_download_id' );
        return;
    }

    update_post_meta( $item_id, '_wpi_current_update_file_id', isset( $row->id ) ? absint( $row->id ) : 0 );
    update_post_meta( $item_id, '_wpi_current_update_file_name', isset( $row->item_name ) ? sanitize_text_field( $row->item_name ) : basename( (string) $row->item_file ) );
    update_post_meta( $item_id, '_wpi_current_update_file_url', esc_url_raw( $row->item_file ) );
    update_post_meta( $item_id, '_wpi_current_update_download_id', isset( $row->download_id ) ? sanitize_text_field( $row->download_id ) : '' );
}

/**
 * Meta box output
 */
function wpi_item_meta_box_callback( $post ) {

    // Security
    wp_nonce_field( 'wpi_item_meta_box', 'wpi_item_meta_box_nonce' );

    // Make sure media uploader is available
    wp_enqueue_media();

    // Meta values
    $last_updated = get_post_meta( $post->ID, '_wpi_last_updated', true );

    // Media IDs + URLs
    $banner_low_id   = get_post_meta( $post->ID, '_wpi_banner_low_id', true );
    $banner_low_url  = get_post_meta( $post->ID, '_wpi_banner_low_url', true );
    $banner_high_id  = get_post_meta( $post->ID, '_wpi_banner_high_id', true );
    $banner_high_url = get_post_meta( $post->ID, '_wpi_banner_high_url', true );
    $icon_1x_id      = get_post_meta( $post->ID, '_wpi_icon_1x_id', true );
    $icon_1x_url     = get_post_meta( $post->ID, '_wpi_icon_1x_url', true );
    $icon_2x_id      = get_post_meta( $post->ID, '_wpi_icon_2x_id', true );
    $icon_2x_url     = get_post_meta( $post->ID, '_wpi_icon_2x_url', true );

    // Other fields
    $requires_php    = get_post_meta( $post->ID, '_wpi_requires_php', true );
    $requires        = get_post_meta( $post->ID, '_wpi_requires', true );
    $tested_upto     = get_post_meta( $post->ID, '_wpi_tested_upto', true );
    $author          = get_post_meta( $post->ID, '_wpi_author', true );
    $author_homepage = get_post_meta( $post->ID, '_wpi_author_homepage', true );
    $item_version    = get_post_meta( $post->ID, 'getpaid_license_manager_item_version', true );
    $plugin_slug     = get_post_meta( $post->ID, '_wpi_plugin_slug', true );
    $sync_latest = get_post_meta( $post->ID, '_wpi_sync_latest_download', true );

    $wpi_has_variations       = wpi_uupd_meta_item_has_variations( $post->ID );
    $wpi_current_update_file  = $wpi_has_variations ? null : wpi_uupd_meta_get_current_update_file_row( $post->ID );
    $wpi_current_file_id      = $wpi_current_update_file && isset( $wpi_current_update_file->id ) ? absint( $wpi_current_update_file->id ) : 0;
    $wpi_current_file_name    = $wpi_current_update_file && ! empty( $wpi_current_update_file->item_name ) ? (string) $wpi_current_update_file->item_name : '';
    $wpi_current_file_url     = $wpi_current_update_file && ! empty( $wpi_current_update_file->item_file ) ? (string) $wpi_current_update_file->item_file : '';
    if ( '' === $wpi_current_file_name && '' !== $wpi_current_file_url ) {
        $wpi_current_file_name = basename( $wpi_current_file_url );
    }

    $wpi_variation_update_ids = array();
    if ( $wpi_has_variations ) {
        // Preferred storage for UUPD's per-variation updater file choice.
        // Keyed by variation ID so it survives GetPaid re-indexing/re-saving its own variation array.
        $wpi_saved_update_map = get_post_meta( $post->ID, '_wpi_uupd_variation_update_download_ids', true );
        $wpi_saved_update_map = maybe_unserialize( $wpi_saved_update_map );
        if ( ! is_array( $wpi_saved_update_map ) ) {
            $wpi_saved_update_map = array();
        }

        $wpi_variations_raw = get_post_meta( $post->ID, '_wpinv_item_variations', true );
        $wpi_variations_raw = maybe_unserialize( $wpi_variations_raw );
        if ( is_array( $wpi_variations_raw ) ) {
            foreach ( $wpi_variations_raw as $idx => $variation ) {
                if ( ! is_array( $variation ) ) {
                    continue;
                }

                $variation_id = ! empty( $variation['id'] ) ? sanitize_key( (string) $variation['id'] ) : '';
                $saved_update_id = 0;

                if ( $variation_id && ! empty( $wpi_saved_update_map[ $variation_id ] ) ) {
                    $saved_update_id = absint( $wpi_saved_update_map[ $variation_id ] );
                } elseif ( ! empty( $variation['update_download_id'] ) ) {
                    // Back-compat for early builds that embedded this in _wpinv_item_variations.
                    $saved_update_id = absint( $variation['update_download_id'] );
                }

                if ( $saved_update_id ) {
                    $wpi_variation_update_ids[ (string) $idx ] = (string) $saved_update_id;
                    if ( $variation_id ) {
                        $wpi_variation_update_ids[ $variation_id ] = (string) $saved_update_id;
                    }
                }
            }
        }
    }

    // WYSIWYG fields (for REST "sections")
    $desc_content  = get_post_meta( $post->ID, 'getpaid_license_manager_description', true );
    $inst_content  = get_post_meta( $post->ID, 'getpaid_license_manager_installation', true );
    $faq_content   = get_post_meta( $post->ID, 'getpaid_license_manager_faq', true );
    $chlg_content  = get_post_meta( $post->ID, 'getpaid_license_manager_changelog', true );

    // Preview URLs for attachment IDs
    $banner_low_src  = $banner_low_id  ? wp_get_attachment_image_url( $banner_low_id, 'thumbnail' )  : '';
    $banner_high_src = $banner_high_id ? wp_get_attachment_image_url( $banner_high_id, 'thumbnail' ) : '';
    $icon_1x_src     = $icon_1x_id     ? wp_get_attachment_image_url( $icon_1x_id, 'thumbnail' )     : '';
    $icon_2x_src     = $icon_2x_id     ? wp_get_attachment_image_url( $icon_2x_id, 'thumbnail' )     : '';
    ?>
    <table class="form-table">
        <tbody>

        <!-- Last updated (read-only) -->
        <tr>
            <th scope="row"><label>Last Updated</label></th>
            <td>
                <input type="text"
                       class="regular-text"
                       value="<?php echo esc_attr( $last_updated ); ?>"
                       readonly />
                <p class="description">
                    Automatically set to the date/time the item was last saved.
                </p>
            </td>
        </tr>

        <?php if ( ! $wpi_has_variations ) : ?>
        <!-- Current update file for non-variation items -->
        <tr id="wpi-current-update-file-row">
            <th scope="row"><label>Current Update File</label></th>
            <td>
                <div class="wpi-current-update-file-card" data-current-file-id="<?php echo esc_attr( $wpi_current_file_id ); ?>">
                    <strong class="wpi-current-update-file-name">
                        <?php echo $wpi_current_file_name ? esc_html( $wpi_current_file_name ) : esc_html__( 'No update file selected', 'get-paid-uupd' ); ?>
                    </strong>
                    <code class="wpi-current-update-file-url" style="display:block;margin-top:4px;word-break:break-all;">
                        <?php echo $wpi_current_file_url ? esc_html( $wpi_current_file_url ) : esc_html__( 'Add or move a file to the top of Downloadable Files, then save.', 'get-paid-uupd' ); ?>
                    </code>
                </div>
                <p class="description">
                    The top row in <strong>Downloadable Files</strong> is served by the updater for non-variation products. Drag a different file to the top and save/update the item to change it.
                </p>
            </td>
        </tr>
        <?php endif; ?>

        <!-- BANNER LOW -->
        <tr>
            <th scope="row"><label for="wpi_banner_low_url">Banner (Low)</label></th>
            <td>
                <div class="wpi-media-field">
                    <div class="wpi-media-preview" style="margin-bottom:8px;">
                        <img id="wpi_banner_low_preview"
                             src="<?php echo esc_url( $banner_low_src ); ?>"
                             style="max-width:150px;height:auto;<?php echo $banner_low_src ? '' : 'display:none;'; ?>" />
                    </div>

                    <!-- Hidden attachment ID -->
                    <input type="hidden"
                           id="wpi_banner_low_id"
                           name="wpi_banner_low_id"
                           value="<?php echo esc_attr( $banner_low_id ); ?>" />

                    <!-- Media buttons -->
                    <button type="button"
                            class="button wpi-media-upload"
                            data-target="#wpi_banner_low_id"
                            data-preview="#wpi_banner_low_preview">
                        <?php echo $banner_low_id ? 'Change image' : 'Select image'; ?>
                    </button>
                    <button type="button"
                            class="button wpi-media-remove"
                            data-target="#wpi_banner_low_id"
                            data-preview="#wpi_banner_low_preview"
                            <?php echo $banner_low_id ? '' : 'style="display:none;"'; ?>>
                        Remove
                    </button>

                    <!-- Type/URL field -->
                    <p style="margin-top:8px;">
                        <label for="wpi_banner_low_url"><strong>URL / Type value:</strong></label><br/>
                        <input type="text"
                               id="wpi_banner_low_url"
                               name="wpi_banner_low_url"
                               class="regular-text"
                               value="<?php echo esc_attr( $banner_low_url ); ?>" />
                    </p>

                    <p class="description">
                        Choose an image from media, and/or type a URL/value here.
                    </p>
                </div>
            </td>
        </tr>

        <!-- BANNER HIGH -->
        <tr>
            <th scope="row"><label for="wpi_banner_high_url">Banner (High)</label></th>
            <td>
                <div class="wpi-media-field">
                    <div class="wpi-media-preview" style="margin-bottom:8px;">
                        <img id="wpi_banner_high_preview"
                             src="<?php echo esc_url( $banner_high_src ); ?>"
                             style="max-width:150px;height:auto;<?php echo $banner_high_src ? '' : 'display:none;'; ?>" />
                    </div>

                    <input type="hidden"
                           id="wpi_banner_high_id"
                           name="wpi_banner_high_id"
                           value="<?php echo esc_attr( $banner_high_id ); ?>" />

                    <button type="button"
                            class="button wpi-media-upload"
                            data-target="#wpi_banner_high_id"
                            data-preview="#wpi_banner_high_preview">
                        <?php echo $banner_high_id ? 'Change image' : 'Select image'; ?>
                    </button>
                    <button type="button"
                            class="button wpi-media-remove"
                            data-target="#wpi_banner_high_id"
                            data-preview="#wpi_banner_high_preview"
                            <?php echo $banner_high_id ? '' : 'style="display:none;"'; ?>>
                        Remove
                    </button>

                    <p style="margin-top:8px%;">
                        <label for="wpi_banner_high_url"><strong>URL / Type value:</strong></label><br/>
                        <input type="text"
                               id="wpi_banner_high_url"
                               name="wpi_banner_high_url"
                               class="regular-text"
                               value="<?php echo esc_attr( $banner_high_url ); ?>" />
                    </p>

                    <p class="description">
                        Choose an image from media, and/or type a URL/value here.
                    </p>
                </div>
            </td>
        </tr>

        <!-- ICON 1x -->
        <tr>
            <th scope="row"><label for="wpi_icon_1x_url">Icon 1x</label></th>
            <td>
                <div class="wpi-media-field">
                    <div class="wpi-media-preview" style="margin-bottom:8px;">
                        <img id="wpi_icon_1x_preview"
                             src="<?php echo esc_url( $icon_1x_src ); ?>"
                             style="max-width:150px;height:auto;<?php echo $icon_1x_src ? '' : 'display:none;'; ?>" />
                    </div>

                    <input type="hidden"
                           id="wpi_icon_1x_id"
                           name="wpi_icon_1x_id"
                           value="<?php echo esc_attr( $icon_1x_id ); ?>" />

                    <button type="button"
                            class="button wpi-media-upload"
                            data-target="#wpi_icon_1x_id"
                            data-preview="#wpi_icon_1x_preview">
                        <?php echo $icon_1x_id ? 'Change image' : 'Select image'; ?>
                    </button>
                    <button type="button"
                            class="button wpi-media-remove"
                            data-target="#wpi_icon_1x_id"
                            data-preview="#wpi_icon_1x_preview"
                            <?php echo $icon_1x_id ? '' : 'style="display:none;"'; ?>>
                        Remove
                    </button>

                    <p style="margin-top:8px;">
                        <label for="wpi_icon_1x_url"><strong>URL / Type value:</strong></label><br/>
                        <input type="text"
                               id="wpi_icon_1x_url"
                               name="wpi_icon_1x_url"
                               class="regular-text"
                               value="<?php echo esc_attr( $icon_1x_url ); ?>" />
                    </p>

                    <p class="description">
                        Choose an image from media, and/or type a URL/value here.
                    </p>
                </div>
            </td>
        </tr>

        <!-- ICON 2x -->
        <tr>
            <th scope="row"><label for="wpi_icon_2x_url">Icon 2x</label></th>
            <td>
                <div class="wpi-media-field">
                    <div class="wpi-media-preview" style="margin-bottom:8px;">
                        <img id="wpi_icon_2x_preview"
                             src="<?php echo esc_url( $icon_2x_src ); ?>"
                             style="max-width:150px;height:auto;<?php echo $icon_2x_src ? '' : 'display:none;'; ?>" />
                    </div>

                    <input type="hidden"
                           id="wpi_icon_2x_id"
                           name="wpi_icon_2x_id"
                           value="<?php echo esc_attr( $icon_2x_id ); ?>" />

                    <button type="button"
                            class="button wpi-media-upload"
                            data-target="#wpi_icon_2x_id"
                            data-preview="#wpi_icon_2x_preview">
                        <?php echo $icon_2x_id ? 'Change image' : 'Select image'; ?>
                    </button>
                    <button type="button"
                            class="button wpi-media-remove"
                            data-target="#wpi_icon_2x_id"
                            data-preview="#wpi_icon_2x_preview"
                            <?php echo $icon_2x_id ? '' : 'style="display:none;"'; ?>>
                        Remove
                    </button>

                    <p style="margin-top:8px;">
                        <label for="wpi_icon_2x_url"><strong>URL / Type value:</strong></label><br/>
                        <input type="text"
                               id="wpi_icon_2x_url"
                               name="wpi_icon_2x_url"
                               class="regular-text"
                               value="<?php echo esc_attr( $icon_2x_url ); ?>" />
                    </p>

                    <p class="description">
                        Choose an image from media, and/or type a URL/value here.
                    </p>
                </div>
            </td>
        </tr>

        <!-- Other text fields -->

        <tr>
            <th scope="row"><label for="wpi_requires_php">Requires PHP</label></th>
            <td>
                <input type="text" id="wpi_requires_php" name="wpi_requires_php"
                       class="regular-text"
                       value="<?php echo esc_attr( $requires_php ); ?>" />
            </td>
        </tr>

        <tr>
            <th scope="row"><label for="wpi_requires">Requires (WordPress)</label></th>
            <td>
                <input type="text" id="wpi_requires" name="wpi_requires"
                       class="regular-text"
                       value="<?php echo esc_attr( $requires ); ?>" />
            </td>
        </tr>

        <tr>
            <th scope="row"><label for="wpi_tested_upto">Tested up to</label></th>
            <td>
                <input type="text" id="wpi_tested_upto" name="wpi_tested_upto"
                       class="regular-text"
                       value="<?php echo esc_attr( $tested_upto ); ?>" />
            </td>
        </tr>

        <tr>
            <th scope="row"><label for="wpi_author">Author</label></th>
            <td>
                <input type="text" id="wpi_author" name="wpi_author"
                       class="regular-text"
                       value="<?php echo esc_attr( $author ); ?>" />
            </td>
        </tr>

        <tr>
            <th scope="row"><label for="wpi_author_homepage">Author Homepage</label></th>
            <td>
                <input type="text" id="wpi_author_homepage" name="wpi_author_homepage"
                       class="regular-text"
                       value="<?php echo esc_attr( $author_homepage ); ?>" />
            </td>
        </tr>

        <tr>
            <th scope="row"><label for="wpi_item_version">Current Version</label></th>
            <td>
                <input type="text" id="wpi_item_version" name="wpi_item_version"
                       class="regular-text"
                       value="<?php echo esc_attr( $item_version ); ?>" />
                <p class="description">
                    This mirrors the <code>Current Version</code> field in the GetPaid License Manager box.
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row"><label for="wpi_plugin_slug">Plugin Slug</label></th>
            <td>
                <input type="text" id="wpi_plugin_slug" name="wpi_plugin_slug"
                       class="regular-text"
                       value="<?php echo esc_attr( $plugin_slug ); ?>" />
            </td>
        </tr>
        <tr>
    <th scope="row">
        <label for="wpi_sync_latest_download">Sync Downloads with Latest Version</label>
    </th>
    <td>
        <label>
            <input type="checkbox"
                   id="wpi_sync_latest_download"
                   name="wpi_sync_latest_download"
                   value="1"
                   <?php checked( $sync_latest, '1' ); ?> />
            Always serve the latest file for this item to customers.
        </label>
        <p class="description">
            If unchecked, customers keep the specific file that was available when they purchased.
        </p>
    </td>
</tr>


        </tbody>
    </table>

    <h2>Plugin Details (Update API Sections)</h2>

    <table class="form-table">
        <tbody>
        <tr>
            <th scope="row"><label for="wpi_description_editor">Description</label></th>
            <td>
                <?php
                wp_editor(
                    $desc_content,
                    'wpi_description_editor',
                    array(
                        'textarea_name' => 'wpi_description',
                        'textarea_rows' => 6,
                        'media_buttons' => false,
                    )
                );
                ?>
            </td>
        </tr>

        <tr>
            <th scope="row"><label for="wpi_installation_editor">Installation</label></th>
            <td>
                <?php
                wp_editor(
                    $inst_content,
                    'wpi_installation_editor',
                    array(
                        'textarea_name' => 'wpi_installation',
                        'textarea_rows' => 4,
                        'media_buttons' => false,
                    )
                );
                ?>
            </td>
        </tr>

        <tr>
            <th scope="row"><label for="wpi_faq_editor">FAQ</label></th>
            <td>
                <?php
                wp_editor(
                    $faq_content,
                    'wpi_faq_editor',
                    array(
                        'textarea_name' => 'wpi_faq',
                        'textarea_rows' => 6,
                        'media_buttons' => false,
                    )
                );
                ?>
            </td>
        </tr>

        <tr>
            <th scope="row"><label for="wpi_changelog_editor">Changelog</label></th>
            <td>
                <?php
                wp_editor(
                    $chlg_content,
                    'wpi_changelog_editor',
                    array(
                        'textarea_name' => 'wpi_changelog',
                        'textarea_rows' => 8,
                        'media_buttons' => false,
                    )
                );
                ?>
                <p class="description">
                    This will be sent as the <code>sections.changelog</code> HTML in the update API.
                </p>
            </td>
        </tr>
        </tbody>
    </table>

    <!-- Media uploader JS -->
    <script>
    jQuery(document).ready(function($){
        var wpi_frame;

        $('.wpi-media-upload').on('click', function(e){
            e.preventDefault();

            var button  = $(this);
            var target  = $(button.data('target'));   // hidden input (ID)
            var preview = $(button.data('preview'));  // img element

            if (wpi_frame) {
                wpi_frame.targetInput   = target;
                wpi_frame.targetPreview = preview;
                wpi_frame.open();
                return;
            }

            wpi_frame = wp.media({
                title:  'Select image',
                button: { text: 'Use this image' },
                multiple: false
            });

            wpi_frame.on('select', function(){
                var attachment = wpi_frame.state().get('selection').first().toJSON();

                if (wpi_frame.targetInput && wpi_frame.targetPreview) {
                    wpi_frame.targetInput.val(attachment.id);
                    wpi_frame.targetPreview
                        .attr('src', attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url)
                        .show();

                    var field = wpi_frame.targetInput.closest('.wpi-media-field');
                    field.find('.wpi-media-remove').show();
                    field.find('.wpi-media-upload').text('Change image');
                }
            });

            wpi_frame.targetInput   = target;
            wpi_frame.targetPreview = preview;
            wpi_frame.open();
        });

        $('.wpi-media-remove').on('click', function(e){
            e.preventDefault();

            var button  = $(this);
            var target  = $(button.data('target'));
            var preview = $(button.data('preview'));

            target.val('');
            preview.attr('src', '').hide();
            button.hide();

            button.closest('.wpi-media-field')
                  .find('.wpi-media-upload')
                  .text('Select image');
        });
    });
    </script>

    <script>
    jQuery(function($){
        const noteText = <?php echo wp_json_encode( $wpi_has_variations
            ? 'Updater behaviour: downloads are controlled by the selected variation. The order of files in this list does not affect updater delivery.'
            : 'Updater behaviour: drag the file you want served by the updater to the top of this list, then save/update the item.'
        ); ?>;

        const $box = $('#getpaid_item_downloads .inside').first();
        if ($box.length && !$box.find('.wpi-uupd-downloads-help').length) {
            $box.prepend(
                $('<p class="description wpi-uupd-downloads-help"></p>').text(noteText)
            );
        }
    });
    </script>

    <?php if ( $wpi_has_variations ) : ?>
    <script>
    jQuery(function($){
        const savedUpdateDownloads = <?php echo wp_json_encode( $wpi_variation_update_ids ); ?> || {};

        function variationIndex($card) {
            let idx = String($card.attr('data-index') || '');
            if (!idx) {
                const name = String($card.find('input[name^="_wpinv_item_variations["]').first().attr('name') || '');
                const match = name.match(/_wpinv_item_variations\[([^\]]+)\]/);
                if (match) idx = match[1];
            }
            return idx;
        }

        function fileLabel($checkbox) {
            const clone = $checkbox.closest('label').clone();
            clone.find('input,.wpi-uupd-variation-update-badge').remove();
            return $.trim(clone.text()) || ('Download #' + $checkbox.val());
        }

        function downloadCheckboxes($card) {
            // Avoid CSS attribute selector edge-cases with square brackets in the name.
            return $card.find('.getpaid-pkg-downloads-list input[type="checkbox"]').filter(function(){
                return String($(this).attr('name') || '').indexOf('[download_ids]') !== -1;
            });
        }

        function selectedCheckboxes($card) {
            return downloadCheckboxes($card).filter(':checked');
        }

        function allCheckboxes($card) {
            return downloadCheckboxes($card);
        }

        function ensureUpdateField($card) {
            const idx = variationIndex($card);
            if (idx === '') return $();

            let $wrap = $card.find('.wpi-uupd-variation-update-file').first();
            if (!$wrap.length) {
                $wrap = $(
                    '<div class="wpi-uupd-variation-update-file mt-2 pt-2">' +
                        '<label class="d-flex flex-wrap align-items-center gap-1 mb-1">' +
                            '<strong>Update file</strong>' +
                            '<select class="form-select form-select-sm w-auto wpi-uupd-variation-update-select"></select>' +
                        '</label>' +
                        '<p class="description mb-0">This is the single file served by plugin updates for this variation. Customer download links can still show all selected files.</p>' +
                    '</div>'
                );
                const $downloadsRow = $card.find('.getpaid-pkg-downloads-row').first();
                if ($downloadsRow.length) {
                    $downloadsRow.append($wrap);
                }
            }

            const variationId = String($card.find('.getpaid-pkg-id').first().val() || idx);
            const $select = $wrap.find('select').first();
            $select.attr('name', '_wpinv_item_variations[' + idx + '][update_download_id]');

            let $hidden = $wrap.find('.wpi-uupd-variation-update-hidden').first();
            if (!$hidden.length) {
                $hidden = $('<input type="hidden" class="wpi-uupd-variation-update-hidden">');
                $wrap.append($hidden);
            }
            $hidden.attr('name', '_wpi_uupd_variation_update_download_ids[' + variationId + ']');
            return $wrap;
        }

        function rebuildVariationUpdateField($card, preferredValue) {
            const idx = variationIndex($card);
            const $wrap = ensureUpdateField($card);
            if (!$wrap.length) return;

            const $select = $wrap.find('select').first();
            const previous = preferredValue || $select.val() || savedUpdateDownloads[idx] || '';
            const $checked = selectedCheckboxes($card);

            $select.empty();

            if (!$checked.length) {
                $select.append($('<option value=""></option>').text('No downloadable file selected'));
                markVariationUpdateBadge($card, '');
                return;
            }

            $checked.each(function(){
                const $cb = $(this);
                $select.append(
                    $('<option></option>')
                        .val(String($cb.val()))
                        .text(fileLabel($cb))
                );
            });

            const values = $checked.map(function(){ return String($(this).val()); }).get();
            const selected = values.indexOf(String(previous)) !== -1 ? String(previous) : String(values[0]);
            $select.val(selected);
            $wrap.find('.wpi-uupd-variation-update-hidden').val(selected);
            markVariationUpdateBadge($card, selected);
        }

        function markVariationUpdateBadge($card, selectedValue) {
            $card.find('.wpi-uupd-variation-update-badge').remove();
            if (!selectedValue) return;

            allCheckboxes($card).each(function(){
                const $cb = $(this);
                if (String($cb.val()) === String(selectedValue)) {
                    $cb.closest('label').append(' <span class="wpi-uupd-variation-update-badge">Updater file</span>');
                    return false;
                }
            });
        }

        function initVariationUpdateFields() {
            $('.getpaid-variation-card').each(function(){
                rebuildVariationUpdateField($(this));
            });
        }

        initVariationUpdateFields();
        setTimeout(initVariationUpdateFields, 250);
        $(window).on('load', function(){ initVariationUpdateFields(); });

        $('#getpaid-variations-list').on('change', '.getpaid-pkg-downloads-list input[type="checkbox"]', function(){
            if (String($(this).attr('name') || '').indexOf('[download_ids]') === -1) {
                return;
            }
            rebuildVariationUpdateField($(this).closest('.getpaid-variation-card'));
        });

        $('#getpaid-variations-list').on('change', '.wpi-uupd-variation-update-select', function(){
            const $card = $(this).closest('.getpaid-variation-card');
            const selected = $(this).val() || '';
            $card.find('.wpi-uupd-variation-update-hidden').first().val(selected);
            markVariationUpdateBadge($card, selected);
        });

        $('#getpaid-add-variation').on('click', function(){
            setTimeout(initVariationUpdateFields, 150);
        });

        const list = document.getElementById('getpaid-variations-list');
        if (list && window.MutationObserver) {
            const observer = new MutationObserver(function(){
                initVariationUpdateFields();
            });
            observer.observe(list, { childList: true, subtree: false });
        }
    });
    </script>
    <?php endif; ?>

    <?php if ( $wpi_has_variations ) : ?>
    <style>
        .wpi-uupd-variation-update-file {
            border-top: 1px solid #dcdcde;
        }
        .wpi-uupd-variation-update-badge {
            display: inline-block;
            margin-left: 6px;
            padding: 2px 7px;
            border-radius: 999px;
            background: #2271b1;
            color: #fff;
            font-size: 11px;
            line-height: 1.6;
            font-weight: 600;
        }
    </style>
    <?php endif; ?>

    <?php if ( ! $wpi_has_variations ) : ?>
    <script>
    jQuery(function($){
        const currentFileId = '<?php echo esc_js( (string) $wpi_current_file_id ); ?>';

        function rowFileId($row) {
            let id = '';
            const $hidden = $row.find('input[type="hidden"][name*="[file_id]"]').first();
            if ($hidden.length && $hidden.val()) {
                id = $hidden.val();
            }
            if (!id) {
                const name = ($row.find('.getpaid-file-name').attr('name') || '') + '';
                const match = name.match(/_getpaid_item_downloads\[([^\]]+)\]/);
                if (match) id = match[1];
            }
            return id;
        }

        function ensureBadge($row) {
            let $badge = $row.find('.wpi-current-update-badge');
            if (!$badge.length) {
                $badge = $('<span class="wpi-current-update-badge">Current update</span>');
                $row.find('.file_name').append($badge);
            }
            return $badge;
        }

        function getTopRow() {
            return $('#getpaid_item_downloads tbody tr:visible').first();
        }

        function updateCurrentCardFromRow($row, pending) {
            if (!$row || !$row.length) return;

            const name = $row.find('.getpaid-file-name').val() || 'No file name set';
            const url  = $row.find('.getpaid-file-url').val() || 'No file URL set';
            const id   = rowFileId($row);

            $('.wpi-current-update-file-name').text(name + (pending ? ' (after save)' : ''));
            $('.wpi-current-update-file-url').text(url);
            $('.wpi-current-update-file-card').attr('data-current-file-id', id);
        }

        function markServedRow() {
            const $rows = $('#getpaid_item_downloads tbody tr');
            $rows.removeClass('wpi-current-update-row wpi-current-update-row-pending')
                 .find('.wpi-current-update-badge').remove();

            let $saved = $();
            if (currentFileId) {
                $rows.each(function(){
                    const $row = $(this);
                    if (rowFileId($row) === currentFileId) {
                        $saved = $row;
                        return false;
                    }
                });
            }

            if ($saved.length) {
                $saved.addClass('wpi-current-update-row');
                ensureBadge($saved);
            }

            const $top = getTopRow();
            if ($top.length) {
                if (!$saved.length || rowFileId($top) !== rowFileId($saved)) {
                    $top.addClass('wpi-current-update-row-pending');
                    ensureBadge($top).text('Current after save');
                    updateCurrentCardFromRow($top, true);
                }
            }
        }

        markServedRow();

        $('#getpaid_item_downloads tbody').on('sortupdate sortstop', function(){
            setTimeout(markServedRow, 50);
        });

        $('#getpaid_item_downloads').on('change keyup', '.getpaid-file-name,.getpaid-file-url', function(){
            const $top = getTopRow();
            updateCurrentCardFromRow($top, rowFileId($top) !== currentFileId);
        });

        $('#getpaid_item_downloads').on('click', '.getpaid-add-download-file,.wpinv-remove-downloadable-file,.upload_file_button', function(){
            setTimeout(markServedRow, 350);
        });
    });
    </script>

    <style>
        .wpi-current-update-file-card {
            max-width: 760px;
            padding: 10px 12px;
            border-left: 4px solid #2271b1;
            background: #f6f7f7;
        }
        .wpi-uupd-downloads-help {
            margin: 0 0 10px;
            padding: 8px 10px;
            background: #f6f7f7;
            border: 1px solid #dcdcde;
        }
        .wpi-current-update-badge {
            display: inline-block;
            margin: 6px 0 0 0;
            padding: 2px 7px;
            border-radius: 999px;
            background: #2271b1;
            color: #fff;
            font-size: 11px;
            line-height: 1.6;
            font-weight: 600;
        }
        .wpi-current-update-row-pending .wpi-current-update-badge {
            background: #dba617;
            color: #1d2327;
        }
    </style>
    <?php endif; ?>
    <?php
}

/**
 * Save handler
 */
add_action( 'save_post_wpi_item', 'wpi_item_save_meta_box_data', 10 );
function wpi_item_save_meta_box_data( $post_id ) {

    // Security checks
    if ( ! isset( $_POST['wpi_item_meta_box_nonce'] ) ) {
        return;
    }
    if ( ! wp_verify_nonce( $_POST['wpi_item_meta_box_nonce'], 'wpi_item_meta_box' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Auto update last-updated (MySQL datetime in site timezone)
    $current_datetime = current_time( 'mysql' );
    update_post_meta( $post_id, '_wpi_last_updated', $current_datetime );

    // MEDIA IDs (attachment IDs)
    $media_id_fields = array(
        'wpi_banner_low_id'  => '_wpi_banner_low_id',
        'wpi_banner_high_id' => '_wpi_banner_high_id',
        'wpi_icon_1x_id'     => '_wpi_icon_1x_id',
        'wpi_icon_2x_id'     => '_wpi_icon_2x_id',
    );

    foreach ( $media_id_fields as $field_name => $meta_key ) {
        if ( isset( $_POST[ $field_name ] ) ) {
            $id = absint( $_POST[ $field_name ] );
            if ( $id ) {
                update_post_meta( $post_id, $meta_key, $id );
            } else {
                delete_post_meta( $post_id, $meta_key );
            }
        }
    }

    // TYPE / URL fields
    $url_fields = array(
        'wpi_banner_low_url'  => '_wpi_banner_low_url',
        'wpi_banner_high_url' => '_wpi_banner_high_url',
        'wpi_icon_1x_url'     => '_wpi_icon_1x_url',
        'wpi_icon_2x_url'     => '_wpi_icon_2x_url',
    );

    foreach ( $url_fields as $field_name => $meta_key ) {
        if ( isset( $_POST[ $field_name ] ) ) {
            $value = esc_url_raw( $_POST[ $field_name ] );
            if ( $value !== '' ) {
                update_post_meta( $post_id, $meta_key, $value );
            } else {
                delete_post_meta( $post_id, $meta_key );
            }
        }
    }

    // Other simple text fields
    $text_fields = array(
        'wpi_requires_php'    => '_wpi_requires_php',
        'wpi_requires'        => '_wpi_requires',
        'wpi_tested_upto'     => '_wpi_tested_upto',
        'wpi_author'          => '_wpi_author',
        'wpi_author_homepage' => '_wpi_author_homepage',
        'wpi_item_version'    => 'getpaid_license_manager_item_version',
        'wpi_plugin_slug'     => '_wpi_plugin_slug',
    );

    foreach ( $text_fields as $field_name => $meta_key ) {
        if ( isset( $_POST[ $field_name ] ) ) {
            $value = sanitize_text_field( $_POST[ $field_name ] );
            if ( $value !== '' ) {
                update_post_meta( $post_id, $meta_key, $value );
            } else {
                delete_post_meta( $post_id, $meta_key );
            }
        }
    }

    // WYSIWYG fields (allow HTML, but clean)
    $wysiwyg_fields = array(
        'wpi_description'  => 'getpaid_license_manager_description',
        'wpi_installation' => 'getpaid_license_manager_installation',
        'wpi_faq'          => 'getpaid_license_manager_faq',
        'wpi_changelog'    => 'getpaid_license_manager_changelog',
    );

    foreach ( $wysiwyg_fields as $field_name => $meta_key ) {
        if ( isset( $_POST[ $field_name ] ) ) {
            $value = wp_kses_post( $_POST[ $field_name ] );
            if ( $value !== '' ) {
                update_post_meta( $post_id, $meta_key, $value );
            } else {
                delete_post_meta( $post_id, $meta_key );
            }
        }
    }


    // Sync downloads with latest version (checkbox).
    if ( isset( $_POST['wpi_sync_latest_download'] ) && '1' === $_POST['wpi_sync_latest_download'] ) {
        update_post_meta( $post_id, '_wpi_sync_latest_download', '1' );
    } else {
        delete_post_meta( $post_id, '_wpi_sync_latest_download' );
    }


}

/**
 * Preserve UUPD's per-variation updater file selection inside GetPaid's
 * _wpinv_item_variations meta.
 *
 * GetPaid owns the variation save process. We merge only our small
 * update_download_id field back into the saved variation rows after the normal
 * save has run, matching by current variation index.
 */
add_action( 'save_post_wpi_item', 'wpi_uupd_meta_save_variation_update_download_ids', 99999 );
function wpi_uupd_meta_save_variation_update_download_ids( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $posted_map = array();

    // Preferred posted field, keyed by stable variation ID.
    if ( ! empty( $_POST['_wpi_uupd_variation_update_download_ids'] ) && is_array( $_POST['_wpi_uupd_variation_update_download_ids'] ) ) {
        $raw_map = wp_unslash( $_POST['_wpi_uupd_variation_update_download_ids'] );
        foreach ( $raw_map as $variation_id => $downloadable_item_id ) {
            $variation_id = sanitize_key( (string) $variation_id );
            $downloadable_item_id = absint( $downloadable_item_id );
            if ( $variation_id && $downloadable_item_id ) {
                $posted_map[ $variation_id ] = (string) $downloadable_item_id;
            }
        }
    }

    // Backstop: the select also posts inside GetPaid's variation array.
    $posted_variations = array();
    if ( ! empty( $_POST['_wpinv_item_variations'] ) && is_array( $_POST['_wpinv_item_variations'] ) ) {
        $posted_variations = wp_unslash( $_POST['_wpinv_item_variations'] );
        foreach ( $posted_variations as $posted_variation ) {
            if ( ! is_array( $posted_variation ) ) {
                continue;
            }
            $variation_id = ! empty( $posted_variation['id'] ) ? sanitize_key( (string) $posted_variation['id'] ) : '';
            $downloadable_item_id = ! empty( $posted_variation['update_download_id'] ) ? absint( $posted_variation['update_download_id'] ) : 0;
            if ( $variation_id && $downloadable_item_id && empty( $posted_map[ $variation_id ] ) ) {
                $posted_map[ $variation_id ] = (string) $downloadable_item_id;
            }
        }
    }

    if ( empty( $posted_map ) ) {
        delete_post_meta( $post_id, '_wpi_uupd_variation_update_download_ids' );
        return;
    }

    // Validate selections against the currently saved variation download_ids.
    $saved_variations = get_post_meta( $post_id, '_wpinv_item_variations', true );
    $saved_variations = maybe_unserialize( $saved_variations );
    if ( ! is_array( $saved_variations ) ) {
        return;
    }

    $valid_map = array();
    $changed_variations = false;

    foreach ( $saved_variations as $idx => $variation ) {
        if ( ! is_array( $variation ) ) {
            continue;
        }

        $variation_id = ! empty( $variation['id'] ) ? sanitize_key( (string) $variation['id'] ) : '';
        if ( ! $variation_id || empty( $posted_map[ $variation_id ] ) ) {
            if ( isset( $saved_variations[ $idx ]['update_download_id'] ) ) {
                unset( $saved_variations[ $idx ]['update_download_id'] );
                $changed_variations = true;
            }
            continue;
        }

        $download_ids = ! empty( $variation['download_ids'] ) && is_array( $variation['download_ids'] ) ? array_map( 'absint', $variation['download_ids'] ) : array();
        $selected_update_id = absint( $posted_map[ $variation_id ] );

        if ( $selected_update_id && in_array( $selected_update_id, $download_ids, true ) ) {
            $valid_map[ $variation_id ] = (string) $selected_update_id;

            // Also embed for readability/back-compat, but the separate map is the source of truth.
            if ( empty( $variation['update_download_id'] ) || (string) $variation['update_download_id'] !== (string) $selected_update_id ) {
                $saved_variations[ $idx ]['update_download_id'] = (string) $selected_update_id;
                $changed_variations = true;
            }
        } elseif ( isset( $saved_variations[ $idx ]['update_download_id'] ) ) {
            unset( $saved_variations[ $idx ]['update_download_id'] );
            $changed_variations = true;
        }
    }

    if ( empty( $valid_map ) ) {
        delete_post_meta( $post_id, '_wpi_uupd_variation_update_download_ids' );
    } else {
        update_post_meta( $post_id, '_wpi_uupd_variation_update_download_ids', $valid_map );
    }

    if ( $changed_variations ) {
        update_post_meta( $post_id, '_wpinv_item_variations', $saved_variations );
    }
}

/**
 * Refresh the displayed current-update-file pointer after GetPaid has saved its
 * Downloadable Files meta/table rows. This is admin diagnostic meta only.
 */
add_action( 'save_post_wpi_item', 'wpi_uupd_meta_refresh_current_update_file_after_save', 1000 );
function wpi_uupd_meta_refresh_current_update_file_after_save( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    wpi_uupd_meta_update_current_update_file_meta( $post_id );
}
