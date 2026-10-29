<?php
/**
 * Plugin Name: UUPD Theme Bootloader Generator
 * Description: Adds Tools > Theme Bootloader to generate UUPD theme bootloaders from active compatibility layers.
 * Version: 1.3
 * Author: Reallyusefulplugins.com
 * Author URI: https://reallyusefulplugins.com
 * Requires PHP: 7.4
 * Website: https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Shared site option used by UUPD compatibility metadata endpoints.
if ( ! function_exists( 'uupd_allow_query_license_key' ) ) {
    function uupd_allow_query_license_key() {
        if ( defined( 'UUPD_ALLOW_QUERY_LICENSE_KEY' ) ) { return (bool) UUPD_ALLOW_QUERY_LICENSE_KEY; }
        return (bool) apply_filters( 'uupd_allow_query_license_key', '1' === (string) get_option( 'uupd_allow_query_license_key', '1' ) );
    }
}
if ( ! function_exists( 'uupd_compat_register_transport_option' ) ) {
    function uupd_compat_register_transport_option() {
        register_setting( 'uupd_compat_transport', 'uupd_allow_query_license_key', [
            'type' => 'string', 'default' => '1',
            'sanitize_callback' => static function ( $value ) { return '1' === (string) $value ? '1' : '0'; },
        ] );
    }
}
if ( ! function_exists( 'uupd_compat_register_transport_settings' ) ) {
    function uupd_compat_register_transport_settings() {
        add_options_page( 'UUPD Key Transport', 'UUPD Key Transport', 'manage_options', 'uupd-key-transport', 'uupd_compat_render_transport_settings' );
    }
}
if ( ! function_exists( 'uupd_compat_render_transport_settings' ) ) {
    function uupd_compat_render_transport_settings() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        ?>
        <div class="wrap"><h1><?php esc_html_e( 'UUPD Key Transport' ); ?></h1>
        <p><?php esc_html_e( 'Legacy URL query keys are enabled by default for backwards compatibility. Disable them after clients use Authorization: Bearer. This shared site setting is used by installed UUPD metadata adapters.' ); ?></p>
        <form method="post" action="options.php">
            <?php settings_fields( 'uupd_compat_transport' ); ?>
            <input type="hidden" name="uupd_allow_query_license_key" value="0">
            <label><input type="checkbox" name="uupd_allow_query_license_key" value="1" <?php checked( get_option( 'uupd_allow_query_license_key', '1' ), '1' ); ?>> <?php esc_html_e( 'Accept licence keys from the legacy metadata key query parameter' ); ?></label>
            <?php submit_button(); ?>
        </form></div>
        <?php
    }
}
if ( false === has_action( 'admin_init', 'uupd_compat_register_transport_option' ) ) { add_action( 'admin_init', 'uupd_compat_register_transport_option' ); }
if ( false === has_action( 'admin_menu', 'uupd_compat_register_transport_settings' ) ) { add_action( 'admin_menu', 'uupd_compat_register_transport_settings' ); }


if ( ! class_exists( 'UUPD_Theme_Bootloader_Generator' ) ) {
    final class UUPD_Theme_Bootloader_Generator {
        const MENU_SLUG = 'uupd-theme-bootloader';

        public static function init() {
            if ( ! is_admin() ) {
                return;
            }

            add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
            add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        }

        public static function register_menu() {
            add_submenu_page(
                'tools.php',
                'Theme Bootloader',
                'Theme Bootloader',
                'manage_options',
                self::MENU_SLUG,
                [ __CLASS__, 'render_page' ]
            );
        }

        public static function enqueue_assets( $hook ) {
            if ( 'tools_page_' . self::MENU_SLUG !== $hook ) {
                return;
            }

            wp_register_script( 'uupd-theme-bootloader-inline', '', [], '1.2.0', true );
            wp_enqueue_script( 'uupd-theme-bootloader-inline' );
            wp_add_inline_script(
                'uupd-theme-bootloader-inline',
                "document.addEventListener('click', async function(e){
                    const btn = e.target.closest('[data-uupd-copy-target]');
                    if(!btn) return;
                    const el = document.getElementById(btn.getAttribute('data-uupd-copy-target'));
                    if(!el) return;
                    try {
                        await navigator.clipboard.writeText(el.value || el.textContent || '');
                        const old = btn.textContent;
                        btn.textContent = 'Copied';
                        setTimeout(function(){ btn.textContent = old; }, 1200);
                    } catch(err) {
                        if (el.select) { el.select(); }
                        window.prompt('Copy the generated bootloader:', el.value || el.textContent || '');
                    }
                });"
            );
        }

        protected static function get_detected_providers() {
            $providers = [];

            if ( defined( 'GET_PAID_UUPD_VERSION' ) ) {
                $providers['getpaid'] = [
                    'label'     => 'Get Paid',
                    'post_type' => 'wpi_item',
                ];
            }

            if ( class_exists( 'EDD_UUPD_Compatibility_Layer' ) ) {
                $providers['edd'] = [
                    'label'     => 'EDD',
                    'post_type' => 'download',
                ];
            }

            if ( class_exists( 'UUPD_FluentCart_Compatibility_Layer' ) ) {
                $providers['fluentcart'] = [
                    'label'     => 'FluentCart',
                    'post_type' => 'fluent-products',
                ];
            }

            if ( class_exists( 'UUPD_Hoster_Compatibility_Layer' ) ) {
                $providers['hoster'] = [
                    'label'     => 'Hoster',
                    'post_type' => 'downloads',
                ];
            }

            if ( class_exists( 'UUPD_WSL_Compatibility_Layer' ) ) {
                $providers['wsl'] = [
                    'label'     => 'WSL',
                    'post_type' => 'product',
                ];
            }

            if ( class_exists( 'UUPD_WC_AM_Compat_Layer' ) ) {
                $providers['wooapi'] = [
                    'label'     => 'Woo API',
                    'post_type' => 'product',
                ];
            }

            return $providers;
        }

        protected static function get_provider_products( $provider ) {
            $providers = self::get_detected_providers();
            if ( empty( $providers[ $provider ] ) ) {
                return [];
            }

            $post_type = $providers[ $provider ]['post_type'];
            $posts     = get_posts(
                [
                    'post_type'      => $post_type,
                    'posts_per_page' => 500,
                    'post_status'    => [ 'publish', 'private', 'draft', 'pending', 'future' ],
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                ]
            );

            $items = [];
            foreach ( $posts as $post ) {
                $item = self::build_product_record( $provider, $post );
                if ( ! empty( $item['item_id'] ) && ! empty( $item['slug'] ) ) {
                    $items[ $item['item_id'] ] = $item;
                }
            }

            return $items;
        }

        protected static function build_product_record( $provider, WP_Post $post ) {
            $slug           = '';
            $version        = '';
            $provider_label = strtoupper( $provider );

            switch ( $provider ) {
                case 'getpaid':
                    $slug = (string) get_post_meta( $post->ID, '_uupd_plugin_slug', true );
                    if ( '' === $slug ) {
                        $slug = (string) get_post_field( 'post_name', $post->ID );
                    }
                    $version = (string) get_post_meta( $post->ID, 'getpaid_license_manager_item_version', true );
                    break;

                case 'edd':
                    $slug = (string) get_post_meta( $post->ID, '_uupd_plugin_slug', true );
                    if ( '' === $slug ) {
                        $slug = (string) get_post_meta( $post->ID, '_edd_sl_item_shortname', true );
                    }
                    if ( '' === $slug ) {
                        $slug = (string) get_post_field( 'post_name', $post->ID );
                    }
                    $version = (string) get_post_meta( $post->ID, '_edd_sl_version', true );
                    break;

                case 'fluentcart':
                    $slug = (string) get_post_meta( $post->ID, '_uupd_plugin_slug', true );
                    if ( '' === $slug ) {
                        $slug = (string) get_post_field( 'post_name', $post->ID );
                    }
                    $version = (string) get_post_meta( $post->ID, '_uupd_current_version', true );
                    global $wpdb;
                    if ( '' === $version && isset( $wpdb ) ) {
                        $table = $wpdb->prefix . 'fct_product_meta';
                        $json  = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT meta_value FROM {$table} WHERE object_id = %d AND meta_key = %s LIMIT 1",
                                $post->ID,
                                'license_settings'
                            )
                        );
                        if ( is_string( $json ) && '' !== $json ) {
                            $data = json_decode( $json, true );
                            if ( is_array( $data ) && ! empty( $data['version'] ) ) {
                                $version = (string) $data['version'];
                            }
                        }
                    }
                    break;

                case 'hoster':
                    $slug = (string) get_post_meta( $post->ID, '_uupd_plugin_slug', true );
                    if ( '' === $slug ) {
                        $slug = (string) get_post_field( 'post_name', $post->ID );
                    }
                    $version = (string) get_post_meta( $post->ID, '_uupd_current_version', true );
                    break;

                case 'wsl':
                    $slug = (string) get_post_meta( $post->ID, '_uupd_plugin_slug', true );
                    if ( '' === $slug ) {
                        $slug = (string) get_post_meta( $post->ID, '_sl_software_title', true );
                    }
                    if ( '' === $slug ) {
                        $slug = (string) get_post_field( 'post_name', $post->ID );
                    }
                    $version = (string) get_post_meta( $post->ID, '_sl_new_version', true );
                    if ( '' === $version ) {
                        $version = (string) get_post_meta( $post->ID, '_uupd_current_version', true );
                    }
                    break;

                case 'wooapi':
                    $slug = (string) get_post_meta( $post->ID, '_uupd_plugin_slug', true );
                    if ( '' === $slug ) {
                        $slug = (string) get_post_field( 'post_name', $post->ID );
                    }
                    $version = (string) get_post_meta( $post->ID, '_uupd_current_version', true );
                    break;
            }

            if ( '' === $version ) {
                $version = '1.0.0';
            }

            return [
                'item_id'  => (int) $post->ID,
                'slug'     => sanitize_title( $slug ),
                'name'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
                'version'  => $version,
                'provider' => $provider,
                'source'   => $provider_label,
            ];
        }

        protected static function build_constant_stem( $vendor, $theme_name, $theme_slug ) {
            $base = trim( (string) $vendor ) . '_' . ( $theme_name ?: $theme_slug );
            $base = strtoupper( preg_replace( '/[^A-Z0-9]+/i', '_', (string) $base ) );
            $base = trim( preg_replace( '/_+/', '_', $base ), '_' );

            if ( '' === $base ) {
                $base = 'THEME';
            }

            return 'UUPD_' . $base;
        }

        protected static function build_func_prefix( $theme_slug ) {
            $prefix = strtolower( preg_replace( '/[^a-z0-9]+/i', '_', (string) $theme_slug ) );
            $prefix = trim( preg_replace( '/_+/', '_', $prefix ), '_' );

            return $prefix ?: 'uupd_theme';
        }

        protected static function esc_php_single( $value ) {
            return str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], (string) $value );
        }

        protected static function generate_bootloader( array $args ) {
            $const              = self::build_constant_stem( $args['vendor'], $args['theme_name'], $args['theme_slug'] );
            $func               = self::build_func_prefix( $args['theme_slug'] );
            $textdomain         = sanitize_key( $args['theme_slug'] ?: 'uupd-theme' );
            $allow_opt          = 'uupd_' . $func . '_allow_prerelease';
            $settings_page_slug = 'uupd-' . sanitize_title( $args['theme_slug'] ) . '-settings';
            $menu_mode = in_array( $args['menu_mode'] ?? 'settings', [ 'top_level', 'settings', 'custom_parent', 'embedded' ], true ) ? $args['menu_mode'] : 'settings';
            $parent = $args['menu_parent'] ?? '';
            $return_path = $args['return_path'] ?? '';
            $title_menu = $args['menu_title'] ?: $args['theme_name'] . ' Licence';
            $title_page = $args['page_title'] ?: $args['theme_name'] . ' Licence';

            $licence_page_slug  = 'uupd-' . sanitize_title( $args['theme_slug'] ) . '-licence';

            $theme_name_esc = self::esc_php_single( $args['theme_name'] );
            $theme_slug_esc = self::esc_php_single( $args['theme_slug'] );
            $vendor_esc     = self::esc_php_single( $args['vendor'] );
            $server_esc     = self::esc_php_single( untrailingslashit( $args['server'] ) );
            $license_ui_rel = self::esc_php_single( $args['license_ui_rel'] );
            $updater_rel    = self::esc_php_single( $args['updater_rel'] );
            $provider_label = self::esc_php_single( strtoupper( $args['provider'] ) );

            $lines   = [];
            $lines[] = "<?php";
            $lines[] = "/**";
            $lines[] = " * UUPD licensing + UUPD updater bootstrap for a THEME (UUPD provider)";
            $lines[] = " * Slug:  {$theme_slug_esc}";
            $lines[] = " * Server: {$server_esc}";
            $lines[] = " * Item: " . (int) $args['item_id'];
            $lines[] = " *";
            $lines[] = " * Requires these files in your theme:";
            $lines[] = " *   {$updater_rel}";
            $lines[] = " *   {$license_ui_rel}";
            $lines[] = " */";
            $lines[] = "";
            $lines[] = "defined('ABSPATH') || exit;";
            $lines[] = "";
            $lines[] = "/// Generated by UUPD Theme Bootloader Generator";
            $lines[] = "// Source provider: {$provider_label}";
            $lines[] = "";
            $lines[] = "// ---- Config ----";
            $lines[] = "define( '{$const}_VENDOR', '{$vendor_esc}' );";
            $lines[] = "define( '{$const}_SLUG', '{$theme_slug_esc}' );";
            $lines[] = "define( '{$const}_NAME', '{$theme_name_esc}' );";
            $lines[] = "define( '{$const}_SERVER', '{$server_esc}' ); // no trailing / needed";
            $lines[] = "define( '{$const}_ITEM_ID', " . (int) $args['item_id'] . " );";
            $lines[] = "";
            $lines[] = "/**";
            $lines[] = " * Optional debug";
            $lines[] = " */";
            $lines[] = "// add_filter( 'updater_enable_debug', '__return_true' );";
            $lines[] = "";
            $lines[] = "function {$func}_require_file( \$relative_path ) {";
            $lines[] = "    \$path = trailingslashit( get_stylesheet_directory() ) . ltrim( \$relative_path, '/' );";
            $lines[] = "    if ( file_exists( \$path ) ) {";
            $lines[] = "        require_once \$path;";
            $lines[] = "        return true;";
            $lines[] = "    }";
            $lines[] = "    return false;";
            $lines[] = "}";
            $lines[] = "";
            $lines[] = "/**";
            $lines[] = " * 1) Register the UUPD License UI instance EARLY (admin only).";
            $lines[] = " *    This is important for wp-config constant auto-activation so the key";
            $lines[] = " *    is available before the updater fetches metadata.";
            $lines[] = " *";
            $lines[] = " * NOTE: after_setup_theme runs before init.";
            $lines[] = " */";
            $lines[] = "add_action( 'after_setup_theme', function() {";
            $lines[] = "    if ( ! is_admin() ) {";
            $lines[] = "        return;";
            $lines[] = "    }";
            $lines[] = "";
            $lines[] = "    if ( ! {$func}_require_file( '{$license_ui_rel}' ) ) {";
            $lines[] = "        return;";
            $lines[] = "    }";
            $lines[] = "";
            $lines[] = "    if ( ! class_exists( '\\\\UUPD\\\\UI\\\\License\\\\V1\\\\UUPD_License_UI' ) ) {";
            $lines[] = "        return;";
            $lines[] = "    }";
            $lines[] = "";
            $lines[] = "    \$theme   = wp_get_theme();";
            $lines[] = "    \$version = \$theme->get( 'Version' ) ?: '0.0.0';";
            $lines[] = "";
            $lines[] = "    \\UUPD\\UI\\License\\V1\\UUPD_License_UI::register( [";
            $lines[] = "        'vendor'           => {$const}_VENDOR,";
            $lines[] = "        'slug'             => {$const}_SLUG,";
            $lines[] = "        'item_id'          => {$const}_ITEM_ID,";
            $lines[] = "        'plugin_name'      => {$const}_NAME,";
            $lines[] = "        'license_server'   => {$const}_SERVER,";
            $lines[] = "        'metadata_base'    => {$const}_SERVER,";
            $lines[] = "        'license_provider' => 'uupd',";
            $lines[] = "        'current_version'  => \$version,";
            $lines[] = "        'menu_parent'      => false,";
            $lines[] = "        'menu_slug'        => '{$licence_page_slug}',";
            if ( $menu_mode === 'embedded' ) {
                $lines[] = "        'return_url'       => admin_url( '" . self::esc_php_single($return_path) . "' ),";
            } else {
                $dest = $menu_mode === 'top_level' ? 'admin.php' : ( $menu_mode === 'custom_parent' ? $parent : 'options-general.php' );
                $url = $menu_mode === 'custom_parent' ? 'admin.php?page=' . $licence_page_slug : $dest . '?page=' . $licence_page_slug;
                $lines[] = "        'return_url'       => admin_url( '" . self::esc_php_single($url) . "' ),";
            }
            $lines[] = "    ] );";
            $lines[] = "}, 1 );";
            $lines[] = "";
            $lines[] = "/**";
            $lines[] = " * 2) Register the updater (theme updates) AFTER the UI has registered its filters.";
            $lines[] = " */";
            $lines[] = "add_action( 'after_setup_theme', function() {";
            $lines[] = "    if ( ! {$func}_require_file( '{$updater_rel}' ) ) {";
            $lines[] = "        return;";
            $lines[] = "    }";
            $lines[] = "";
            $lines[] = "    \$theme   = wp_get_theme();";
            $lines[] = "    \$version = \$theme->get( 'Version' ) ?: '0.0.0';";
            $lines[] = "";
            $lines[] = "    if ( class_exists( '\\\\UUPD\\\\V2\\\\UUPD_Updater_V2' ) ) {";
            $lines[] = "        \\UUPD\\V2\\UUPD_Updater_V2::register( [";
            $lines[] = "            'vendor'           => {$const}_VENDOR,";
            $lines[] = "            'slug'             => {$const}_SLUG,";
            $lines[] = "            'real_slug'        => {$const}_SLUG,";
            $lines[] = "            'name'             => {$const}_NAME,";
            $lines[] = "            'version'          => \$version,";
            $lines[] = "            'server'           => {$const}_SERVER,";
		$lines[] = "            'license_key_transport' => 'header',";
            $lines[] = "            'allow_prerelease' => (bool) get_option( '{$allow_opt}', false ),";
            $lines[] = "        ] );";
            $lines[] = "    }";
            $lines[] = "}, 5 );";
            $lines[] = "";
            $lines[] = "/**";
            $lines[] = " * 3) Apply prerelease option instantly (vendor-aware)";
            $lines[] = " */";
            $lines[] = "add_filter(";
            $lines[] = "    'uupd/allow_prerelease/' . {$const}_VENDOR . '/' . {$const}_SLUG,";
            $lines[] = "    function( \$allow, \$vendor, \$slug ) {";
            $lines[] = "        return (bool) get_option( '{$allow_opt}', false );";
            $lines[] = "    },";
            $lines[] = "    10,";
            $lines[] = "    3";
            $lines[] = ");";
            $lines[] = "";
            $lines[] = "/**";
            $lines[] = " * 4) Update settings under Appearance → {$theme_name_esc}";
            $lines[] = " */";
            $lines[] = "add_action( 'admin_init', function() {";
            $lines[] = "    register_setting(";
            $lines[] = "        '{$func}_settings',";
            $lines[] = "        '{$allow_opt}',";
            $lines[] = "        [";
            $lines[] = "            'type'              => 'boolean',";
            $lines[] = "            'sanitize_callback' => static function( \$v ) {";
            $lines[] = "                return (int) ! empty( \$v );";
            $lines[] = "            },";
            $lines[] = "            'default'           => 0,";
            $lines[] = "        ]";
            $lines[] = "    );";
            $lines[] = "";
            $lines[] = "    add_settings_section(";
            $lines[] = "        '{$func}_updates',";
            $lines[] = "        __( 'Update Settings', '{$textdomain}' ),";
            $lines[] = "        '__return_false',";
            $lines[] = "        '{$settings_page_slug}'";
            $lines[] = "    );";
            $lines[] = "";
            $lines[] = "    add_settings_field(";
            $lines[] = "        '{$func}_allow_prerelease_field',";
            $lines[] = "        __( 'Allow prereleases', '{$textdomain}' ),";
            $lines[] = "        function() {";
            $lines[] = "            \$val = (bool) get_option( '{$allow_opt}', false );";
            $lines[] = "            ?>";
            $lines[] = "            <label>";
            $lines[] = "                <input type=\"checkbox\" name=\"{$allow_opt}\" value=\"1\" <?php checked( \$val, true ); ?> />";
            $lines[] = "                <?php esc_html_e( 'Enable beta/RC/dev updates', '{$textdomain}' ); ?>";
            $lines[] = "            </label>";
            $lines[] = "            <p class=\"description\">";
            $lines[] = "                <?php esc_html_e( 'When enabled, the updater will offer pre-release versions (alpha, beta, RC, dev).', '{$textdomain}' ); ?>";
            $lines[] = "            </p>";
            $lines[] = "            <?php";
            $lines[] = "        },";
            $lines[] = "        '{$settings_page_slug}',";
            $lines[] = "        '{$func}_updates'";
            $lines[] = "    );";
            $lines[] = "} );";
            $lines[] = "";
            $lines[] = "add_action( 'admin_menu', function() {";
            $lines[] = "    add_theme_page(";
            $lines[] = "        __( '{$theme_name_esc}', '{$textdomain}' ),";
            $lines[] = "        __( '{$theme_name_esc}', '{$textdomain}' ),";
            $lines[] = "        'edit_theme_options',";
            $lines[] = "        '{$settings_page_slug}',";
            $lines[] = "        '{$func}_render_settings_page'";
            $lines[] = "    );";
            $lines[] = "} );";
            $lines[] = "";
            $lines[] = "function {$func}_render_settings_page() {";
            $lines[] = "    if ( ! current_user_can( 'edit_theme_options' ) ) {";
            $lines[] = "        return;";
            $lines[] = "    }";
            $lines[] = "";
            $lines[] = "    \$slug      = {$const}_SLUG;";
            $lines[] = "    \$nonce     = wp_create_nonce( 'uupd_manual_check_' . \$slug );";
            $lines[] = "    \$check_url = admin_url(";
            $lines[] = "        sprintf(";
            $lines[] = "            'admin.php?action=uupd_manual_check&slug=%s&_wpnonce=%s',";
            $lines[] = "            rawurlencode( \$slug ),";
            $lines[] = "            \$nonce";
            $lines[] = "        )";
            $lines[] = "    );";
            $lines[] = "    ?>";
            $lines[] = "    <div class=\"wrap\">";
            $lines[] = "        <h1><?php echo esc_html( {$const}_NAME ); ?></h1>";
            $lines[] = "";
            $lines[] = "        <form method=\"post\" action=\"options.php\">";
            $lines[] = "            <?php";
            $lines[] = "            settings_fields( '{$func}_settings' );";
            $lines[] = "            do_settings_sections( '{$settings_page_slug}' );";
            $lines[] = "            submit_button( __( 'Save Changes', '{$textdomain}' ) );";
            $lines[] = "            ?>";
            $lines[] = "        </form>";
            $lines[] = "";
            $lines[] = "        <hr />";
            $lines[] = "        <p><?php esc_html_e( 'Need to refresh update data now?', '{$textdomain}' ); ?></p>";
            $lines[] = "        <p>";
            $lines[] = "            <a class=\"button button-secondary\" href=\"<?php echo esc_url( \$check_url ); ?>\">";
            $lines[] = "                <?php esc_html_e( 'Check for updates now', '{$textdomain}' ); ?>";
            $lines[] = "            </a>";
            $lines[] = "        </p>";
            $lines[] = "    </div>";
            $lines[] = "    <?php";
            $lines[] = "}";
            $lines[] = "";
            $lines[] = "/**";
            $lines[] = " * 5) Licensing: Settings → {$theme_name_esc} Licence";
            $lines[] = " */";
            $lines[] = "add_action( 'admin_menu', function() {";
            if ($menu_mode !== 'embedded') {
                $menu_fn = $menu_mode === 'top_level' ? 'add_menu_page' : ( $menu_mode === 'settings' ? 'add_options_page' : 'add_submenu_page' );
                $lines[] = "    {$menu_fn}(";
                if ($menu_mode === 'custom_parent') $lines[] = "        '" . self::esc_php_single($parent) . "',";
                $lines[] = "        '" . self::esc_php_single($title_page) . "',";
                $lines[] = "        '" . self::esc_php_single($title_menu) . "',";
                $lines[] = "        'manage_options',";
                $lines[] = "        '{$licence_page_slug}',";
                $lines[] = "        '{$func}_render_licence_page'";
                $lines[] = "    );";
            }
            $lines[] = "} );";
            $lines[] = "";
            $lines[] = "function {$func}_render_licence_page() {";
            $lines[] = "    if ( ! current_user_can( 'manage_options' ) ) {";
            $lines[] = "        return;";
            $lines[] = "    }";
            $lines[] = "";
            $lines[] = "    if ( ! class_exists( '\\\\UUPD\\\\UI\\\\License\\\\V1\\\\UUPD_License_UI' ) ) {";
            $lines[] = "        echo '<div class=\"wrap\"><div class=\"notice notice-error\"><p>';";
            $lines[] = "        echo esc_html__( 'License UI class not loaded. Check your bundled UUPD files exist in the active theme.', '{$textdomain}' );";
            $lines[] = "        echo '</p></div></div>';";
            $lines[] = "        return;";
            $lines[] = "    }";
            $lines[] = "";
            $lines[] = "    \$inst = \\UUPD\\UI\\License\\V1\\UUPD_License_UI::get_instance(";
            $lines[] = "        {$const}_VENDOR,";
            $lines[] = "        {$const}_SLUG";
            $lines[] = "    );";
            $lines[] = "";
            $lines[] = "    if ( ! \$inst ) {";
            $lines[] = "        echo '<div class=\"wrap\"><div class=\"notice notice-error\"><p>';";
            $lines[] = "        echo esc_html__( 'License handler not initialized. Ensure registration is running for admin requests.', '{$textdomain}' );";
            $lines[] = "        echo '</p></div></div>';";
            $lines[] = "        return;";
            $lines[] = "    }";
            $lines[] = "";
            $lines[] = "    \$inst->render_page();";
            $lines[] = "}";

            return implode( "\n", $lines );
        }

        public static function render_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $providers          = self::get_detected_providers();
            $selected_provider  = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';

            if ( '' === $selected_provider && ! empty( $providers ) ) {
                $selected_provider = (string) array_key_first( $providers );
            }

            if ( ! isset( $providers[ $selected_provider ] ) ) {
                $selected_provider = '';
            }

            $products          = $selected_provider ? self::get_provider_products( $selected_provider ) : [];
            $selected_item_id  = isset( $_GET['item_id'] ) ? absint( wp_unslash( $_GET['item_id'] ) ) : 0;
            $selected_product  = $selected_item_id && isset( $products[ $selected_item_id ] ) ? $products[ $selected_item_id ] : reset( $products );

            if ( ! is_array( $selected_product ) ) {
                $selected_product = [];
            }

            $default_theme_name = ! empty( $selected_product['name'] ) ? $selected_product['name'] : 'Example Theme';
            $default_theme_slug = ! empty( $selected_product['slug'] ) ? $selected_product['slug'] : 'example-theme';
            $default_server     = home_url();

            $theme_name     = isset( $_GET['theme_name'] ) ? sanitize_text_field( wp_unslash( $_GET['theme_name'] ) ) : $default_theme_name;
            $theme_slug     = isset( $_GET['theme_slug'] ) ? sanitize_title( wp_unslash( $_GET['theme_slug'] ) ) : $default_theme_slug;
            $vendor         = isset( $_GET['vendor'] ) ? sanitize_key( wp_unslash( $_GET['vendor'] ) ) : 'yourvendor';
            $server         = isset( $_GET['server'] ) ? esc_url_raw( wp_unslash( $_GET['server'] ) ) : $default_server;
            $license_ui_rel = isset( $_GET['license_ui_rel'] ) ? sanitize_text_field( wp_unslash( $_GET['license_ui_rel'] ) ) : '/uupd/class-uupd-license-ui.php';
            $menu_mode = isset( $_GET['menu_mode'] ) ? sanitize_key( wp_unslash( $_GET['menu_mode'] ) ) : 'settings';
            if ( ! in_array( $menu_mode, [ 'top_level', 'settings', 'custom_parent', 'embedded' ], true ) ) $menu_mode = 'settings';
            $menu_parent = isset( $_GET['menu_parent'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['menu_parent'] ) ) ) : '';
            if ( preg_match( '~^admin\.php\?page=([a-zA-Z0-9_-]+)$~D', $menu_parent, $m ) ) $menu_parent = $m[1];
            $return_path = isset( $_GET['return_path'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['return_path'] ) ) ) : '';
            $menu_title = isset( $_GET['menu_title'] ) ? sanitize_text_field( wp_unslash( $_GET['menu_title'] ) ) : '';
            $page_title = isset( $_GET['page_title'] ) ? sanitize_text_field( wp_unslash( $_GET['page_title'] ) ) : '';
            $valid_parent = preg_match( '~^(?:[a-zA-Z0-9_-]+|[a-zA-Z0-9_-]+\.php|edit\.php\?post_type=[a-zA-Z0-9_-]+)$~D', $menu_parent );
            $valid_return = preg_match( '~^(?:admin|options-general|tools|themes|plugins|users|index|upload|edit)\.php\?page=[a-zA-Z0-9_-]+(?:\#[a-zA-Z0-9_-]+)?$~D', $return_path );
            $menu_error = $menu_mode === 'custom_parent' && ! $valid_parent ? 'Enter an existing registered parent menu slug.' : ( $menu_mode === 'embedded' && ! $valid_return ? 'Inline-only requires an admin return path, e.g. themes.php?page=my-settings#licence.' : '' );
            $updater_rel    = isset( $_GET['updater_rel'] ) ? sanitize_text_field( wp_unslash( $_GET['updater_rel'] ) ) : '/uupd/updater.php';

            if ( '' === $vendor ) {
                $vendor = 'yourvendor';
            }

            $bootloader = '';
            if ( ! empty( $selected_product ) && ! $menu_error ) {
                $bootloader = self::generate_bootloader(
                    [
                        'provider'       => $selected_provider,
                        'item_id'        => (int) $selected_product['item_id'],
                        'theme_name'     => $theme_name,
                        'theme_slug'     => $theme_slug,
                        'vendor'         => $vendor,
                        'server'         => $server,
                        'license_ui_rel' => $license_ui_rel,
                        'updater_rel'    => $updater_rel,
                        'menu_mode' => $menu_mode, 'menu_parent' => $menu_parent, 'return_path' => $return_path, 'menu_title' => $menu_title, 'page_title' => $page_title,
                    ]
                );
            }

            echo '<div class="wrap">';
            echo '<h1>Theme Bootloader</h1>';
            echo '<p>Detects active UUPD compatibility layers and generates a theme bootloader for the selected item.</p>';

            if ( empty( $providers ) ) {
                echo '<div class="notice notice-warning"><p>No supported compatibility layers were detected. Activate one of: Get Paid, EDD, FluentCart, Hoster, Woo API, or WSL UUPD compatibility plugins.</p></div>';
                echo '</div>';
                return;
            }

            echo '<form method="get">';
            echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
            echo '<table class="form-table" role="presentation">';
            echo '<tbody>';

            echo '<tr><th scope="row"><label for="uupd-provider">Provider</label></th><td>';
            echo '<select id="uupd-provider" name="provider" onchange="this.form.submit()">';
            foreach ( $providers as $provider_key => $provider_data ) {
                echo '<option value="' . esc_attr( $provider_key ) . '" ' . selected( $selected_provider, $provider_key, false ) . '>' . esc_html( $provider_data['label'] ) . '</option>';
            }
            echo '</select>';
            echo '</td></tr>';

            echo '<tr><th scope="row"><label for="uupd-item-id">Source item</label></th><td>';
            echo '<select id="uupd-item-id" name="item_id" onchange="this.form.submit()">';
            foreach ( $products as $product ) {
                $label = sprintf( '%s (#%d · %s)', $product['name'], $product['item_id'], $product['slug'] );
                echo '<option value="' . esc_attr( (string) $product['item_id'] ) . '" ' . selected( $selected_item_id ?: (int) ( $selected_product['item_id'] ?? 0 ), (int) $product['item_id'], false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select>';
            echo '</td></tr>';

            echo '<tr><th scope="row"><label for="uupd-theme-name">Theme name</label></th><td><input class="regular-text" id="uupd-theme-name" type="text" name="theme_name" value="' . esc_attr( $theme_name ) . '" /></td></tr>';
            echo '<tr><th scope="row"><label for="uupd-theme-slug">Theme slug</label></th><td><input class="regular-text" id="uupd-theme-slug" type="text" name="theme_slug" value="' . esc_attr( $theme_slug ) . '" /></td></tr>';
            echo '<tr><th scope="row"><label for="uupd-vendor">Vendor slug</label></th><td><input class="regular-text" id="uupd-vendor" type="text" name="vendor" value="' . esc_attr( $vendor ) . '" /><p class="description">Used in generated constants and vendor-aware registration.</p></td></tr>';
            echo '<tr><th scope="row"><label for="uupd-server">Server base</label></th><td><input class="regular-text code" id="uupd-server" type="url" name="server" value="' . esc_attr( $server ) . '" /><p class="description">Usually the current site URL where the compatibility layer is installed.</p></td></tr>';
            echo '<tr><th scope="row"><label for="uupd-license-ui-rel">License UI file</label></th><td><input class="regular-text code" id="uupd-license-ui-rel" type="text" name="license_ui_rel" value="' . esc_attr( $license_ui_rel ) . '" /></td></tr>';
            echo '<tr><th>Licence menu placement</th><td><select id="uupd-menu-mode" name="menu_mode">';
            foreach ( ['settings'=>'Settings submenu (existing default)','top_level'=>'Top-level menu','custom_parent'=>'Submenu under another plugin','embedded'=>'Inline only (existing page)'] as $v=>$label ) echo '<option value="' . esc_attr($v) . '" ' . selected($menu_mode,$v,false) . '>' . esc_html($label) . '</option>';
            echo '</select></td></tr>';
            echo '<tr class="uupd-theme-parent"><th>Existing parent menu slug</th><td><input class="regular-text" name="menu_parent" value="' . esc_attr($menu_parent) . '" placeholder="esp-example-setting-page" /></td></tr>';
            echo '<tr class="uupd-theme-return"><th>Existing admin return path</th><td><input class="regular-text" name="return_path" value="' . esc_attr($return_path) . '" placeholder="themes.php?page=my-settings#licence" /></td></tr>';
            echo '<tr class="uupd-theme-title"><th>Menu title (optional)</th><td><input class="regular-text" name="menu_title" value="' . esc_attr($menu_title) . '" placeholder="Theme name + Licence" /></td></tr>';
            echo '<tr class="uupd-theme-title"><th>Page title (optional)</th><td><input class="regular-text" name="page_title" value="' . esc_attr($page_title) . '" placeholder="Theme name + Licence" /></td></tr>';
            echo '<tr><th scope="row"><label for="uupd-updater-rel">Updater file</label></th><td><input class="regular-text code" id="uupd-updater-rel" type="text" name="updater_rel" value="' . esc_attr( $updater_rel ) . '" /></td></tr>';

            echo '</tbody>';
            echo '</table>';
            echo '<script>(function(){var el=document.getElementById("uupd-menu-mode");function sync(){var v=el.value;document.querySelectorAll(".uupd-theme-parent").forEach(function(r){r.style.display=v==="custom_parent"?"":"none"});document.querySelectorAll(".uupd-theme-return").forEach(function(r){r.style.display=v==="embedded"?"":"none"});document.querySelectorAll(".uupd-theme-title").forEach(function(r){r.style.display=v==="embedded"?"none":""});}el.addEventListener("change",sync);sync();})();</script>';
            if ($menu_error) echo '<div class="notice notice-error"><p>' . esc_html($menu_error) . '</p></div>';
            submit_button( 'Generate bootloader' );
            echo '</form>';

            if ( ! empty( $selected_product ) ) {
                echo '<hr />';
                echo '<h2>Generated bootloader</h2>';
                echo '<p><strong>Detected source:</strong> ' . esc_html( $providers[ $selected_provider ]['label'] ) . ' &nbsp; <strong>Item ID:</strong> ' . esc_html( (string) $selected_product['item_id'] ) . ' &nbsp; <strong>Source slug:</strong> ' . esc_html( $selected_product['slug'] ) . ' &nbsp; <strong>Vendor:</strong> ' . esc_html( $vendor ) . '</p>';
                echo '<p><button type="button" class="button button-secondary" data-uupd-copy-target="uupd-theme-bootloader-code">Copy bootloader</button></p>';
                echo '<textarea id="uupd-theme-bootloader-code" class="large-text code" rows="44" readonly>' . esc_textarea( $bootloader ) . '</textarea>';
                $stem = self::build_constant_stem($vendor, $theme_name, $theme_slug);
                $inline = "<?php \UUPD\UI\License\V1\UUPD_License_UI::render_box_for( {$stem}_SLUG, {$stem}_VENDOR ); ?>";
                echo '<h3>Copy inline licence box</h3><p>Paste into an existing admin page only after the bootloader has registered the licence instance.</p>';
                echo '<p><button type="button" class="button" data-uupd-copy-target="uupd-theme-inline-code">Copy inline box</button></p>';
                echo '<textarea id="uupd-theme-inline-code" class="large-text code" rows="3" readonly>' . esc_textarea($inline) . '</textarea>';
            }

            echo '</div>';
        }
    }

    UUPD_Theme_Bootloader_Generator::init();
}
