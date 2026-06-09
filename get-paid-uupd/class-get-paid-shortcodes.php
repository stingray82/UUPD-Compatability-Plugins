<?php 

/* -------------------------------------------------------------------------
 * Shortcode: combined list – [my_getpaid_licenses_downloads]
 * ---------------------------------------------------------------------- */

/**
 * [my_getpaid_licenses_downloads details_page="/licence2/" per_page="10"]
 *
 * Combined view: for each item, show licenses + downloads.
 *
 * Attributes:
 *  - details_page: URL of the license details page (where [my_getpaid_license_details] lives)
 *                  If omitted, defaults to the current page.
 *  - per_page:     Optional. Number of items (products) per page. 0 or empty = no pagination.
 *                  Pagination uses ?lpaged=<page>.
 */
function my_getpaid_licenses_downloads_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view your licenses and downloads.</p>';
    }

    $atts = shortcode_atts(
        array(
            'details_page'        => '',
            'per_page'            => 0,
            'downloads_per_page'  => 10,
        ),
        $atts,
        'my_getpaid_licenses_downloads'
    );

    $user_id           = get_current_user_id();
    $licenses_by_item  = my_gpd_get_user_licenses_grouped_by_item( $user_id );

    if ( empty( $licenses_by_item ) ) {
        return '<p>No licensed products found for your account.</p>';
    }

    $permissions_map = my_gpd_get_permissions_map_for_user( $user_id );
    $details_base    = $atts['details_page'] ? esc_url_raw( $atts['details_page'] ) : get_permalink();

    // ------------------------------------------------------------------
    // Pagination by item (product)
    // ------------------------------------------------------------------
    $per_page = (int) $atts['per_page'];
    $item_ids = array_keys( $licenses_by_item );
    $total_items = count( $item_ids );
    $max_pages   = 1;
    $current_page = isset( $_GET['lpaged'] ) ? max( 1, (int) $_GET['lpaged'] ) : 1;

    if ( $per_page > 0 && $total_items > $per_page ) {
        $max_pages = (int) ceil( $total_items / $per_page );
        if ( $current_page > $max_pages ) {
            $current_page = $max_pages;
        }

        $offset          = ( $current_page - 1 ) * $per_page;
        $paged_item_ids  = array_slice( $item_ids, $offset, $per_page );
    } else {
        // No pagination, show all
        $paged_item_ids = $item_ids;
    }

    // Base URL for pagination links (keep other query args, drop lpaged).
    $pagination_base_url = remove_query_arg( 'lpaged' );

    ob_start();
    ?>
    <div class="gpd-account gpd-licenses-downloads">
        <?php foreach ( $paged_item_ids as $item_id ) : 
            $licenses_for_item = $licenses_by_item[ $item_id ];

            $item_post = get_post( $item_id );
            if ( ! $item_post || $item_post->post_type !== 'wpi_item' ) {
                continue;
            }

            $item_title = get_the_title( $item_post );
            $downloads  = function_exists( 'my_gpd_get_shortcode_download_archive_for_user_item' )
                ? my_gpd_get_shortcode_download_archive_for_user_item( $user_id, $item_id, $licenses_for_item )
                : ( function_exists( 'my_gpd_get_allowed_downloads_for_user_item' )
                    ? my_gpd_get_allowed_downloads_for_user_item( $user_id, $item_id, $permissions_map )
                    : array() );

            $downloads_per_page = isset( $atts['downloads_per_page'] ) ? absint( $atts['downloads_per_page'] ) : 10;
            $download_page_key  = 'dlpaged_' . (int) $item_id;
            $download_page      = isset( $_GET[ $download_page_key ] ) ? max( 1, absint( $_GET[ $download_page_key ] ) ) : 1;
            $download_total     = is_array( $downloads ) ? count( $downloads ) : 0;
            $download_max_pages = 1;

            if ( $downloads_per_page > 0 && $download_total > $downloads_per_page ) {
                $download_max_pages = (int) ceil( $download_total / $downloads_per_page );
                if ( $download_page > $download_max_pages ) {
                    $download_page = $download_max_pages;
                }
                $download_offset = ( $download_page - 1 ) * $downloads_per_page;
                $downloads       = array_slice( $downloads, $download_offset, $downloads_per_page );
            }
            ?>
            <section class="gpd-card gpd-product-card">
                <header class="gpd-card__header">
                    <h2 class="gpd-product-card__title"><?php echo esc_html( $item_title ); ?></h2>
                </header>

                <div class="gpd-product-card__body">
                    <div class="gpd-product-card__column gpd-product-card__column--licenses">
                        <h3 class="gpd-section-title">Licenses</h3>
                        <ul class="gpd-list gpd-list--licenses">
                            <?php foreach ( $licenses_for_item as $lic ) :
                                $status       = $lic->status;
                                $status_label = ucfirst( $status );
                                $license_id   = (int) $lic->id;
                                $max          = (int) $lic->max_activations;
                                $current      = (int) $lic->activations;
                                $activations_label = $max > 0
                                    ? sprintf( '%d / %d', $current, $max )
                                    : sprintf( '%d / ∞', $current );

                                $details_url  = esc_url(
                                    add_query_arg(
                                        'license',
                                        $license_id,
                                        $details_base
                                    )
                                );
                                ?>
                                <li class="gpd-list__item gpd-license gpd-license--<?php echo esc_attr( $status ); ?>">
                                    <div class="gpd-license__row">
                                        <code class="gpd-pill gpd-license__key gpd-copy-on-click"
                                              data-gpd-copy="<?php echo esc_attr( $lic->license_key ); ?>">
                                            <?php echo esc_html( $lic->license_key ); ?>
                                        </code>
                                        <span class="gpd-badge gpd-badge--status">
                                            <?php echo esc_html( $status_label ); ?>
                                        </span>
                                    </div>
                                    <div class="gpd-license__meta">
                                        <span class="gpd-license__activations">
                                            Activations: <?php echo esc_html( $activations_label ); ?>
                                        </span>
                                        <a class="gpd-link gpd-license__details-link" href="<?php echo $details_url; ?>">
                                            View details
                                        </a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="gpd-product-card__column gpd-product-card__column--downloads">
                        <h3 class="gpd-section-title">Downloads</h3>
                        <?php if ( ! empty( $downloads ) && is_array( $downloads ) ) : ?>
                            <ul class="gpd-list gpd-list--downloads">
                                <?php foreach ( $downloads as $download ) :
                                    $url             = isset( $download['url'] ) ? $download['url'] : '';
                                    $name            = isset( $download['name'] ) ? $download['name'] : __( 'Download', 'getpaid' );
                                    $variation_label = ! empty( $download['variation_label'] ) ? $download['variation_label'] : '';
                                    if ( ! $url ) {
                                        continue;
                                    }
                                    ?>
                                    <li class="gpd-list__item gpd-download">
                                        <span class="gpd-download__name">
                                            <?php echo esc_html( $name ); ?>
                                            <?php if ( $variation_label ) : ?>
                                                <small class="gpd-download__variation">&mdash; <?php echo esc_html( sprintf( __( 'Variation: %s', 'getpaid' ), $variation_label ) ); ?></small>
                                            <?php endif; ?>
                                        </span>
                                        <a class="gpd-button gpd-button--primary gpd-download__button"
                                           href="<?php echo esc_url( $url ); ?>">
                                            Download
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ( $downloads_per_page > 0 && $download_max_pages > 1 ) : ?>
                                <nav class="gpd-pagination gpd-pagination--downloads" aria-label="Downloads pagination">
                                    <?php if ( $download_page > 1 ) : ?>
                                        <a class="gpd-pagination__link gpd-pagination__link--prev"
                                           href="<?php echo esc_url( add_query_arg( $download_page_key, $download_page - 1 ) ); ?>">
                                            &laquo; Previous downloads
                                        </a>
                                    <?php endif; ?>

                                    <span class="gpd-pagination__link gpd-pagination__link--current">
                                        <?php echo esc_html( sprintf( __( 'Downloads page %1$d of %2$d', 'getpaid' ), $download_page, $download_max_pages ) ); ?>
                                    </span>

                                    <?php if ( $download_page < $download_max_pages ) : ?>
                                        <a class="gpd-pagination__link gpd-pagination__link--next"
                                           href="<?php echo esc_url( add_query_arg( $download_page_key, $download_page + 1 ) ); ?>">
                                            More downloads &raquo;
                                        </a>
                                    <?php endif; ?>
                                </nav>
                            <?php endif; ?>
                        <?php else : ?>
                            <p class="gpd-empty">No downloads are currently available for this product.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>

        <?php if ( $per_page > 0 && $max_pages > 1 ) : ?>
            <nav class="gpd-pagination" aria-label="Licenses pagination">
                <?php if ( $current_page > 1 ) : ?>
                    <a class="gpd-pagination__link gpd-pagination__link--prev"
                       href="<?php echo esc_url( add_query_arg( 'lpaged', $current_page - 1, $pagination_base_url ) ); ?>">
                        &laquo; Previous
                    </a>
                <?php endif; ?>

                <?php for ( $i = 1; $i <= $max_pages; $i++ ) : ?>
                    <?php if ( $i === $current_page ) : ?>
                        <span class="gpd-pagination__link gpd-pagination__link--current"><?php echo $i; ?></span>
                    <?php else : ?>
                        <a class="gpd-pagination__link"
                           href="<?php echo esc_url( add_query_arg( 'lpaged', $i, $pagination_base_url ) ); ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ( $current_page < $max_pages ) : ?>
                    <a class="gpd-pagination__link gpd-pagination__link--next"
                       href="<?php echo esc_url( add_query_arg( 'lpaged', $current_page + 1, $pagination_base_url ) ); ?>">
                        Next &raquo;
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>

    <?php
    // Click-to-copy handler for license keys (no button).
    if ( ! defined( 'MY_GPD_COPY_SCRIPT_PRINTED' ) ) :
        define( 'MY_GPD_COPY_SCRIPT_PRINTED', true );
        ?>
        <script>
        document.addEventListener('click', function (e) {
            var pill = e.target.closest('.gpd-copy-on-click');
            if (!pill) return;
            var key = pill.getAttribute('data-gpd-copy');
            if (!key || !navigator.clipboard) return;

            var originalText = pill.textContent;
            navigator.clipboard.writeText(key).then(function () {
                pill.textContent = 'Copied!';
                setTimeout(function () {
                    pill.textContent = originalText;
                }, 1500);
            }).catch(function () {/* ignore */});
        });
        </script>
    <?php
    endif;

    $html = ob_get_clean();

    /**
     * Filter: my_getpaid_licenses_downloads_html
     *
     * Allows full override/branding of the combined view.
     */
    return apply_filters( 'my_getpaid_licenses_downloads_html', $html, $licenses_by_item, $user_id, $atts );
}
add_shortcode( 'my_getpaid_licenses_downloads', 'my_getpaid_licenses_downloads_shortcode' );


/* -------------------------------------------------------------------------
 * Shortcode: details view – [my_getpaid_license_details]
 * ---------------------------------------------------------------------- */

/**
 * [my_getpaid_license_details]
 *
 * Detail view for a single license.
 *
 * URL params:
 *   ?license=1              (license ID)
 *   or ?license_key=abc123  (license key)
 *
 * Shortcode attributes:
 *   quiet_if_missing (string) "yes" or "no" (default "no")
 *       - If "yes" and NO license/license_key param is present,
 *         the shortcode returns an empty string instead of
 *         "License not found for your account."
 *
 * Output:
 *   - Page title: "License Details – {Item Name}"
 *   - Item name
 *   - License key, status, expiry, invoice number, purchase date
 *   - Activations current / max
 *   - Download names for the licensed item (if any)
 *
 * Example:
 *   [my_getpaid_license_details quiet_if_missing="yes"]
 */
function my_getpaid_license_details_shortcode( $atts ) {

    // Shortcode attributes.
    $atts = shortcode_atts(
        array(
            'quiet_if_missing' => 'no',
        ),
        $atts,
        'my_getpaid_license_details'
    );

    $quiet_if_missing = in_array(
        strtolower( (string) $atts['quiet_if_missing'] ),
        array( 'yes', 'true', '1' ),
        true
    );

    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view license details.</p>';
    }

    global $wpdb;
    $tables  = my_gpd_tables();
    $user_id = get_current_user_id();
    $license = null;

    // Params: ?license=ID or ?license_key=KEY
    $license_id  = isset( $_GET['license'] ) ? (int) $_GET['license'] : 0;
    $license_key = isset( $_GET['license_key'] ) ? sanitize_text_field( wp_unslash( $_GET['license_key'] ) ) : '';
    $has_param   = ( $license_id || $license_key );

    // Fetch license row *without* user filter first.
    if ( $license_id ) {
        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tables['licenses']} WHERE id = %d",
                $license_id
            )
        );
    } elseif ( $license_key ) {
        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tables['licenses']} WHERE license_key = %s",
                $license_key
            )
        );
    }

    // Now enforce that it belongs to current user.
    if ( ! $license || (int) $license->user_id !== $user_id ) {

        // If there is NO param and we're allowed to be quiet, show nothing.
        if ( ! $has_param && $quiet_if_missing ) {
            return '';
        }

        // Otherwise, show the standard "not found" message.
        return '<p>License not found for your account.</p>';
    }

    // ------------------------------------------------------------------
    // Item & invoice info
    // ------------------------------------------------------------------
    $item_post = get_post( (int) $license->item_id );
    $item_name = $item_post ? get_the_title( $item_post ) : __( 'Unknown Item', 'getpaid' );

    $invoice = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT inv.*, p.post_date
             FROM {$tables['invoices']} AS inv
             INNER JOIN {$wpdb->posts} AS p ON inv.post_id = p.ID
             WHERE inv.post_id = %d",
            (int) $license->invoice_id
        )
    );

    $invoice_number = ( $invoice && ! empty( $invoice->number ) ) ? $invoice->number : '';
    $purchase_date  = $invoice ? mysql2date( get_option( 'date_format' ), $invoice->post_date ) : '';

    // ------------------------------------------------------------------
    // Status, expiry, activations
    // ------------------------------------------------------------------
    $status       = $license->status;
    $status_label = ucfirst( $status );

    $current_act = (int) $license->activations;
    $max_act     = (int) $license->max_activations;
    $act_label   = $max_act > 0 ? sprintf( '%d / %d', $current_act, $max_act ) : sprintf( '%d / ∞', $current_act );

    $expiry = ( '0000-00-00 00:00:00' === $license->date_expires || ! $license->date_expires )
        ? __( 'Never', 'getpaid' )
        : mysql2date( get_option( 'date_format' ), $license->date_expires );

        
    // ------------------------------------------------------------------
    // Download names for this item (permissions-aware). Use the permissions
    // table plus _getpaid_downloadable_items instead of _getpaid_item_downloads,
    // so variation and non-variation products both display the actual allowed files.
    // ------------------------------------------------------------------
    $download_names = array();

    $permissions_map = array();
    if ( function_exists( 'my_gpd_get_permissions_map_for_user' ) ) {
        $permissions_map = my_gpd_get_permissions_map_for_user( $user_id );
    }

    $item_id = (int) $license->item_id;
    $allowed_downloads_for_label = function_exists( 'my_gpd_get_allowed_downloads_for_user_item' )
        ? my_gpd_get_allowed_downloads_for_user_item( $user_id, $item_id, $permissions_map )
        : array();

    foreach ( $allowed_downloads_for_label as $download ) {
        if ( ! empty( $download['name'] ) ) {
            $download_names[] = $download['name'];
        }
    }

    if ( ! empty( $download_names ) ) {
        $download_names  = array_unique( $download_names );
        $downloads_label = implode( ', ', array_map( 'esc_html', $download_names ) );
    } else {
        $downloads_label = '&mdash;';
    }



    $activations = my_gpd_get_license_activations( $license );
    $base_url    = get_permalink();

    ob_start();
    ?>
    <div class="gpd-account gpd-license-details">
        <section class="gpd-card gpd-license-summary">
            <header class="gpd-card__header">
                <h1 class="gpd-page-title">
                    License Details<?php if ( $item_name ) : ?> – <?php echo esc_html( $item_name ); ?><?php endif; ?>
                </h1>
            </header>
            <div class="gpd-card__body">
                <dl class="gpd-definition-list">

                    <div class="gpd-definition-list__row">
                        <dt>Item</dt>
                        <dd><?php echo esc_html( $item_name ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Downloads</dt>
                        <dd><?php echo $downloads_label; // already escaped or &mdash; ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>License Key</dt>
                        <dd class="gpd-license-key-cell">
                            <code class="gpd-pill gpd-pill--wide"><?php echo esc_html( $license->license_key ); ?></code>
                            <button type="button"
                                    class="gpd-button gpd-button--ghost gpd-copy-btn"
                                    data-gpd-copy="<?php echo esc_attr( $license->license_key ); ?>">
                                Copy
                            </button>
                        </dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Status</dt>
                        <dd><span class="gpd-badge gpd-badge--status"><?php echo esc_html( $status_label ); ?></span></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Expiry Date</dt>
                        <dd><?php echo esc_html( $expiry ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Invoice</dt>
                        <dd><?php echo $invoice_number ? esc_html( $invoice_number ) : '&mdash;'; ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Purchase Date</dt>
                        <dd><?php echo $purchase_date ? esc_html( $purchase_date ) : '&mdash;'; ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Activations</dt>
                        <dd><?php echo esc_html( $act_label ); ?></dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="gpd-card gpd-license-activations">
            <header class="gpd-card__header">
                <h2 class="gpd-section-title">Activations</h2>
            </header>
            <div class="gpd-card__body">
                <?php if ( empty( $activations ) ) : ?>
                    <p class="gpd-empty">No activations yet.</p>
                <?php else : ?>
                    <ul class="gpd-list gpd-list--activations">
                        <?php foreach ( $activations as $domain => $timestamp ) :
                            $date = $timestamp ? mysql2date( get_option( 'date_format' ), $timestamp ) : '';
                            $remove_url = wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'gpd_remove_activation' => rawurlencode( $domain ),
                                        'license'               => (int) $license->id,
                                    ),
                                    $base_url
                                ),
                                'gpd_remove_activation_' . $license->id . '_' . $domain
                            );
                            ?>
                            <li class="gpd-list__item gpd-activation">
                                <div class="gpd-activation__info">
                                    <span class="gpd-activation__domain"><?php echo esc_html( $domain ); ?></span>
                                    <?php if ( $date ) : ?>
                                        <span class="gpd-activation__date">— <?php echo esc_html( $date ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <a class="gpd-link gpd-link--danger gpd-activation__remove"
                                   href="<?php echo esc_url( $remove_url ); ?>"
                                   onclick="return confirm('Remove this activation?');">
                                    Remove
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <script>
    // Simple click-to-copy handler for license key buttons on this page.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.gpd-copy-btn');
        if (!btn) return;
        var key = btn.getAttribute('data-gpd-copy');
        if (!key || !navigator.clipboard) {
            return;
        }
        var originalText = btn.textContent;
        navigator.clipboard.writeText(key).then(function () {
            btn.textContent = 'Copied!';
            setTimeout(function () {
                btn.textContent = originalText;
            }, 1500);
        }).catch(function () {
            // swallow
        });
    });
    </script>
    <?php

    $html = ob_get_clean();

    return apply_filters( 'my_getpaid_license_details_html', $html, $license, $activations );
}
add_shortcode( 'my_getpaid_license_details', 'my_getpaid_license_details_shortcode' );



/**
 * [my_getpaid_purchases]
 *
 * Shows a table of license-related purchases for the current user.
 *
 * Attributes:
 *   confirmation_page (string) URL or path for the confirmation page
 *                      (expects ?invoice_key=KEY; page has the confirmation shortcode)
 *   invoice_page      (string) URL or path for the invoice page
 *                      (expects ?invoice_key=KEY; page has invoice shortcode)
 *   licenses_page     (string) URL or path for the license-details page
 *                      (expects ?license=ID; page has license shortcode)
 *
 * Example:
 *   [my_getpaid_purchases
 *       confirmation_page="/confirmation/"
 *       invoice_page="/invoice/"
 *       licenses_page="/my-licenses/"]
 */
 /**
 * [my_getpaid_purchases confirmation_page="/confirmation/"
 *                        invoice_page="/invoice/"
 *                        licenses_page="/my-licenses/"]
 *
 * Shows a table of purchases for the current user:
 *   - Date
 *   - Amount
 *   - Details and Downloads (confirmation page)
 *   - View Invoice
 *   - View Licenses
 *
 * All three URLs are overridable via shortcode attributes and via a filter.
 */
 /**
 * [my_getpaid_purchases
 *     confirmation_page="/my-downloads/"
 *     invoice_page="/"
 *     licenses_page="/my-licenses/"]
 *
 * Shows a simple purchases table:
 *  - Date
 *  - Amount
 *  - Details and Downloads  → confirmation_page/?invoice_key=...
 *  - View Invoice           → invoice_page/?invoice_key=...
 *  - View Licenses          → licenses_page/?license=ID
 */
function my_getpaid_purchases_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view your purchases.</p>';
    }

    global $wpdb;
    $tables  = my_gpd_tables();
    $user_id = get_current_user_id();

    $atts = shortcode_atts(
        array(
            'confirmation_page' => '',          // e.g. "/confirmation/"
            'invoice_page'      => '/',         // "/" or empty = use WPInv_Invoice::get_view_url()
            'licenses_page'     => '/my-licenses/',
        ),
        $atts,
        'my_getpaid_purchases'
    );

    // Helper: normalise a page value to a full URL.
    $normalize_page_url = function( $page ) {
        $page = trim( (string) $page );
        if ( '' === $page ) {
            return '';
        }
        // Full URL already?
        if ( preg_match( '#^https?://#i', $page ) ) {
            return $page;
        }
        // Treat as path relative to site root.
        return home_url( '/' . ltrim( $page, '/' ) );
    };

    $confirmation_base = $normalize_page_url( $atts['confirmation_page'] );
    $licenses_base     = $normalize_page_url( $atts['licenses_page'] );
    $invoice_page_raw  = trim( (string) $atts['invoice_page'] );

    // Get invoices for this user via the licenses table.
    $sql = "
        SELECT
            inv.*,
            p.post_date,
            MIN(lic.id) AS license_id
        FROM {$tables['licenses']} AS lic
        INNER JOIN {$tables['invoices']} AS inv
            ON lic.invoice_id = inv.post_id
        INNER JOIN {$wpdb->posts} AS p
            ON inv.post_id = p.ID
        WHERE lic.user_id = %d
        GROUP BY inv.post_id
        ORDER BY p.post_date DESC
    ";

    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $user_id ) );

    if ( empty( $rows ) ) {
        return '<p>No purchases found for your account.</p>';
    }

    ob_start();
    ?>
    <div class="gpd-account gpd-purchases">
        <section class="gpd-card gpd-purchases-card">
            <header class="gpd-card__header">
                <h1 class="gpd-page-title">My Purchases</h1>
            </header>
            <div class="gpd-card__body">
                <table class="gpd-table gpd-table--purchases">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Details</th>
                            <th>Invoice</th>
                            <th>License Keys</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                                        foreach ( $rows as $row ) {
                        $post_id     = (int) $row->post_id;
                        $invoice_no  = isset( $row->number ) ? $row->number : '';
                        $invoice_key = isset( $row->invoice_key ) ? $row->invoice_key : '';
                        $license_id  = (int) $row->license_id;

                        // Date.
                        $date = mysql2date( get_option( 'date_format' ), $row->post_date );

                        // Build invoice object for amount / URLs / fallback key.
                        $inv_obj = null;
                        if ( class_exists( 'WPInv_Invoice' ) && $post_id ) {
                            $inv_obj = new WPInv_Invoice( $post_id );
                        }

                        if ( $inv_obj && ! $invoice_key ) {
                            $invoice_key = $inv_obj->get_key();
                        }

                        // Amount (via object if possible, otherwise from row).
                        if ( $inv_obj ) {
                            $amount_raw = $inv_obj->get_total();
                            $currency   = $inv_obj->get_currency();
                            $amount     = function_exists( 'wpinv_price' )
                                ? wpinv_price( $amount_raw, $currency )
                                : esc_html( $amount_raw );
                        } else {
                            $amount_raw = isset( $row->total ) ? (float) $row->total : 0;
                            $amount     = function_exists( 'wpinv_price' )
                                ? wpinv_price( $amount_raw )
                                : esc_html( $amount_raw );
                        }

                                                /*
                         * -----------------------------------------
                         * Details & Downloads link
                         * -----------------------------------------
                         *
                         * Priority:
                         *   1) Use confirmation_page attribute (your custom page)
                         *      ALWAYS, if it’s set.
                         *   2) Fall back to the plugin’s own receipt URL
                         */
                        $details_html = '&mdash;';
                        $details_url  = '';

                        // 1) Custom confirmation page if set.
                        if ( $confirmation_base ) {

                            // Make sure we have an invoice_key – fall back to object if needed.
                            $key_for_url = $invoice_key;
                            if ( ! $key_for_url && $inv_obj ) {
                                $key_for_url = $inv_obj->get_key();
                            }

                            $details_url = add_query_arg(
                                'invoice_key',
                                $key_for_url,
                                $confirmation_base
                            );

                        // 2) Fall back to WPInv’s receipt URL only if NO confirmation_page set.
                        } elseif ( $inv_obj ) {
                            $details_url = $inv_obj->get_receipt_url();
                        }

                        if ( $details_url ) {
                            $details_html = sprintf(
                                '<a href="%s">Details and Downloads</a>',
                                esc_url( $details_url )
                            );
                        }

                        // ----------------------------------
                        // Invoice view link.
                        // ----------------------------------
                        $invoice_html = '&mdash;';

                        if ( $invoice_page_raw === '' || $invoice_page_raw === '/' ) {
                            // Use plugin's own view URL.
                            if ( $inv_obj ) {
                                $invoice_url  = $inv_obj->get_view_url();
                                $invoice_html = sprintf(
                                    '<a href="%s">View Invoice</a>',
                                    esc_url( $invoice_url )
                                );
                            }
                        } else {
                            // Use custom invoice page and attach invoice_key.
                            if ( $invoice_key ) {
                                $invoice_base = $normalize_page_url( $invoice_page_raw );
                                $invoice_url  = add_query_arg( 'invoice_key', $invoice_key, $invoice_base );
                                $invoice_html = sprintf(
                                    '<a href="%s">View Invoice</a>',
                                    esc_url( $invoice_url )
                                );
                            }
                        }

                        // ----------------------------------
                        // License keys link.
                        // ----------------------------------
                        $licenses_html = '&mdash;';
                        if ( $licenses_base && $license_id ) {
                            $licenses_url  = add_query_arg( 'license', $license_id, $licenses_base );
                            $licenses_html = sprintf(
                                '<a href="%s">View Licenses</a>',
                                esc_url( $licenses_url )
                            );
                        }

                        ?>
                        <tr>
                            <td><?php echo esc_html( $date ); ?></td>
                            <td><?php echo $amount; ?></td>
                            <td><?php echo $details_html; ?></td>
                            <td><?php echo $invoice_html; ?></td>
                            <td><?php echo $licenses_html; ?></td>
                        </tr>
                        <?php
                    }

                    ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <?php

    $html = ob_get_clean();

    return apply_filters( 'my_getpaid_purchases_html', $html, $rows, $user_id, $atts );
}
add_shortcode( 'my_getpaid_purchases', 'my_getpaid_purchases_shortcode' );



/**
 * [my_getpaid_license_activations]
 *
 * Shows ONLY the Activations list for a single license.
 *
 * Accepts:
 *   ?license=ID
 *   ?license_key=KEY
 *   ?invoice=POST_ID   ← new: find the first license for this invoice/user
 */
function my_getpaid_license_activations_shortcode( $atts ) {

    $atts = shortcode_atts(
        array(
            'quiet_if_missing' => 'no',
        ),
        $atts,
        'my_getpaid_license_activations'
    );

    $quiet_if_missing = in_array(
        strtolower( (string) $atts['quiet_if_missing'] ),
        array( 'yes', 'true', '1' ),
        true
    );

    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view license activations.</p>';
    }

    if ( ! function_exists( 'my_gpd_tables' ) ) {
        return '<p>Configuration error: helper tables function missing.</p>';
    }

    global $wpdb;
    $tables  = my_gpd_tables();
    $user_id = get_current_user_id();
    $license = null;

    // Params: ?license=ID or ?license_key=KEY or ?invoice=POST_ID
    $license_id  = isset( $_GET['license'] ) ? (int) $_GET['license'] : 0;
    $license_key = isset( $_GET['license_key'] ) ? sanitize_text_field( wp_unslash( $_GET['license_key'] ) ) : '';
    $invoice_id  = isset( $_GET['invoice'] ) ? (int) $_GET['invoice'] : 0;

    $has_param   = ( $license_id || $license_key || $invoice_id );

    // Fetch license row *without* user filter first.
    if ( $license_id ) {

        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tables['licenses']} WHERE id = %d",
                $license_id
            )
        );

    } elseif ( $license_key ) {

        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tables['licenses']} WHERE license_key = %s",
                $license_key
            )
        );

    } elseif ( $invoice_id ) {

        // NEW: get first license for this invoice
        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tables['licenses']}
                 WHERE invoice_id = %d
                 ORDER BY id ASC
                 LIMIT 1",
                $invoice_id
            )
        );
    }

    // Enforce that it belongs to current user.
    if ( ! $license || (int) $license->user_id !== $user_id ) {

        if ( ! $has_param && $quiet_if_missing ) {
            return '';
        }

        return '<p>License not found for your account.</p>';
    }

    $activations = my_gpd_get_license_activations( $license );
    $base_url    = get_permalink();

    ob_start();
    ?>
    <div class="gpd-account gpd-license-activations-only">
        <section class="gpd-card gpd-license-activations">
            <header class="gpd-card__header">
                <h1 class="gpd-page-title">Activations</h1>
            </header>
            <div class="gpd-card__body">
                <?php if ( empty( $activations ) ) : ?>
                    <p class="gpd-empty">No activations yet.</p>
                <?php else : ?>
                    <ul class="gpd-list gpd-list--activations">
                        <?php foreach ( $activations as $domain => $timestamp ) :
                            $date = $timestamp ? mysql2date( get_option( 'date_format' ), $timestamp ) : '';
                            $remove_url = wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'gpd_remove_activation' => rawurlencode( $domain ),
                                        'license'               => (int) $license->id,
                                    ),
                                    $base_url
                                ),
                                'gpd_remove_activation_' . $license->id . '_' . $domain
                            );
                            ?>
                            <li class="gpd-list__item gpd-activation">
                                <div class="gpd-activation__info">
                                    <span class="gpd-activation__domain"><?php echo esc_html( $domain ); ?></span>
                                    <?php if ( $date ) : ?>
                                        <span class="gpd-activation__date">— <?php echo esc_html( $date ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <a class="gpd-link gpd-link--danger gpd-activation__remove"
                                   href="<?php echo esc_url( $remove_url ); ?>"
                                   onclick="return confirm('Remove this activation?');">
                                    Remove
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <?php

    $html = ob_get_clean();

    return apply_filters( 'my_getpaid_license_activations_html', $html, $license, $activations );
}
add_shortcode( 'my_getpaid_license_activations', 'my_getpaid_license_activations_shortcode' );



/**
 * [my_getpaid_purchase_confirmation
 *     invoice_page="/gp-checkout/gp-receipt/"
 *     confirmation_page="/confirmation/"
 *     activations_page="/license-activations/"]
 *
 * - Reads ?invoice_key= or ?invoice= from URL
 * - Confirms the order belongs to the current user
 * - Shows order summary
 * - Shows *all* license keys (item name left, key right, click-to-copy)
 * - Shows Products table with ALL download links for this purchase + price
 * - “Activations” row links to a separate activations page
 */
function my_getpaid_purchase_confirmation_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view this order.</p>';
    }

    if ( ! function_exists( 'my_gpd_tables' ) ) {
        return '<p>Configuration error: helper tables function missing.</p>';
    }

    $atts = shortcode_atts(
        array(
            'invoice_page'      => '', // e.g. "/gp-checkout/gp-receipt/"
            'confirmation_page' => '', // not used for links here, just documented
            'activations_page'  => '', // e.g. "/license-activations/"
        ),
        $atts,
        'my_getpaid_purchase_confirmation'
    );

    global $wpdb;
    $tables  = my_gpd_tables();
    $user_id = get_current_user_id();

    $invoice_key = isset( $_GET['invoice_key'] ) ? sanitize_text_field( wp_unslash( $_GET['invoice_key'] ) ) : '';
    $invoice_id  = isset( $_GET['invoice'] ) ? (int) $_GET['invoice'] : 0;

    if ( ! $invoice_key && ! $invoice_id ) {
        return '<p>Order not found.</p>';
    }

    // ------------------------------------------------------
    // Load invoice & confirm it belongs to this user
    // ------------------------------------------------------
    if ( $invoice_key ) {
        $sql = "
            SELECT inv.*, p.post_date
            FROM {$tables['invoices']} AS inv
            INNER JOIN {$wpdb->posts} AS p ON inv.post_id = p.ID
            INNER JOIN {$tables['licenses']} AS lic ON lic.invoice_id = inv.post_id
            WHERE lic.user_id = %d
              AND inv.invoice_key = %s
            LIMIT 1
        ";
        $invoice = $wpdb->get_row( $wpdb->prepare( $sql, $user_id, $invoice_key ) );
    } else {
        $sql = "
            SELECT inv.*, p.post_date
            FROM {$tables['invoices']} AS inv
            INNER JOIN {$wpdb->posts} AS p ON inv.post_id = p.ID
            INNER JOIN {$tables['licenses']} AS lic ON lic.invoice_id = inv.post_id
            WHERE lic.user_id = %d
              AND inv.post_id = %d
            LIMIT 1
        ";
        $invoice = $wpdb->get_row( $wpdb->prepare( $sql, $user_id, $invoice_id ) );
    }

    if ( ! $invoice ) {
        return '<p>Order not found for your account.</p>';
    }

    $post_id     = (int) $invoice->post_id;
    $invoice_key = ! empty( $invoice->invoice_key ) ? $invoice->invoice_key : $invoice_key;
    $date        = mysql2date( get_option( 'date_format' ), $invoice->post_date );

    // ------------------------------------------------------
    // Totals via WPInv_Invoice
    // ------------------------------------------------------
    $subtotal_label = '&mdash;';
    $discount_label = '&mdash;';
    $total_label    = '&mdash;';

    if ( class_exists( 'WPInv_Invoice' ) ) {
        $inv_obj = new WPInv_Invoice( $post_id );

        if ( method_exists( $inv_obj, 'get_subtotal' ) ) {
            $subtotal       = (float) $inv_obj->get_subtotal();
            $subtotal_label = sprintf( '$%s', number_format_i18n( $subtotal, 2 ) );
        }
        if ( method_exists( $inv_obj, 'get_discount' ) ) {
            $discount       = (float) $inv_obj->get_discount();
            $discount_label = sprintf( '-$%s', number_format_i18n( $discount, 2 ) );
        }
        if ( method_exists( $inv_obj, 'get_total' ) ) {
            $total       = (float) $inv_obj->get_total();
            $total_label = sprintf( '$%s', number_format_i18n( $total, 2 ) );
        }
    }

    // Status & payment method
    $status_label = 'Completed';
    if ( ! empty( $invoice->status ) ) {
        $status_label = ucwords( str_replace( array( 'wpi-', '-' ), array( '', ' ' ), $invoice->status ) );
    }

    $payment_method = '&mdash;';
    if ( isset( $invoice->gateway_id ) && $invoice->gateway_id ) {
        $payment_method = ucfirst( $invoice->gateway_id );
    } elseif ( isset( $invoice->payment_method ) && $invoice->payment_method ) {
        $payment_method = $invoice->payment_method;
    }

    // ------------------------------------------------------
    // Licenses for this invoice
    // ------------------------------------------------------
    $licenses = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$tables['licenses']}
             WHERE user_id = %d AND invoice_id = %d
             ORDER BY id ASC",
            $user_id,
            $post_id
        )
    );

    // Build license keys block (inline rows: item name left, key pill right)
    $license_keys_block = '';

    if ( ! empty( $licenses ) ) {
        ob_start();

        foreach ( $licenses as $lic ) {
            $item_post = get_post( (int) $lic->item_id );
            $item_name = $item_post ? get_the_title( $item_post ) : __( 'Unknown Item', 'getpaid' );
            ?>
            <div class="gpd-license-inline-row">
                <span class="gpd-license-item-name"><?php echo esc_html( $item_name ); ?></span>
                <span class="gpd-license-key-wrapper">
                    <code class="gpd-pill gpd-license-key-pill gpd-copy-on-click"
                        data-gpd-copy="<?php echo esc_attr( $lic->license_key ); ?>">
                        <?php echo esc_html( $lic->license_key ); ?>
                    </code>
                </span>
            </div>
            <?php
        }

        $license_keys_block = ob_get_clean();
    } else {
        $license_keys_block = '<span>&mdash;</span>';
    }


    // ------------------------------------------------------
    // Products + ALL download links (for *this* purchase)
    // ------------------------------------------------------
    $products = array();

    // Permissions map helper (same as your licenses_downloads shortcode)
    $permissions_map = array();
    if ( function_exists( 'my_gpd_get_permissions_map_for_user' ) ) {
        $permissions_map = my_gpd_get_permissions_map_for_user( $user_id );
    }

    foreach ( $licenses as $lic ) {
        $item_id = (int) $lic->item_id;
        if ( ! $item_id ) {
            continue;
        }
        if ( ! isset( $products[ $item_id ] ) ) {
            $item_post = get_post( $item_id );
            $item_name = $item_post ? get_the_title( $item_post ) : __( 'Unknown Item', 'getpaid' );

            // For now: show 0.00 to match your current test data.
            $price_label = '$0.00';

            $products[ $item_id ] = array(
                'name'      => $item_name,
                'price'     => $price_label,
                'downloads' => array(),
            );
        }

        // Build download links from the permissions table + _getpaid_downloadable_items.
        // Do not rely on _getpaid_item_downloads here; variation/download rows can live
        // only in GetPaid's downloadable-items table.
        $allowed_downloads_for_item = function_exists( 'my_gpd_get_allowed_downloads_for_user_item' )
            ? my_gpd_get_allowed_downloads_for_user_item( $user_id, $item_id, $permissions_map )
            : array();

        foreach ( $allowed_downloads_for_item as $download ) {
            if ( empty( $download['url'] ) ) {
                continue;
            }

            $products[ $item_id ]['downloads'][] = array(
                'name' => ! empty( $download['name'] ) ? $download['name'] : __( 'Download', 'getpaid' ),
                'url'  => $download['url'],
            );
        }
    }

    // ------------------------------------------------------
    // URLs for invoice & activations
    // ------------------------------------------------------
    $invoice_base     = $atts['invoice_page']     ? esc_url_raw( $atts['invoice_page'] )     : '';
    $activations_base = $atts['activations_page'] ? esc_url_raw( $atts['activations_page'] ) : '';

    $invoice_url     = '';
    $activations_url = '';

    if ( $invoice_base && $invoice_key ) {
        $invoice_url = esc_url( add_query_arg( 'invoice_key', $invoice_key, $invoice_base ) );
    }

    if ( $activations_base && ! empty( $licenses ) ) {
        $activations_url = esc_url( add_query_arg( 'invoice', $post_id, $activations_base ) );
    }

    // ------------------------------------------------------
    // Output
    // ------------------------------------------------------
    ob_start();
    ?>
    <div class="gpd-account gpd-purchase-confirmation">
        <section class="gpd-card gpd-order-summary">
            <header class="gpd-card__header">
                <h1 class="gpd-page-title">Order Summary</h1>
            </header>
            <div class="gpd-card__body">
                <dl class="gpd-definition-list gpd-definition-list--order">
                    <div class="gpd-definition-list__row">
                        <dt>Order:</dt>
                        <dd><?php echo ! empty( $invoice->number ) ? esc_html( $invoice->number ) : esc_html( $post_id ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Order Status:</dt>
                        <dd><?php echo esc_html( $status_label ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Payment Method:</dt>
                        <dd><?php echo wp_kses_post( $payment_method ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Date:</dt>
                        <dd><?php echo esc_html( $date ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Subtotal:</dt>
                        <dd><?php echo wp_kses_post( $subtotal_label ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Discount:</dt>
                        <dd><?php echo wp_kses_post( $discount_label ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Total:</dt>
                        <dd><?php echo wp_kses_post( $total_label ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row gpd-definition-list__row--license">
                        <dt>License Keys:</dt>
                        <dd><?php echo $license_keys_block; // already escaped ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt>Invoice:</dt>
                        <dd>
                            <?php if ( $invoice_url ) : ?>
                                <a href="<?php echo $invoice_url; ?>">View Invoice</a>
                            <?php else : ?>
                                &mdash;
                            <?php endif; ?>
                        </dd>
                    </div>

                    <?php if ( $activations_url ) : ?>
                    <div class="gpd-definition-list__row">
                        <dt>Activations:</dt>
                        <dd><a href="<?php echo $activations_url; ?>">Manage Activations</a></dd>
                    </div>
                    <?php endif; ?>
                </dl>
            </div>
        </section>

        <section class="gpd-card gpd-order-products">
            <header class="gpd-card__header">
                <h2 class="gpd-section-title gpd-section-title--products">Products</h2>
            </header>
            <div class="gpd-card__body">
                <?php if ( empty( $products ) ) : ?>
                    <p class="gpd-empty">No products found for this order.</p>
                <?php else : ?>
                    <table class="gpd-table gpd-table--products">
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col" class="gpd-product-price-col">Price</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $products as $prod ) : ?>
                            <tr>
                                <td class="gpd-product-name-cell">
                                    <div class="gpd-product-name">
                                        <?php echo esc_html( $prod['name'] ); ?>
                                    </div>

                                    <?php if ( ! empty( $prod['downloads'] ) ) : ?>
                                        <ul class="gpd-download-list-inline">
                                            <?php foreach ( $prod['downloads'] as $dl ) : ?>
                                                <li>
                                                    <a class="gpd-download-link"
                                                    href="<?php echo esc_url( $dl['url'] ); ?>">
                                                        <?php echo esc_html( $dl['name'] ); ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>

                                <td class="gpd-product-price">
                                    <?php
                                    // If you already stored a formatted price string in $prod['price'], use it.
                                    if ( ! empty( $prod['price'] ) ) {
                                        echo esc_html( $prod['price'] );
                                    } else {
                                        echo '$0.00';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>

    </div>

    <script>
    // Click-to-copy for license key pills
    document.addEventListener('click', function (e) {
        var pill = e.target.closest('.gpd-copy-on-click');
        if (!pill) return;
        var key = pill.getAttribute('data-gpd-copy');
        if (!key || !navigator.clipboard) return;

        var originalText = pill.textContent;
        navigator.clipboard.writeText(key).then(function () {
            pill.classList.add('gpd-pill--copied');
            pill.textContent = 'Copied!';
            setTimeout(function () {
                pill.classList.remove('gpd-pill--copied');
                pill.textContent = originalText;
            }, 1500);
        }).catch(function () {});
    });
    </script>
    <?php

    $html = ob_get_clean();
    return apply_filters( 'my_getpaid_purchase_confirmation_html', $html, $invoice, $licenses, $atts );
}
add_shortcode( 'my_getpaid_purchase_confirmation', 'my_getpaid_purchase_confirmation_shortcode' );

/**
 * [my_getpaid_license_card
 *     order_page="/my-order/"
 *     changelog_url="https://example.com/changelog/"
 *     activations_page="/license-activations/"]
 * [my_getpaid_license_card order_page="/my-order/" changelog_url="https://example.com/changelog/" activations_page="/license-activations/"]
 *
 * Single product card like the screenshot:
 *  - Product name
 *  - Order: #12345 (invoice number, linked)
 *  - License key in an input + "Copy license key" button
 *  - Downloads dropdown + Download button
 *  - Version text + "View changelog" link
 *  - Activations count + "Manage activations" link
 *
 * Uses ?license=ID or ?license_key=KEY in the URL
 * and enforces that the license belongs to the current user.
 * ACF Style
 */
function my_getpaid_license_card_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view this license.</p>';
    }

    if ( ! function_exists( 'my_gpd_tables' ) ) {
        return '<p>Configuration error: helper tables function missing.</p>';
    }

    global $wpdb;
    $tables  = my_gpd_tables();
    $user_id = get_current_user_id();

    $atts = shortcode_atts(
        array(
            'order_page'       => '', // page that shows order/receipt
            'changelog_url'    => '', // full URL
            'activations_page' => '', // page with [my_getpaid_license_activations]
        ),
        $atts,
        'my_getpaid_license_card'
    );

    // Normalise path → URL if needed.
    $normalize_page_url = function( $page ) {
        $page = trim( (string) $page );
        if ( '' === $page ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $page ) ) {
            return $page; // already full URL
        }
        return home_url( '/' . ltrim( $page, '/' ) );
    };

    $order_page       = $normalize_page_url( $atts['order_page'] );
    $activations_page = $normalize_page_url( $atts['activations_page'] );
    $changelog_url    = trim( (string) $atts['changelog_url'] );

    // --------- Find license (by ?license= or ?license_key=) ----------
    $license_id  = isset( $_GET['license'] ) ? (int) $_GET['license'] : 0;
    $license_key = isset( $_GET['license_key'] ) ? sanitize_text_field( wp_unslash( $_GET['license_key'] ) ) : '';

    if ( ! $license_id && ! $license_key ) {
        return '<p>License not specified.</p>';
    }

    $license = null;

    if ( $license_id ) {
        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tables['licenses']} WHERE id = %d",
                $license_id
            )
        );
    } else {
        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tables['licenses']} WHERE license_key = %s",
                $license_key
            )
        );
    }

    // Enforce ownership
    if ( ! $license || (int) $license->user_id !== $user_id ) {
        return '<p>License not found for your account.</p>';
    }

    $license_id  = (int) $license->id;
    $item_id     = (int) $license->item_id;
    $invoice_id  = (int) $license->invoice_id;
    $license_key = $license->license_key;

    // --------- Product (item) ----------
    $item_post  = $item_id ? get_post( $item_id ) : null;
    $item_name  = $item_post ? get_the_title( $item_post ) : __( 'Unknown product', 'getpaid' );

    // --------- Invoice (order) ----------
    $invoice = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT inv.*, p.post_date
             FROM {$tables['invoices']} AS inv
             INNER JOIN {$wpdb->posts} AS p ON inv.post_id = p.ID
             WHERE inv.post_id = %d",
            $invoice_id
        )
    );

    $invoice_number = ( $invoice && ! empty( $invoice->number ) ) ? $invoice->number : '';
    $invoice_key    = ( $invoice && ! empty( $invoice->invoice_key ) ) ? $invoice->invoice_key : '';
    $order_date     = $invoice ? mysql2date( get_option( 'date_format' ), $invoice->post_date ) : '';

    $order_url = '';
    if ( $order_page && $invoice_key ) {
        $order_url = esc_url( add_query_arg( 'invoice_key', $invoice_key, $order_page ) );
    }

    // --------- Activations ----------
    $current_act = (int) $license->activations;
    $max_act     = (int) $license->max_activations;
    $act_label   = $max_act > 0
        ? sprintf( '%d of %d activations', $current_act, $max_act )
        : sprintf( '%d of unlimited activations', $current_act );

    $activations_url = '';
    if ( $activations_page ) {
        // your [my_getpaid_license_activations] shortcode accepts ?license=
        $activations_url = esc_url( add_query_arg( 'license', $license_id, $activations_page ) );
    }

    // --------- Downloads & versions ----------
    $allowed_downloads = function_exists( 'my_gpd_get_allowed_downloads_for_user_item' )
        ? my_gpd_get_allowed_downloads_for_user_item( $user_id, $item_id )
        : array();


    // Pick the "latest" download as first in the list.
    $primary_download = ! empty( $allowed_downloads ) ? $allowed_downloads[0] : null;

    // Very simple "version" extraction: look for something like 1.2.3 in the name.
    $version_label = '';
    if ( $primary_download && preg_match( '/(\d+(?:\.\d+)+)/', $primary_download['name'], $m ) ) {
        $version_label = $m[1];
    }

    ob_start();
    ?>
    <div class="license-card">
        <div class="license-card__header">
            <h2 class="license-card__title"><?php echo esc_html( $item_name ); ?></h2>

            <div class="license-card__order">
                <?php if ( $invoice_number ) : ?>
                    Order:
                    <?php if ( $order_url ) : ?>
                        <a href="<?php echo $order_url; ?>">#<?php echo esc_html( $invoice_number ); ?></a>
                    <?php else : ?>
                        #<?php echo esc_html( $invoice_number ); ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="license-card__body">
            <div class="license-card__field-group">
                <label class="license-card__label">License Key</label>
                <div class="license-card__license-row">
                    <input
                        type="text"
                        class="license-card__input"
                        readonly
                        value="<?php echo esc_attr( $license_key ); ?>"
                        data-license-key-input="1"
                    />
                    <button type="button"
                            class="license-card__copy-btn"
                            data-license-copy-button="1">
                        Copy license key
                    </button>
                </div>
            </div>

            <hr class="license-card__divider" />

            <div class="license-card__footer">
                <div class="license-card__downloads">
                    <div class="license-card__label">Downloads</div>

                    <?php if ( ! empty( $allowed_downloads ) ) : ?>
                        <div class="license-card__downloads-row">
                            <select class="license-card__select" data-license-download-select="1">
                                <?php foreach ( $allowed_downloads as $i => $dl ) : ?>
                                    <option
                                        value="<?php echo esc_url( $dl['url'] ); ?>"
                                        <?php selected( $i, 0 ); ?>>
                                        <?php
                                        echo esc_html(
                                            $i === 0
                                                ? sprintf( 'Latest Version (%s)', $dl['name'] )
                                                : $dl['name']
                                        );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <a href="<?php echo esc_url( $primary_download['url'] ?? '#' ); ?>"
                               class="license-card__download-btn"
                               data-license-download-button="1">
                                Download
                            </a>
                        </div>
                    <?php else : ?>
                        <p>No downloads available for this license.</p>
                    <?php endif; ?>
                </div>

                <div class="license-card__version">
                    <div class="license-card__label">Version</div>
                    <div class="license-card__version-text">
                        <?php if ( $version_label ) : ?>
                            <?php echo esc_html( $version_label ); ?>
                        <?php endif; ?>
                        <?php if ( $order_date ) : ?>
                            <span class="license-card__version-date">
                                Released <?php echo esc_html( $order_date ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ( $changelog_url ) : ?>
                        <a href="<?php echo esc_url( $changelog_url ); ?>" class="license-card__link">
                            View changelog
                        </a>
                    <?php endif; ?>
                </div>

                <div class="license-card__activations">
                    <div class="license-card__label">Activations</div>
                    <div class="license-card__activations-text">
                        <?php echo esc_html( $act_label ); ?>
                    </div>
                    <?php if ( $activations_url ) : ?>
                        <a href="<?php echo $activations_url; ?>" class="license-card__link">
                            Manage activations
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Basic layout – tweak / move to your stylesheet as desired */
        .license-card {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 24px;
            margin-bottom: 24px;
            background: #fff;
        }
        .license-card__header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .license-card__title {
            font-size: 20px;
            margin: 0;
        }
        .license-card__order {
            font-size: 14px;
        }
        .license-card__field-group {
            margin-bottom: 16px;
        }
        .license-card__label {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .license-card__license-row {
            display: flex;
            gap: 12px;
        }
        .license-card__input {
            flex: 1;
            padding: 8px;
        }
        .license-card__copy-btn,
        .license-card__download-btn {
            padding: 8px 18px;
            border-radius: 999px;
            border: 1px solid #1e73be;
            background: #1e73be;
            color: #fff;
            cursor: pointer;
            text-decoration: none;
        }
        .license-card__divider {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #eee;
        }
        .license-card__footer {
            display: grid;
            gap: 16px;
        }
        @media (min-width: 768px) {
            .license-card__footer {
                grid-template-columns: 1.4fr 1fr 1.2fr;
                align-items: flex-start;
            }
        }
        .license-card__downloads-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .license-card__select {
            min-width: 220px;
        }
        .license-card__version-text,
        .license-card__activations-text {
            font-size: 14px;
            margin-bottom: 4px;
        }
        .license-card__version-date {
            margin-left: 8px;
            color: #777;
            font-size: 13px;
        }
        .license-card__link {
            font-size: 13px;
        }
    </style>

    <script>
    (function() {
        // Copy license key
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-license-copy-button]');
            if (!btn) return;

            var input = document.querySelector('[data-license-key-input]');
            if (!input || !navigator.clipboard) return;

            navigator.clipboard.writeText(input.value).then(function () {
                var old = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(function () {
                    btn.textContent = old;
                }, 1500);
            });
        });

        // Download button follows selected option
        document.addEventListener('change', function(e) {
            var select = e.target.closest('[data-license-download-select]');
            if (!select) return;

            var btn = document.querySelector('[data-license-download-button]');
            if (btn) {
                btn.href = select.value;
            }
        });
    })();
    </script>
    <?php

    return ob_get_clean();
}
add_shortcode( 'my_getpaid_license_card', 'my_getpaid_license_card_shortcode' );

/*
* The Below is to show up regardless
*
*/
/**
 * [my_getpaid_license_cards
 *     order_page="/my-order/"
 *     changelog_url="https://example.com/changelog/"
 *     activations_page="/license-activations/"]
 *
 * Renders a card (like my_getpaid_license_card) for **each**
 * license the current user owns. No ?license or ?license_key
 * is required on the URL.
 */
function my_getpaid_license_cards_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view your licenses.</p>';
    }

    if ( ! function_exists( 'my_gpd_tables' ) ) {
        return '<p>Configuration error: helper tables function missing.</p>';
    }

    global $wpdb;
    $tables  = my_gpd_tables();
    $user_id = get_current_user_id();

    $atts = shortcode_atts(
        array(
            'order_page'       => '',
            'changelog_url'    => '',
            'activations_page' => '',
        ),
        $atts,
        'my_getpaid_license_cards'
    );

    // ---- helpers ----
    $normalize_page_url = function( $page ) {
        $page = trim( (string) $page );
        if ( '' === $page ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $page ) ) {
            return $page; // already full URL
        }
        return home_url( '/' . ltrim( $page, '/' ) );
    };

    $order_page       = $normalize_page_url( $atts['order_page'] );
    $activations_page = $normalize_page_url( $atts['activations_page'] );
    $changelog_url    = trim( (string) $atts['changelog_url'] );

    // Get all licenses for this user
    $licenses = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$tables['licenses']}
             WHERE user_id = %d
             ORDER BY id DESC",
            $user_id
        )
    );

    if ( empty( $licenses ) ) {
        return '<p>No licenses found for your account.</p>';
    }

    // Permissions map (for downloads)
    $permissions_map = array();
    if ( function_exists( 'my_gpd_get_permissions_map_for_user' ) ) {
        $permissions_map = my_gpd_get_permissions_map_for_user( $user_id );
    }

    ob_start();

    foreach ( $licenses as $license ) {

        $license_id  = (int) $license->id;
        $item_id     = (int) $license->item_id;
        $invoice_id  = (int) $license->invoice_id;
        $license_key = $license->license_key;

        // ----- product -----
        $item_post = $item_id ? get_post( $item_id ) : null;
        $item_name = $item_post ? get_the_title( $item_post ) : __( 'Unknown product', 'getpaid' );

        // ----- invoice / order info -----
        $invoice = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT inv.*, p.post_date
                 FROM {$tables['invoices']} AS inv
                 INNER JOIN {$wpdb->posts} AS p ON inv.post_id = p.ID
                 WHERE inv.post_id = %d",
                $invoice_id
            )
        );

        $invoice_number = ( $invoice && ! empty( $invoice->number ) ) ? $invoice->number : '';
        $invoice_key    = ( $invoice && ! empty( $invoice->invoice_key ) ) ? $invoice->invoice_key : '';
        $order_date     = $invoice ? mysql2date( get_option( 'date_format' ), $invoice->post_date ) : '';

        $order_url = '';
        if ( $order_page && $invoice_key ) {
            $order_url = esc_url( add_query_arg( 'invoice_key', $invoice_key, $order_page ) );
        }

        // ----- activations -----
        $current_act = (int) $license->activations;
        $max_act     = (int) $license->max_activations;
        $act_label   = $max_act > 0
            ? sprintf( '%d of %d activations', $current_act, $max_act )
            : sprintf( '%d of unlimited activations', $current_act );

        $activations_url = '';
        if ( $activations_page ) {
            $activations_url = esc_url( add_query_arg( 'license', $license_id, $activations_page ) );
        }

        // ----- downloads + version -----
        $allowed_downloads  = function_exists( 'my_gpd_get_allowed_downloads_for_user_item' )
            ? my_gpd_get_allowed_downloads_for_user_item( $user_id, $item_id, $permissions_map )
            : array();


        $primary_download = ! empty( $allowed_downloads ) ? $allowed_downloads[0] : null;

        $version_label = '';
        if ( $primary_download && preg_match( '/(\d+(?:\.\d+)+)/', $primary_download['name'], $m ) ) {
            $version_label = $m[1];
        }
        ?>
        <div class="license-card">
            <div class="license-card__header">
                <h2 class="license-card__title"><?php echo esc_html( $item_name ); ?></h2>

                <div class="license-card__order">
                    <?php if ( $invoice_number ) : ?>
                        Order:
                        <?php if ( $order_url ) : ?>
                            <a href="<?php echo $order_url; ?>">#<?php echo esc_html( $invoice_number ); ?></a>
                        <?php else : ?>
                            #<?php echo esc_html( $invoice_number ); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="license-card__body">
                <div class="license-card__field-group">
                    <label class="license-card__label">License Key</label>
                    <div class="license-card__license-row">
                        <input
                            type="text"
                            class="license-card__input"
                            readonly
                            value="<?php echo esc_attr( $license_key ); ?>"
                        />
                        <button type="button"
                                class="license-card__copy-btn">
                            Copy license key
                        </button>
                    </div>
                </div>

                <hr class="license-card__divider" />

                <div class="license-card__footer">
                    <div class="license-card__downloads">
                        <div class="license-card__label">Downloads</div>

                        <?php if ( ! empty( $allowed_downloads ) ) : ?>
                            <div class="license-card__downloads-row">
                                <select class="license-card__select">
                                    <?php foreach ( $allowed_downloads as $i => $dl ) : ?>
                                        <option value="<?php echo esc_url( $dl['url'] ); ?>">
                                            <?php
                                            echo esc_html(
                                                $i === 0
                                                    ? sprintf( 'Latest Version (%s)', $dl['name'] )
                                                    : $dl['name']
                                            );
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <a href="<?php echo esc_url( $primary_download['url'] ?? '#' ); ?>"
                                   class="license-card__download-btn">
                                    Download
                                </a>
                            </div>
                        <?php else : ?>
                            <p>No downloads available for this license.</p>
                        <?php endif; ?>
                    </div>

                    <div class="license-card__version">
                        <div class="license-card__label">Version</div>
                        <div class="license-card__version-text">
                            <?php if ( $version_label ) : ?>
                                <?php echo esc_html( $version_label ); ?>
                            <?php endif; ?>
                            <?php if ( $order_date ) : ?>
                                <span class="license-card__version-date">
                                    Released <?php echo esc_html( $order_date ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ( $changelog_url ) : ?>
                            <a href="<?php echo esc_url( $changelog_url ); ?>" class="license-card__link">
                                View changelog
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="license-card__activations">
                        <div class="license-card__label">Activations</div>
                        <div class="license-card__activations-text">
                            <?php echo esc_html( $act_label ); ?>
                        </div>
                        <?php if ( $activations_url ) : ?>
                            <a href="<?php echo $activations_url; ?>" class="license-card__link">
                                Manage activations
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    } // end foreach licenses

    // ---- CSS + JS once only (reuse from single-card version) ----
    if ( ! defined( 'MY_GPD_LICENSE_CARD_ASSETS' ) ) :
        define( 'MY_GPD_LICENSE_CARD_ASSETS', true );
        ?>
        <style>
            .license-card {
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 24px;
                margin-bottom: 24px;
                background: #fff;
            }
            .license-card__header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 16px;
            }
            .license-card__title {
                font-size: 20px;
                margin: 0;
            }
            .license-card__order {
                font-size: 14px;
            }
            .license-card__field-group {
                margin-bottom: 16px;
            }
            .license-card__label {
                font-size: 13px;
                font-weight: 600;
                margin-bottom: 4px;
            }
            .license-card__license-row {
                display: flex;
                gap: 12px;
            }
            .license-card__input {
                flex: 1;
                padding: 8px;
            }
            .license-card__copy-btn,
            .license-card__download-btn {
                padding: 8px 18px;
                border-radius: 999px;
                border: 1px solid #1e73be;
                background: #1e73be;
                color: #fff;
                cursor: pointer;
                text-decoration: none;
            }
            .license-card__divider {
                margin: 20px 0;
                border: none;
                border-top: 1px solid #eee;
            }
            .license-card__footer {
                display: grid;
                gap: 16px;
            }
            @media (min-width: 768px) {
                .license-card__footer {
                    grid-template-columns: 1.4fr 1fr 1.2fr;
                    align-items: flex-start;
                }
            }
            .license-card__downloads-row {
                display: flex;
                gap: 10px;
                align-items: center;
            }
            .license-card__select {
                min-width: 220px;
            }
            .license-card__version-text,
            .license-card__activations-text {
                font-size: 14px;
                margin-bottom: 4px;
            }
            .license-card__version-date {
                margin-left: 8px;
                color: #777;
                font-size: 13px;
            }
            .license-card__link {
                font-size: 13px;
            }
        </style>

        <script>
        (function() {
            // Copy buttons (per card)
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.license-card__copy-btn');
                if (!btn) return;

                var card  = btn.closest('.license-card');
                var input = card ? card.querySelector('.license-card__input') : null;
                if (!input || !navigator.clipboard) return;

                navigator.clipboard.writeText(input.value).then(function () {
                    var old = btn.textContent;
                    btn.textContent = 'Copied!';
                    setTimeout(function () {
                        btn.textContent = old;
                    }, 1500);
                });
            });

            // Download button follows select (per card)
            document.addEventListener('change', function(e) {
                var select = e.target.closest('.license-card__select');
                if (!select) return;

                var card = select.closest('.license-card');
                var btn  = card ? card.querySelector('.license-card__download-btn') : null;
                if (btn) {
                    btn.href = select.value;
                }
            });
        })();
        </script>
        <?php
    endif;

    return ob_get_clean();
}
add_shortcode( 'my_getpaid_license_cards', 'my_getpaid_license_cards_shortcode' );

