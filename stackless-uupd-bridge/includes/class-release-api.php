<?php
/** Authenticated release publishing for Stackless-enabled WooCommerce products. */
defined('ABSPATH') || exit;
final class Stackless_UUPD_Release_API {
    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }
    public static function routes() {
        register_rest_route('uupd/v1', '/products/(?P<slug>[a-z0-9_.-]+)/release', [
            'methods'=>'POST', 'callback'=>[__CLASS__, 'release'], 'permission_callback'=>[__CLASS__, 'permit'],
        ]);
    }
    private static function product($slug) {
        if (!class_exists('Stackless\\Modules\\SoftwareLicensing\\ProductConfig')) { return null; }
        $id = \Stackless\Modules\SoftwareLicensing\ProductConfig::productIdForSlug(sanitize_key($slug));
        return $id ? wc_get_product($id) : null;
    }
    public static function permit(WP_REST_Request $request) {
        if (!is_user_logged_in()) { return new WP_Error('release_auth_required', 'Authenticate with a WordPress Application Password.', ['status'=>401]); }
        $product = self::product((string)$request['slug']);
        if (!$product) { return new WP_Error('release_unknown_product', 'Unknown Stackless licensing product.', ['status'=>404]); }
        if (!current_user_can('edit_post', $product->get_id()) || !current_user_can('manage_woocommerce')) {
            return new WP_Error('release_forbidden','Insufficient product permissions.', ['status'=>403]);
        }
        if ($request->get_param('publish') && !current_user_can('publish_products')) {
            return new WP_Error('release_cannot_publish','Publishing permission required.', ['status'=>403]);
        }
        return true;
    }
    public static function release(WP_REST_Request $request) {
        $product = self::product((string)$request['slug']);
        if (!$product) { return new WP_Error('release_unknown_product','Unknown product.', ['status'=>404]); }
        if ($product->get_meta('_stackless_sl_enabled', true) !== 'yes') {
            return new WP_Error('release_disabled','Enable Stackless licensing first.', ['status'=>400]);
        }
        $raw_version = (string) $request->get_param('version');
        $version = preg_replace('/^v/i', '', trim($raw_version));
        if (!$version || strlen($version)>64 || !preg_match('/^[0-9][A-Za-z0-9._+\\-]*$/', $version)) {
            return new WP_Error('release_version','A valid version is required.', ['status'=>400]);
        }
        $download_mode = sanitize_key((string)($request->get_param('download_mode') ?: 'replace'));
        if (!in_array($download_mode, ['replace', 'append'], true)) {
            return new WP_Error('release_download_mode', 'download_mode must be replace or append.', ['status'=>400]);
        }
        if (class_exists('Stackless_UUPD_Variation_Releases') && Stackless_UUPD_Variation_Releases::enabled($product)) {
            $scope_check=(string)$request->get_param('release_scope');
            if ($scope_check && !in_array($scope_check,['product','all_variations'],true)) return new WP_Error('release_scope','release_scope must be product or all_variations.', ['status'=>400]);
            if (Stackless_UUPD_Variation_Releases::scope($product,$scope_check)==='all_variations' && !$product->is_type('variable')) return new WP_Error('release_not_variable','All variations requires a variable product.', ['status'=>400]);
        }
        $files = $request->get_file_params();
        $upload = $files['package'] ?? null;
        $url = trim((string)$request->get_param('download_url'));
        if ($upload && $url) { return new WP_Error('release_multiple_packages','Use package OR download_url.', ['status'=>400]); }
        if ($url && (!wp_http_validate_url($url) || stripos($url, 'https://') !== 0 || !preg_match('~\\.zip(?:\\?.*)?$~i',$url))) {
            return new WP_Error('release_url','Package URL must be an HTTPS ZIP URL.', ['status'=>400]);
        }
        if (!$upload && !$url && !\Stackless\Modules\SoftwareLicensing\ProductConfig::package($product)) {
            return new WP_Error('release_missing_package','A package is required for the first release.', ['status'=>400]);
        }
        // Validate all inputs before touching the saved product.
        $fields = [];
        foreach (['requires_wp','tested_wp','requires_php'] as $key) {
            if ($request->has_param($key)) {
                $value = (string)$request->get_param($key);
                if ($value !== '' && !preg_match('/^[0-9.]+$/', $value)) { return new WP_Error('release_invalid_field','Invalid '.$key, ['status'=>400]); }
                $fields[$key] = $value;
            }
        }
        foreach (['icon_1x','icon_2x','banner_low','banner_high'] as $artwork_field) {
            if ($request->has_param($artwork_field)) {
                $artwork_url = trim((string)$request->get_param($artwork_field));
                if ($artwork_url !== '' && (stripos($artwork_url,'https://') !== 0 || !wp_http_validate_url($artwork_url))) {
                    return new WP_Error('release_artwork_url','Artwork URLs must be valid HTTPS URLs.', ['status'=>400]);
                }
                $product->update_meta_data('_stackless_uupd_'.$artwork_field, $artwork_url ? esc_url_raw($artwork_url) : '');
            }
        }
        $artifact = null;
        if ($upload) {
            if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return new WP_Error('release_upload_error','Upload error: ' . (int)($upload['error'] ?? -1), ['status'=>400]);
            }
            if (!is_uploaded_file($upload['tmp_name'] ?? '')) { return new WP_Error('release_upload_invalid','Invalid uploaded file.', ['status'=>400]); }
            if (strtolower(pathinfo($upload['name'] ?? '', PATHINFO_EXTENSION)) !== 'zip') {
                return new WP_Error('release_not_zip','The uploaded file must be a zip.', ['status'=>400]);
            }
            if (!class_exists('ZipArchive')) { return new WP_Error('release_zip_missing','PHP ZipArchive is required.', ['status'=>503]); }
            $archive = new ZipArchive();
            $opened = $archive->open($upload['tmp_name'], ZipArchive::CHECKCONS);
            if ($opened !== true) { return new WP_Error('release_bad_zip','Corrupt ZIP archive.', ['status'=>400]); }
            // Verify expected top-level plugin/theme directory: WordPress upgrades need it.
            $slug = sanitize_key((string)$request['slug']);
            $found = false;
            for ($i=0; $i<$archive->numFiles; $i++) {
                $member = $archive->getNameIndex($i);
                if (strpos($member, '../') !== false || strpos($member, '..\\') !== false || substr($member,0,1)==='/') { $archive->close(); return new WP_Error('release_unsafe_zip','Unsafe ZIP member.', ['status'=>400]); }
                if (strpos($member, $slug . '/') === 0 && substr($member, -1) !== '/') { $found = true; }
            }
            $archive->close();
            if (!$found) { return new WP_Error('release_zip_layout','ZIP must contain the software slug folder at its root.', ['status'=>400]); }
            // WooCommerce is already required by product resolution above. The
            // wc_get_upload_dir() function is not a supported WooCommerce API.
            // Use a protected subdirectory within WordPress uploads instead.
            if (!function_exists('WC') || !WC() || !class_exists('WC_Product_Download')) {
                return new WP_Error('release_wc_missing', 'WooCommerce is not loaded.', ['status'=>503]);
            }
            $uploads = wp_upload_dir();
            if (!empty($uploads['error']) || empty($uploads['basedir'])) {
                return new WP_Error('release_storage', 'Uploads directory unavailable.', ['status'=>500]);
            }
            $directory = trailingslashit($uploads['basedir']) . 'woocommerce_uploads/stackless-uupd-releases/';
            if (!wp_mkdir_p($directory)) {
                return new WP_Error('release_storage', 'Unable to create release storage.', ['status'=>500]);
            }
            // Apache protection. Nginx/IIS need equivalent deny rules at server level.
            $deny = "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
            foreach ([trailingslashit($uploads['basedir']) . 'woocommerce_uploads/', $directory] as $protected_dir) {
                if (!file_exists($protected_dir . '.htaccess') && false === file_put_contents($protected_dir . '.htaccess', $deny)) {
                    return new WP_Error('release_storage', 'Cannot protect release storage.', ['status'=>500]);
                }
                if (!file_exists($protected_dir . 'index.html')) { file_put_contents($protected_dir . 'index.html', ''); }
            }
            $filename = wp_unique_filename($directory, $slug . '-' . $version . '-' . wp_generate_password(8, false) . '.zip');
            $destination = $directory . $filename;
            if (!move_uploaded_file($upload['tmp_name'], $destination)) { return new WP_Error('release_storage','Unable to store ZIP.', ['status'=>500]); }
            chmod($destination, 0640);
            $artifact = $destination;
        }
        $product->update_meta_data('_stackless_sl_version', $version);
        if ($request->has_param('changelog')) {
            $entry = wp_kses_post((string)$request->get_param('changelog'));
            if ($entry !== '') {
                $prior = (string)$product->get_meta('_stackless_sl_changelog', true);
                $product->update_meta_data('_stackless_sl_changelog', '<h4>' . esc_html($version) . '</h4>' . $entry . "\\n" . $prior);
            }
        }
        foreach ($fields as $key=>$value) { $product->update_meta_data('_stackless_sl_'.$key, $value); }
        if ($request->has_param('upgrade_notice')) { $product->update_meta_data('_stackless_uupd_upgrade_notice', wp_kses_post((string)$request->get_param('upgrade_notice'))); }
        if ($artifact || $url) {
            $downloads = $download_mode === 'append' ? $product->get_downloads() : [];
            // Retain unique Woo download IDs in append mode; never overwrite history.
            $selected = wp_generate_uuid4();
            $download = new WC_Product_Download();
            $download->set_id($selected);
            $download->set_name(sanitize_key((string)$request['slug']) . ' ' . $version);
            $download->set_file($artifact ?: esc_url_raw($url));
            if (class_exists('Stackless_UUPD_Variation_Releases') && Stackless_UUPD_Variation_Releases::enabled($product)) {
                // Opted-in release manager always puts the newly published file first.
                $downloads = [$selected => $download] + $downloads;
            } else {
                $downloads[$selected] = $download;
            }
            $product->set_downloadable(true);
            $product->set_downloads($downloads);
            $product->update_meta_data('_stackless_sl_package_source', 'download');
            $product->update_meta_data('_stackless_sl_download_id', $selected);
            // Explicitly clear stale custom-URL state when changing to downloads.
            $product->update_meta_data('_stackless_sl_package_url', '');
        }
        if ($request->get_param('publish')) { $product->set_status('publish'); }
        $product->save();
        clean_post_cache($product->get_id());
        $distribution = null;
        // Backward compatible: deploy.sh sends no new fields. The product's opt-in
        // settings choose whether we also distribute to its variations.
        if (class_exists('Stackless_UUPD_Variation_Releases') && Stackless_UUPD_Variation_Releases::enabled($product) && ($artifact || $url)) {
            $requested_scope = (string)$request->get_param('release_scope');
            if ($requested_scope && !in_array($requested_scope, ['product','all_variations'], true)) {
                return new WP_Error('release_scope', 'release_scope must be product or all_variations.', ['status'=>400]);
            }
            $scope = Stackless_UUPD_Variation_Releases::scope($product, $requested_scope);
            $grant = in_array(strtolower((string)$request->get_param('grant_existing')), ['1','yes','true','on'], true);
            $distribution = Stackless_UUPD_Variation_Releases::apply(
                $product, $artifact ?: esc_url_raw($url), sanitize_key((string)$request['slug']).' '.$version,
                $download_mode, $scope, $grant, $selected
            );
            if (is_wp_error($distribution)) {
                return new WP_Error('release_variations_failed', $distribution->get_error_message(), ['status'=>500, 'parent_updated'=>true]);
            }
        }
        $response = new WP_REST_Response(['success'=>true, 'id'=>$product->get_id(),'slug'=>sanitize_key((string)$request['slug']), 'status'=>$product->get_status(), 'version'=>$version, 'download_mode'=>$download_mode, 'download_count'=>count($product->get_downloads()), 'package'=>!!($artifact || $url || \Stackless\Modules\SoftwareLicensing\ProductConfig::package($product)), 'last_updated'=>current_time('mysql'), 'distribution'=>$distribution], ($distribution && !empty($distribution['failed'])) ? 207 : 200);
        $response->header('Cache-Control','no-store');
        return $response;
    }
}
