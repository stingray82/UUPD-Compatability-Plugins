<?php
/**
 * Plugin Name: Stackless → UUPD Compatibility Bridge
 * Description: UUPD-compatible licensing and metadata endpoints backed by Stackless Software Licensing, plus product bootloader generator.
 * Version: 1.1.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

if (!class_exists('Stackless_UUPD_Bridge')) {
final class Stackless_UUPD_Bridge {
    const VERSION = '1.1.0';
    const NS = 'uupd/v1';
    const META_PREFIX = '_stackless_uupd_';

    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'routes'], 20);
        add_filter('woocommerce_product_data_tabs', [__CLASS__, 'tab']);
        add_action('woocommerce_product_data_panels', [__CLASS__, 'panel']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save']);
        add_action('wp_ajax_stackless_uupd_preview', [__CLASS__, 'ajax_preview']);
        add_action('woocommerce_product_data_panels', [__CLASS__, 'artwork_panel'], 30);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_media']);
        add_filter('stackless_software_licensing_version_payload', [__CLASS__, 'version_artwork_payload'], 10, 3);
        add_filter('stackless_software_licensing_info_payload', [__CLASS__, 'artwork_payload'], 10, 3);
        require_once __DIR__ . '/includes/class-variation-releases.php';
        Stackless_UUPD_Variation_Releases::boot();
        require_once __DIR__ . '/includes/class-release-api.php';
        Stackless_UUPD_Release_API::boot();
    }

    public static function routes() {
        register_rest_route(self::NS, '/license/(?P<action>activate|deactivate|check)', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'license'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/metadata', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'metadata'], 'permission_callback' => '__return_true',
        ]);
    }

    private static function license_key(WP_REST_Request $request) {
        $key = $request->get_header('x-uupd-license-key');
        if (!$key) { $key = $request->get_param('license_key'); }
        if (!$key) { $key = $request->get_param('key'); }
        return is_scalar($key) ? trim((string) $key) : '';
    }

    private static function params(WP_REST_Request $request) {
        $domain = (string) ($request->get_param('domain') ?: $request->get_param('site_url') ?: '');
        $domain = trim($domain);
        // UUPD normally sends a host only; Stackless accepts a canonical URL.
        if ($domain && !preg_match('~^https?://~i', $domain)) { $domain = 'https://' . $domain; }
        $params = [
            'license_key' => self::license_key($request),
            'slug' => sanitize_key((string) $request->get_param('slug')),
            'site_url' => esc_url_raw($domain),
        ];
        $id = absint($request->get_param('product_id') ?: $request->get_param('item_id'));
        if ($id) { $params['product_id'] = $id; }
        $version = $request->get_param('installed_version') ?: $request->get_param('version');
        if ($version) { $params['version'] = sanitize_text_field((string) $version); }
        return $params;
    }

    /** In-process REST dispatch uses Stackless's own permissions, validation and response logic. */
    private static function stackless($endpoint, array $params) {
        if (!class_exists('Stackless\\Modules\\SoftwareLicensing\\RestApi')) {
            return new WP_Error('stackless_unavailable', 'Enable Stackless Software Licensing on this store.', ['status' => 503]);
        }
        $request = new WP_REST_Request('POST', '/stackless/v1/licenses/' . $endpoint);
        $request->set_body_params($params);
        return rest_do_request($request);
    }

    private static function translate(WP_REST_Response $response, $action) {
        $data = $response->get_data();
        $data = is_array($data) ? $data : [];
        if (isset($data['license']) && is_array($data['license'])) { $data += $data['license']; }
        $status = (string) ($data['status'] ?? 'inactive');
        $success = !empty($data['success']);
        if ($action === 'check') {
            $success = !empty($data['valid']) && (!array_key_exists('site_active', $data) || !empty($data['site_active']));
        }
        if ($action === 'deactivate' && $success) { $status = 'inactive'; }
        $data['success'] = $success;
        $data['status'] = $status;
        $data['license_status'] = $status;
        $data['license'] = $success && $status === 'active' ? 'valid' : 'invalid';
        if (isset($data['expires_at'])) { $data['date_expires'] = $data['expires_at']; $data['expiry'] = $data['expires_at']; }
        if (isset($data['activation_limit'])) { $data['max_activations'] = $data['activation_limit']; }
        if (isset($data['activations_used'])) { $data['activations'] = $data['activations_used']; }
        $response->set_data($data);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    public static function license(WP_REST_Request $request) {
        $action = sanitize_key((string) $request->get_param('action'));
        $params = self::params($request);
        if (!$params['license_key'] || !$params['slug'] || !$params['site_url']) {
            return new WP_Error('missing_parameters', 'license_key, slug and domain are required.', ['status' => 400]);
        }
        // Do not add a second deactivate: the bundled UUPD UI deliberately does that itself.
        $response = self::stackless($action, $params);
        if (is_wp_error($response)) { return $response; }
        // Deactivation is idempotent: an already-absent site activation is a
        // successful end state for UUPD. Do not hide invalid-key, invalid-site,
        // authorization, or other Stackless errors.
        if ($action === 'deactivate' && $response instanceof WP_REST_Response
            && (int) $response->get_status() === 404) {
            $body = $response->get_data();
            if (is_array($body) && ($body['code'] ?? '') === 'site_not_active') {
                $response = new WP_REST_Response([
                    'success' => true,
                    'code' => 'already_deactivated',
                    'message' => 'This site was not activated; the local license can be cleared.',
                    'status' => 'inactive',
                    'site_active' => false,
                ], 200);
            }
        }
        return self::translate($response, $action);
    }

    public static function metadata(WP_REST_Request $request) {
        $params = self::params($request);
        if (!$params['slug']) { return new WP_Error('missing_slug', 'slug is required.', ['status' => 400]); }
        // Public info is available without a license, but the package requires a valid activated key.
        $info = self::stackless('info', $params);
        $version = $params['license_key'] !== '' ? self::stackless('version', $params) : null;
        $details = ($info instanceof WP_REST_Response && $info->get_status() === 200) ? (array) $info->get_data() : [];
        $updates = ($version instanceof WP_REST_Response && $version->get_status() === 200) ? (array) $version->get_data() : [];
        if (!$details && !$updates) {
            if (is_wp_error($info)) { return $info; }
            return $info instanceof WP_REST_Response ? $info : new WP_Error('missing_product', 'Unknown software product.', ['status' => 404]);
        }
        $combined = array_merge($details, $updates);
        $version_number = (string) ($combined['new_version'] ?? $combined['version'] ?? '');
        $package = (string) ($updates['package'] ?? $updates['download_link'] ?? '');
        $combined['success'] = true;
        $combined['version'] = $version_number;
        $combined['new_version'] = $version_number;
        $combined['download_url'] = $package;
        $combined['package'] = $package;
        $combined['download_link'] = $package;
        $combined['license_required'] = true;
        $combined['license_status'] = (string) ($updates['license']['status'] ?? 'inactive');
        $combined['sections'] = (array) ($combined['sections'] ?? []);
        $combined['icons'] = (array) ($combined['icons'] ?? []);
        $combined['banners'] = (array) ($combined['banners'] ?? []);
        // Successful metadata response even without a key: show new versions, never distribute unauthorised packages.
        $response = new WP_REST_Response($combined, 200);
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    public static function tab($tabs) {
        $tabs['stackless_uupd'] = ['label' => 'UUPD Loader', 'target' => 'stackless_uupd_panel', 'class' => [], 'priority' => 85];
        return $tabs;
    }

    public static function panel() {
        global $post;
        if (!$post || $post->post_type !== 'product') { return; }
        $id = (int) $post->ID;
        $get = static function($key, $fallback = '') use ($id) {
            $value = get_post_meta($id, Stackless_UUPD_Bridge::META_PREFIX . $key, true);
            return $value !== '' ? $value : $fallback;
        };
        $vendor = $get('vendor', 'myvendor');
        $type = $get('type', 'plugin');
        $mode = $get('mode', 'both');
        $parent = $get('menu_parent', 'options-general.php');
        $placement = $get('menu_placement', $mode === 'inline' ? 'existing' : ($parent === 'top_level' ? 'top' : 'submenu'));
        $target_slug = $get('menu_slug', '');
        $menu_title = $get('menu_title', '');
        $page_title = $get('page_title', '');
        $updater_path = $get('updater_path', 'inc/updater.php');
        $ui_path = $get('ui_path', 'inc/class-uupd-license-ui.php');
        echo '<div id="stackless_uupd_panel" class="panel woocommerce_options_panel"><div class="options_group">';
        echo '<p style="padding:12px">Configure <strong>Licensing</strong> first. Both snippets belong inside an existing PHP file (plugin main file or theme functions.php); neither contains an opening PHP tag. Changes below refresh the preview without saving.</p>';
        woocommerce_wp_text_input(['id' => self::META_PREFIX . 'vendor', 'label' => 'UUPD Vendor', 'value' => $vendor, 'description' => 'Stable vendor namespace, e.g. tdlab', 'desc_tip' => true]);
        woocommerce_wp_select(['id' => self::META_PREFIX . 'type', 'label' => 'Software type', 'value' => $type, 'options' => ['plugin'=>'Plugin', 'theme'=>'Theme']]);
        woocommerce_wp_select(['id' => self::META_PREFIX . 'mode', 'label' => 'Licence UI', 'value' => $mode, 'options' => ['inline'=>'Inline only', 'menu'=>'Menu only', 'both'=>'Inline + menu']]);
        woocommerce_wp_select(['id' => self::META_PREFIX . 'menu_placement', 'label' => 'Menu placement', 'value' => $placement, 'options' => ['existing'=>'Use existing settings page (no new menu)', 'top'=>'Create new top-level menu', 'submenu'=>'Create submenu under parent']]);
        woocommerce_wp_text_input(['id' => self::META_PREFIX . 'menu_parent', 'label' => 'Parent menu slug', 'value' => $parent, 'description' => 'For existing page: options-general.php or admin.php/custom parent slug. For new submenu: the parent slug, e.g. options-general.php or my-plugin.', 'desc_tip'=>true]);
        woocommerce_wp_text_input(['id' => self::META_PREFIX . 'menu_slug', 'label' => 'Target / new page slug', 'value' => $target_slug, 'description' => 'Existing page: exact registered slug, e.g. esp-example-setting-page. New menu: unique slug; blank uses vendor-software-slug.', 'desc_tip'=>true]);
        woocommerce_wp_text_input(['id' => self::META_PREFIX . 'menu_title', 'label' => 'New menu title', 'value' => $menu_title, 'description' => 'For newly created menus only. Blank defaults to product name + License.', 'desc_tip'=>true]);
        woocommerce_wp_text_input(['id' => self::META_PREFIX . 'page_title', 'label' => 'New page title', 'value' => $page_title, 'description' => 'For newly created menus only. Blank defaults to product name + License.', 'desc_tip'=>true]);
        echo '<p style="padding:0 12px">Existing page: use Inline and place Part 3 inside its settings callback. The Bridge will not register a duplicate menu. New menu: choose Menu only or Inline + menu.</p>';
        woocommerce_wp_text_input(['id' => self::META_PREFIX . 'updater_path', 'label' => 'Updater include path', 'value' => $updater_path, 'description' => 'Path relative to the main plugin/theme directory; e.g. inc/updater.php', 'desc_tip' => true]);
        woocommerce_wp_text_input(['id' => self::META_PREFIX . 'ui_path', 'label' => 'Licence UI include path', 'value' => $ui_path, 'description' => 'Relative path, e.g. includes/licensing/class-uupd-license-ui.php', 'desc_tip' => true]);
        $slug = get_post_meta($id, '_stackless_sl_slug', true);
        $enabled = get_post_meta($id, '_stackless_sl_enabled', true);
        if (!$slug || $enabled !== 'yes') {
            echo '<p style="padding:12px;color:#b32d2e">Enable Stackless licensing and set the Software slug under Licensing. Save the product once to update those source values.</p>';
        }
        woocommerce_wp_checkbox(['id'=>'_stackless_uupd_bulk_enabled','label'=>'Optional release management','value'=>get_post_meta($id,'_stackless_uupd_bulk_enabled',true),'description'=>'Opt in for manual releases, release history and optional variation deployment. Off by default.']);
        woocommerce_wp_select(['id'=>'_stackless_uupd_release_scope','label'=>'Automatic deployment target','value'=>$get('release_scope','product'),'options'=>['product'=>'Product only (existing behaviour)','all_variations'=>'Product + all variations'],'description'=>'Used by the existing deploy.sh without changing its request.']);
        echo '<p style="padding:0 12px">Manual release scope and previous-purchaser grants are selected separately for each release. Automatic deployments never grant historic orders unless explicitly requested through the API.</p>';
        $parts = self::loader_parts($id, $vendor, $type, $mode, $parent, $updater_path, $ui_path, $placement, $target_slug, $menu_title, $page_title);
        echo '<p style="padding:0 12px"><strong>Part 1 — Configuration constants</strong><br>Place near the top of your main plugin PHP file or theme functions.php, outside a function.</p>';
        echo '<p style="padding:0 12px"><textarea id="stackless-uupd-constants" readonly style="width:100%;height:265px;font-family:monospace" onclick="this.select()">' . esc_textarea($parts['constants']) . '</textarea></p>';
        echo '<p style="padding:0 12px"><strong>Part 2 — Bootloader</strong><br>Place after the constants in the same PHP file. Place the two UUPD source files in the paths configured above. Paths are relative to the plugin/theme root.</p>';
        echo '<p style="padding:0 12px"><textarea id="stackless-uupd-bootloader" readonly style="width:100%;height:390px;font-family:monospace" onclick="this.select()">' . esc_textarea($parts['bootloader']) . '</textarea></p>';
        echo '<div id="stackless-uupd-inline-section"' . ($mode === 'menu' ? ' style="display:none"' : '') . '>';
        echo '<p style="padding:0 12px"><strong>Part 3 — Inline licence box (settings-page callback only)</strong><br>For Inline or Both, copy this call into the function that renders your existing plugin/theme admin settings page, at the exact location where the licence box should appear. Do not put it in the bootloader or at file scope. The UI must have been registered by Part 2 first.</p>';
        echo '<p style="padding:0 12px"><textarea id="stackless-uupd-inline" readonly style="width:100%;height:85px;font-family:monospace" onclick="this.select()">' . esc_textarea($parts['inline']) . '</textarea></p>';
        echo '</div>';
        echo '<p style="padding:0 12px">Install this bridge only on the Stackless store, not on customer sites.</p>';
        echo '<script>jQuery(function($){let timer;const prefix="' . esc_js(self::META_PREFIX) . '";const ids=["vendor","type","mode","menu_placement","menu_parent","menu_slug","menu_title","page_title","updater_path","ui_path"];const panel=$("#stackless_uupd_panel");function refresh(){const data={action:"stackless_uupd_preview",nonce:"' . esc_js(wp_create_nonce('stackless_uupd_preview')) . '",product_id:' . (int)$id . '};ids.forEach(k=>data[k]=panel.find("#"+prefix+k).val());$.post(ajaxurl,data,function(r){if(r&&r.success){$("#stackless-uupd-constants").val(r.data.constants);$("#stackless-uupd-bootloader").val(r.data.bootloader);$("#stackless-uupd-inline").val(r.data.inline);$("#stackless-uupd-inline-section").toggle(data.mode!=="menu");}});}panel.on("input change",ids.map(k=>"#"+prefix+k).join(","),function(){clearTimeout(timer);timer=setTimeout(refresh,180);});});</script>';
        echo '</div></div>';
    }

    public static function save($product) {
        $product->update_meta_data('_stackless_uupd_bulk_enabled', isset($_POST['_stackless_uupd_bulk_enabled']) ? 'yes' : 'no');
        if (isset($_POST['_stackless_uupd_release_scope'])) {
            $scope = sanitize_key(wp_unslash($_POST['_stackless_uupd_release_scope']));
            $product->update_meta_data('_stackless_uupd_release_scope', in_array($scope,['product','all_variations'],true) ? $scope : 'product');
        }
        foreach (['vendor', 'type', 'mode', 'menu_placement', 'menu_parent', 'menu_slug', 'menu_title', 'page_title', 'updater_path', 'ui_path', 'icon_1x', 'icon_2x', 'banner_low', 'banner_high'] as $field) {
            $key = self::META_PREFIX . $field;
            if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) { continue; }
            $value = sanitize_text_field(wp_unslash($_POST[$key]));
            if (in_array($field, ['icon_1x', 'icon_2x','banner_low','banner_high'], true)) { $value = esc_url_raw($value, ['https']); }
            if ($field === 'vendor') { $value = sanitize_key(str_replace('-', '_', $value)); }
            if ($field === 'type' && !in_array($value, ['plugin','theme'], true)) { $value = 'plugin'; }
            if ($field === 'mode' && !in_array($value, ['inline','menu','both'], true)) { $value = 'both'; }
            if ($field === 'menu_placement' && !in_array($value, ['existing','top','submenu'], true)) { $value = 'submenu'; }
            if ($field === 'menu_parent') { $value = self::safe_menu_parent($value); }
            if ($field === 'menu_slug') { $value = sanitize_key($value); }
            if (in_array($field, ['updater_path','ui_path'], true)) { $value = self::safe_path($value, $field === 'updater_path' ? 'inc/updater.php' : 'inc/class-uupd-license-ui.php'); }
            $product->update_meta_data($key, $value);
        }
    }

    public static function ajax_preview() {
        check_ajax_referer('stackless_uupd_preview', 'nonce');
        if (!current_user_can('edit_products')) { wp_send_json_error('Not permitted.', 403); }
        $id = absint($_POST['product_id'] ?? 0);
        if (!$id || get_post_type($id) !== 'product' || !current_user_can('edit_post', $id)) { wp_send_json_error('Invalid product.', 403); }
        $vendor = sanitize_key(str_replace('-', '_', sanitize_text_field(wp_unslash($_POST['vendor'] ?? ''))));
        $type = sanitize_key(wp_unslash($_POST['type'] ?? 'plugin'));
        $mode = sanitize_key(wp_unslash($_POST['mode'] ?? 'both'));
        $parent = self::safe_menu_parent(sanitize_text_field(wp_unslash($_POST['menu_parent'] ?? 'options-general.php')));
        $placement = sanitize_key(wp_unslash($_POST['menu_placement'] ?? ($mode === 'inline' ? 'existing' : (($_POST['menu_parent'] ?? '') === 'top_level' ? 'top' : 'submenu'))));
        $target_slug = sanitize_key(wp_unslash($_POST['menu_slug'] ?? ''));
        $menu_title = sanitize_text_field(wp_unslash($_POST['menu_title'] ?? ''));
        $page_title = sanitize_text_field(wp_unslash($_POST['page_title'] ?? ''));
        $updater = self::safe_path(sanitize_text_field(wp_unslash($_POST['updater_path'] ?? '')), 'inc/updater.php');
        $ui = self::safe_path(sanitize_text_field(wp_unslash($_POST['ui_path'] ?? '')), 'inc/class-uupd-license-ui.php');
        wp_send_json_success(self::loader_parts($id, $vendor, $type, $mode, $parent, $updater, $ui, $placement, $target_slug, $menu_title, $page_title));
    }

    private static function loader_parts($product_id, $vendor, $type, $mode, $menu_parent, $updater_path = 'inc/updater.php', $ui_path = 'inc/class-uupd-license-ui.php', $placement = 'submenu', $target_slug = '', $menu_title = '', $page_title = '') {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $name = $product ? $product->get_name() : 'My Software';
        $slug = sanitize_key((string)get_post_meta($product_id, '_stackless_sl_slug', true));
        if ($slug === '') { $slug = 'set-software-slug'; }
        $vendor = sanitize_key(str_replace('-', '_', (string)$vendor));
        if ($vendor === '') { $vendor = 'myvendor'; }
        $type = $type === 'theme' ? 'theme' : 'plugin';
        $mode = in_array($mode, ['inline','menu','both'], true) ? $mode : 'both';
        $placement = in_array($placement, ['existing','top','submenu'], true) ? $placement : ($mode === 'inline' ? 'existing' : ($menu_parent === 'top_level' ? 'top' : 'submenu'));
        // Inline-only never creates a menu. Existing-page mode also never creates a menu.
        if ($mode === 'inline') { $placement = 'existing'; }
        $menu_parent = self::safe_menu_parent($menu_parent);
        $target_slug = sanitize_key($target_slug);
        $menu_title = sanitize_text_field($menu_title);
        $page_title = sanitize_text_field($page_title);
        $resolved_parent = $placement === 'top' ? 'top_level' : $menu_parent;
        $creates_menu = $placement !== 'existing';
        $base = 'UUPD_STACKLESS_' . strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $vendor . '_' . $slug . '_' . $product_id));
        $base = preg_replace('/_+/', '_', $base);
        $q = static function($x) { return var_export($x, true); };
        $site = untrailingslashit(home_url('/'));
        $const = "// Configuration: paste into the main " . $type . " PHP file. No opening PHP tag.\n";
        foreach (['VENDOR'=>$vendor,'VERSION'=>'1.0.0','ITEM_NUMBER'=>(int)$product_id,'FRIENDLY_NAME'=>$name,'SLUG'=>$slug,'SERVER_BASE'=>$site] as $key=>$value) {
            $const .= 'define( ' . $q($base . '_' . $key) . ', ' . $q($value) . " );\n";
        }
        $const .= "define( '" . $base . "_MAIN_FILE', __FILE__ ); // Plugin main file, or theme functions.php\n";
        $const .= 'define( ' . $q($base . '_MENU_SLUG') . ', ' . ($target_slug !== '' ? $q($target_slug) : $base . "_VENDOR . '-' . " . $base . '_SLUG') . " );\n";
        $C = static function($key) use ($base) { return $base . '_' . $key; };
        $root = $type === 'theme' ? 'get_stylesheet_directory()' : '__DIR__';
        $hook = $type === 'theme' ? 'after_setup_theme' : 'plugins_loaded';
        $metadata = $C('SERVER_BASE') . " . '/wp-json/uupd/v1/metadata'";
        $license_scope = $vendor . '/' . $slug;
        $boot = "// Initialization: paste below the constants in the same PHP file.\n";
        $boot .= "add_action( '" . $hook . "', static function () {\n";
        $boot .= "    require_once " . $root . " . " . $q("/" . self::safe_path($updater_path, "inc/updater.php")) . ";\n";
        $boot .= "    require_once " . $root . " . " . $q("/" . self::safe_path($ui_path, "inc/class-uupd-license-ui.php")) . ";\n\n";
        $boot .= "    \\UUPD\\UI\\License\\V1\\UUPD_License_UI::register( [\n";
        foreach (['vendor'=>'VENDOR','slug'=>'SLUG','item_id'=>'ITEM_NUMBER','plugin_name'=>'FRIENDLY_NAME','license_server'=>'SERVER_BASE'] as $key=>$ck) {
            $boot .= "        '" . $key . "' => " . $C($ck) . ",\n";
        }
        $boot .= "        'metadata_base' => " . $metadata . ",\n";
        $boot .= "        'menu_parent' => " . ($creates_menu ? $q($resolved_parent) : 'false') . ",\n";
        $boot .= "        'menu_slug' => " . $C('MENU_SLUG') . ",\n";
        $boot .= "        'page_title' => " . ($page_title !== '' ? $q($page_title) : $C('FRIENDLY_NAME') . " . ' License'") . ",\n";
        $boot .= "        'menu_title' => " . ($menu_title !== '' ? $q($menu_title) : $C('FRIENDLY_NAME') . " . ' License'") . ",\n";
        $boot .= "        'capability' => 'manage_options',\n";
        $boot .= "    ] );\n\n";
        if (!$creates_menu && !in_array($menu_parent, ['options-general.php', ''], true)) {
            // License UI defaults inline links to options-general.php. For a custom
            // parent, suppress only this product's built-in notice and replace it.
            $scope_filter = 'uupd/license_ui/show_notice/' . $vendor . '/' . $slug;
            $boot .= "    add_filter( " . $q($scope_filter) . ", static function ( \$show, \$license ) {\n";
            $boot .= "        if ( ! \$show || ( \$license['status'] ?? '' ) === 'active' ) { return \$show; }\n";
            $boot .= "        add_action( 'admin_notices', static function () {\n";
            $boot .= "            if ( ! current_user_can( 'manage_options' ) ) { return; }\n";
            $boot .= "            \$url = add_query_arg( 'page', " . $C('MENU_SLUG') . ", admin_url( 'admin.php' ) );\n";
            $boot .= "            echo '<div class=\"notice notice-warning\"><p>' . esc_html( sprintf( 'Please activate your %s license to enable updates and support.', " . $C('FRIENDLY_NAME') . " ) ) . ' <a class=\"button button-primary\" href=\"' . esc_url( \$url ) . '\">Activate License</a></p></div>';\n";
            $boot .= "        } );\n";
            $boot .= "        return false;\n    }, 10, 2 );\n\n";
        }
        $boot .= "    \\UUPD\\V2\\UUPD_Updater_V2::register( [\n";
        $boot .= "        'vendor' => " . $C('VENDOR') . ",\n";
        $boot .= "        'slug' => " . $C('SLUG') . ",\n";
        $boot .= "        'name' => " . $C('FRIENDLY_NAME') . ",\n";
        $boot .= "        'version' => " . $C('VERSION') . ",\n";
        $boot .= "        'mode' => 'json',\n";
        $boot .= "        'server' => " . $metadata . ",\n";
        $boot .= $type === 'theme' ? "        'real_slug' => " . $C('SLUG') . ",\n" : "        'plugin_file' => plugin_basename( " . $C('MAIN_FILE') . " ),\n";
        $boot .= "    ] );\n";
        $boot .= "}, 1 );\n\n";
        // Remove sensitive query key while preserving it for the UUPD request header.
        $boot .= "add_filter( 'uupd/request_params/" . $license_scope . "', static function ( \$params ) {\n";
        $boot .= "    unset( \$params['key'] );\n    \$params['domain'] = home_url( '/' );\n    \$params['item_id'] = " . $C('ITEM_NUMBER') . ";\n    return \$params;\n} );\n";
        $boot .= "add_filter( 'uupd/request_headers/" . $license_scope . "', static function ( \$headers ) {\n";
        $boot .= "    \$option = get_option( 'uupd_license_' . sanitize_key( " . $C('VENDOR') . " . '__' . str_replace( '-', '_', " . $C('SLUG') . " ) ), [] );\n";
        $boot .= "    \$constant_name = 'UUPD_' . strtoupper( str_replace( '-', '_', " . $C('VENDOR') . " ) ) . '_' . strtoupper( str_replace( '-', '_', " . $C('SLUG') . " ) ) . '_LICENSE_KEY';\n";
        $boot .= "    \$key = defined( \$constant_name ) ? constant( \$constant_name ) : ( \$option['license_key'] ?? '' );\n";
        $boot .= "    if ( is_string( \$key ) && \$key !== '' && ( \$option['status'] ?? '' ) === 'active' ) { \$headers['X-UUPD-License-Key'] = \$key; }\n";
        $boot .= "    return \$headers;\n} );\n";
        $inline = "\\UUPD\\UI\\License\\V1\\UUPD_License_UI::render_box_for( " . $C('SLUG') . ", " . $C('VENDOR') . " );\n";
        return ['constants'=>$const, 'bootloader'=>$boot, 'inline'=>$inline];
    }

    /** Validate WP core menu parents and custom plugin parent slugs. */
    private static function safe_menu_parent($parent) {
        $parent = trim((string) $parent);
        if ($parent === 'top_level') { return 'options-general.php'; } // Legacy setting.
        if (in_array($parent, ['options-general.php','tools.php','themes.php','plugins.php','users.php','index.php','upload.php','edit.php','admin.php'], true)) { return $parent; }
        if (preg_match('~^[a-zA-Z0-9_-]+(?:\.php)?$~', $parent)) { return $parent; }
        return 'options-general.php';
    }

    /** Reject absolute paths, traversal and non-PHP includes. */
    private static function safe_path($path, $default) {
        $path = str_replace('\\', '/', trim((string) $path));
        $path = ltrim($path, './');
        if ($path === '' || strpos($path, '..') !== false || strpos($path, ':') !== false || !preg_match('~^[a-zA-Z0-9_./-]+\.php$~', $path)) { return $default; }
        return $path;
    }

    public static function enqueue_media($hook) {
        if (in_array($hook, ['post.php','post-new.php'], true) && get_post_type() === 'product') { wp_enqueue_media(); }
    }

    /** Extra artwork belongs in the existing Stackless Licensing tab. */
    public static function artwork_panel() {
        global $post;
        if (!$post || $post->post_type !== 'product') { return; }
        echo '<div id="stackless-uupd-artwork" class="options_group" style="display:none">';
        echo '<p style="padding:10px 12px"><strong>WordPress update artwork</strong> — optional image URLs; shown in plugin/theme update metadata. The product image remains the fallback.</p>';
        foreach (['icon_1x'=>'Icon 128 × 128 (1x)', 'icon_2x'=>'Icon 256 × 256 (2x)', 'banner_low'=>'Banner 772 × 250', 'banner_high'=>'Banner 1544 × 500'] as $key=>$label) {
            woocommerce_wp_text_input(['id'=>self::META_PREFIX . $key,'label'=>$label,'value'=>get_post_meta($post->ID, self::META_PREFIX . $key, true),'type'=>'url','placeholder'=>'https://','description'=>'Direct HTTPS image URL','desc_tip'=>true]);
        }
        echo '<p style="padding:0 12px"><button type="button" class="button" id="stackless-uupd-pick-artwork">Select image for focused field</button> <span class="description">Click an image URL field, then select an image from Media Library.</span></p>';
        echo '</div>';
        echo '<script>jQuery(function($){let selected=$("#_stackless_uupd_icon_1x");$("#stackless-uupd-artwork").on("focus","input[type=url]",function(){selected=$(this);});$("#stackless-uupd-pick-artwork").on("click",function(){if(typeof wp==="undefined"||!wp.media)return;const frame=wp.media({title:"Choose WordPress update artwork",button:{text:"Use image"},multiple:false,library:{type:"image"}});frame.on("select",function(){const media=frame.state().get("selection").first().toJSON();selected.val(media.url).trigger("change");});frame.open();});const group=$("#stackless-uupd-artwork");const dest=$("#stackless_licensing_data");if(dest.length){group.appendTo(dest).show();function toggle(){group.toggle($("#_stackless_sl_enabled").is(":checked"));}toggle();$("#_stackless_sl_enabled").on("change",toggle);}});</script>';
    }

    /** Stackless version filter arguments are ($data, $license, $product), unlike info. */
    public static function version_artwork_payload($data, $license, $product) {
        return self::artwork_payload($data, $product);
    }

    public static function artwork_payload($data, $product) {
        if (!is_array($data) || !($product instanceof WC_Product)) { return $data; }
        $product_id = $product->get_parent_id() ?: $product->get_id();
        $icons = isset($data['icons']) && is_array($data['icons']) ? $data['icons'] : [];
        $banners = isset($data['banners']) && is_array($data['banners']) ? $data['banners'] : [];
        foreach (['icon_1x'=>'1x','icon_2x'=>'2x'] as $key=>$size) {
            $url = get_post_meta($product_id, self::META_PREFIX . $key, true);
            if ($url) { $icons[$size] = esc_url_raw($url); }
        }
        foreach (['banner_low'=>'low','banner_high'=>'high'] as $key=>$size) {
            $url = get_post_meta($product_id, self::META_PREFIX . $key, true);
            if ($url) { $banners[$size] = esc_url_raw($url); }
        }
        if (!empty($icons)) { $data['icons'] = $icons; }
        if (!empty($banners)) { $data['banners'] = $banners; }
        return $data;
    }

}
Stackless_UUPD_Bridge::boot();
}
