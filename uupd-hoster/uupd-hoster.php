<?php
/**
 * Plugin Name: UUPD > Hoster
 * Description: Adds UUPD-compatible license and metadata endpoints on top of native Hoster licensing.
 * Version: 1.1
 * Author: Reallyusefulplugins.com
 * Author URI: https://reallyusefulplugins.com
 * Requires PHP: 7.4
 * Website: https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__ . '/uupd-generator-menu.php';


if ( ! function_exists( 'uupd_compat_metadata_key' ) ) {
    function uupd_compat_metadata_key( $request = null ) {
        $authorization = $request instanceof WP_REST_Request ? $request->get_header( 'authorization' ) : '';
        if ( '' === $authorization && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) { $authorization = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ); }
        if ( preg_match( '/^Bearer\s+(.+)$/i', trim( (string) $authorization ), $matches ) ) { return trim( $matches[1] ); }
        $query = $request instanceof WP_REST_Request ? $request->get_query_params() : $_GET;
        if ( ( isset( $query['key'] ) || isset( $query['license_key'] ) ) && ! uupd_allow_query_license_key() ) { return ''; }
        if ( $request instanceof WP_REST_Request ) { return (string) ( $request->get_param( 'key' ) ?: $request->get_param( 'license_key' ) ); }
        return isset( $_GET['key'] ) ? (string) wp_unslash( $_GET['key'] ) : ( isset( $_GET['license_key'] ) ? (string) wp_unslash( $_GET['license_key'] ) : '' );
    }
}

if ( ! function_exists( 'uupd_allow_query_license_key' ) ) {
    function uupd_allow_query_license_key() {
        if ( defined( 'UUPD_ALLOW_QUERY_LICENSE_KEY' ) ) {
            return (bool) UUPD_ALLOW_QUERY_LICENSE_KEY;
        }
        return (bool) apply_filters( 'uupd_allow_query_license_key', '1' === (string) get_option( 'uupd_allow_query_license_key', '1' ) );
    }
}
if ( ! function_exists( 'uupd_compat_register_transport_settings' ) ) {
    function uupd_compat_register_transport_option() {
        register_setting( 'uupd_compat_transport', 'uupd_allow_query_license_key', [
            'type' => 'string', 'default' => '1', 'sanitize_callback' => static function ( $value ) { return '1' === (string) $value ? '1' : '0'; },
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
        <p><?php esc_html_e( 'Legacy URL query keys are enabled by default for backwards compatibility. Disable them after clients have moved to Authorization: Bearer.' ); ?></p>
        <form method="post" action="options.php">
            <?php settings_fields( 'uupd_compat_transport' ); ?>
            <input type="hidden" name="uupd_allow_query_license_key" value="0">
            <label><input type="checkbox" name="uupd_allow_query_license_key" value="1" <?php checked( get_option( 'uupd_allow_query_license_key', '1' ), '1' ); ?>> <?php esc_html_e( 'Accept licence keys from the legacy key query parameter' ); ?></label>
            <?php submit_button(); ?>
        </form></div>
        <?php
    }
}
if ( false === has_action( 'admin_init', 'uupd_compat_register_transport_option' ) ) { add_action( 'admin_init', 'uupd_compat_register_transport_option' ); }
if ( false === has_action( 'admin_menu', 'uupd_compat_register_transport_settings' ) ) { add_action( 'admin_menu', 'uupd_compat_register_transport_settings' ); }


if ( ! class_exists( 'UUPD_Hoster_Compatibility_Layer' ) ) {

    final class UUPD_Hoster_Compatibility_Layer {
        const VERSION             = '1.0';
        const SLUG_META_KEY       = '_uupd_plugin_slug';
        const VERSION_META_KEY    = '_uupd_current_version';
        const TOKEN_OPTION_PREFIX = 'uupd_hoster_token_';
        const OPTION_VENDOR       = 'uupd_hoster_global_vendor';

        public static function init() {
            add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
            add_action( 'init', [ __CLASS__, 'maybe_handle_legacy_metadata' ], 0 );

            add_action( 'admin_menu', [ __CLASS__, 'register_settings_page' ] );
        }

        /** Code generation lives here, not in the product-list table. */
        protected static function render_generator_panel() {
            $type = self::get_product_post_type();
            $selected = isset($_GET['uupd_hoster_product']) ? absint(wp_unslash($_GET['uupd_hoster_product'])) : 0;
            $posts = get_posts(['post_type'=>$type, 'post_status'=>['publish','draft','pending','private'], 'posts_per_page'=>200, 'orderby'=>'title', 'order'=>'ASC']);
            $product = $selected ? self::resolve_product('', $selected) : [];
            $valid = $selected && !empty($product['item_id']);
            ?>
            <div class="postbox" style="padding:16px 20px;max-width:900px;margin-top:18px">
                <h2>Generate bootloader and inline licence</h2>
                <p>Save the vendor and licence-menu settings above, then choose your Hoster product. Both snippets use the saved settings.</p>
                <form method="get">
                    <input type="hidden" name="page" value="uupd-settings">
                    <label for="uupd-hoster-product"><strong>Hoster product</strong></label>
                    <select name="uupd_hoster_product" id="uupd-hoster-product" required>
                        <option value="">Select a product</option>
                        <?php foreach ($posts as $post) : ?>
                            <option value="<?php echo esc_attr($post->ID); ?>" <?php selected($selected,$post->ID); ?>><?php echo esc_html($post->post_title . ' (#' . $post->ID . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php submit_button('Generate code','secondary','submit',false); ?>
                </form>
                <?php if ($selected && !$valid) : ?>
                    <div class="notice notice-error inline"><p>Could not resolve that Hoster product.</p></div>
                <?php elseif ($valid) :
                    $snippets = ['Bootloader'=>self::generate_bootloader($product), 'Inline licence box'=>self::generate_inline($product)];
                    foreach ($snippets as $label=>$code) :
                        $target='uupd-hoster-'.sanitize_title($label); ?>
                        <h3><?php echo esc_html($label); ?></h3>
                        <textarea id="<?php echo esc_attr($target); ?>" class="large-text code" rows="9" readonly><?php echo esc_textarea($code); ?></textarea>
                        <p><button type="button" class="button uupd-hoster-copy" data-target="<?php echo esc_attr($target); ?>">Copy <?php echo esc_html($label); ?></button></p>
                    <?php endforeach; ?>
                    <script>(function(){document.querySelectorAll('.uupd-hoster-copy').forEach(function(b){b.addEventListener('click',function(){var e=document.getElementById(b.dataset.target);if(!e)return;var t=e.value;function done(){b.textContent='Copied!';setTimeout(function(){b.textContent='Copy snippet';},1500);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done).catch(function(){window.prompt('Copy snippet:',t);});}else{window.prompt('Copy snippet:',t);}});});})();</script>
                <?php endif; ?>
            </div>
            <?php
        }

        protected static function get_product_post_type() {
            return (string) apply_filters( 'uupd_hoster_compat/post_type', 'downloads' );
        }

        public static function register_settings_page() {
            add_options_page(
                'UUPD Settings',
                'UUPD Settings',
                'manage_options',
                'uupd-settings',
                [ __CLASS__, 'render_settings_page' ]
            );
        }

        public static function render_settings_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'default' ) );
            }

            if (
                isset( $_POST['uupd_hoster_vendor_nonce'] ) &&
                wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uupd_hoster_vendor_nonce'] ) ), 'uupd_hoster_save_vendor' )
            ) {
                $vendor = isset( $_POST['uupd_hoster_vendor'] )
                    ? sanitize_key( wp_unslash( $_POST['uupd_hoster_vendor'] ) )
                    : '';

                if ( '' === $vendor ) {
                    delete_option( self::OPTION_VENDOR );
                } else {
                    update_option( self::OPTION_VENDOR, $vendor, false );
                }

                echo '<div class="notice notice-success is-dismissible"><p>Vendor saved.</p></div>';
            }

            $current_vendor = get_option( self::OPTION_VENDOR, '' );
            $current_vendor = is_string( $current_vendor ) ? $current_vendor : '';
            ?>
            <div class="wrap">
                <h1>UUPD Settings</h1>
                <?php uupd_host_gen_menu_render('hoster'); ?>
                <form method="post">
                    <?php wp_nonce_field( 'uupd_hoster_save_vendor', 'uupd_hoster_vendor_nonce' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="uupd_hoster_vendor">Global vendor slug</label></th>
                            <td>
                                <input type="text" class="regular-text" id="uupd_hoster_vendor" name="uupd_hoster_vendor" value="<?php echo esc_attr( $current_vendor ); ?>" placeholder="rup" />
                                <p class="description">Used for generated bootloader constants and vendor-aware UI/updater registration. Can be overridden in code with the <code>uupd_hoster_compat/vendor</code> filter.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Save vendor' ); ?>
                </form>
                <?php self::render_generator_panel(); ?>
            </div>
            <?php
        }

        public static function register_routes() {
            register_rest_route(
                'uupd/v1',
                '/license/(?P<action>activate|deactivate|check)',
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ __CLASS__, 'handle_license_request' ],
                    'permission_callback' => '__return_true',
                ]
            );

            register_rest_route(
                'uupd/v1',
                '/metadata',
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ __CLASS__, 'handle_metadata_request' ],
                    'permission_callback' => '__return_true',
                ]
            );
        }

        public static function maybe_handle_legacy_metadata() {
            if ( empty( $_GET['action'] ) || strtolower( (string) $_GET['action'] ) !== 'get_metadata' ) {
                return;
            }

            $slug    = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
            $key     = self::read_legacy_license_key();
            $domain  = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '';
            $item_id = isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : 0;
            $token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

            list( $data, $status ) = self::build_uupd_metadata( $slug, $key, $domain, $item_id, $token );

            status_header( (int) $status );
            header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
            echo wp_json_encode( $data );
            exit;
        }

        protected static function read_legacy_license_key() {
            if ( '' !== uupd_compat_metadata_key() ) {
                return self::sanitize_license_key( uupd_compat_metadata_key() );
            }

            return '';
        }

        public static function handle_license_request( WP_REST_Request $request ) {
            $action  = strtolower( (string) $request['action'] );
            $payload = (array) $request->get_json_params();

            if ( empty( $payload ) ) {
                $payload = (array) $request->get_body_params();
            }

            $license_key = isset( $payload['license_key'] ) ? self::sanitize_license_key( $payload['license_key'] ) : '';
            $slug        = isset( $payload['slug'] ) ? sanitize_key( (string) $payload['slug'] ) : '';
            $domain      = isset( $payload['domain'] ) ? sanitize_text_field( (string) $payload['domain'] ) : '';
            $item_id     = isset( $payload['item_id'] ) ? absint( $payload['item_id'] ) : 0;

            if ( '' === $license_key ) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => 'license_key is required.',
                        'status'  => 'inactive',
                    ],
                    400
                );
            }

            $product = self::resolve_product( $slug, $item_id );

            if ( empty( $product['item_id'] ) ) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => 'Could not resolve a Hoster product for the supplied slug/item_id.',
                        'status'  => 'inactive',
                    ],
                    404
                );
            }

            $site_url = self::normalize_site_value( $domain );
            $api_data = self::hoster_license_request( $action, (int) $product['item_id'], $license_key, $site_url );
            $status   = self::map_hoster_status( $api_data, $action );
            $success  = ( 'activate' === $action ) ? ( 'active' === $status ) : empty( $api_data['error'] );
            $message  = self::extract_message( $api_data, $action );

            $response = [
                'success'          => $success,
                'message'          => $message,
                'status'           => $status,
                'license_status'   => $status,
                'license'          => ( 'active' === $status ) ? 'valid' : 'invalid',
                'error'            => isset( $api_data['error'] ) ? (string) $api_data['error'] : '',
                'item_id'          => (int) $product['item_id'],
                'slug'             => (string) $product['slug'],
                'item_name'        => (string) ( $product['name'] ?? $product['slug'] ),
                'activation_limit' => self::calculate_activation_limit( $api_data ),
                'max_activations'  => self::calculate_activation_limit( $api_data ),
                'activations'      => isset( $api_data['used_activations'] ) ? (int) $api_data['used_activations'] : 0,
                'activations_left' => isset( $api_data['remaining_activations'] ) ? $api_data['remaining_activations'] : '',
                'expiry'           => isset( $api_data['expiry_date'] ) ? (string) $api_data['expiry_date'] : '',
                'date_expires'     => isset( $api_data['expiry_date'] ) ? (string) $api_data['expiry_date'] : '',
                'domain_active'    => ( 'active' === $status ),
                'token'            => isset( $api_data['token'] ) ? (string) $api_data['token'] : '',
                'raw_response'     => $api_data,
            ];

            if ( ! empty( $api_data['token'] ) && in_array( $action, [ 'activate', 'check' ], true ) ) {
                self::save_token( (int) $product['item_id'], $license_key, $site_url, (string) $api_data['token'] );
            }

            if ( 'deactivate' === $action ) {
                self::delete_token( (int) $product['item_id'], $license_key, $site_url );
            }

            $http = ! empty( $api_data['success'] ) ? 200 : 400;
            return new WP_REST_Response( $response, $http );
        }

        public static function handle_metadata_request( WP_REST_Request $request ) {
            $action = (string) $request->get_param( 'action' );

            if ( '' !== $action && 'get_metadata' !== strtolower( $action ) ) {
                return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid action.' ], 400 );
            }

            $slug    = sanitize_key( (string) $request->get_param( 'slug' ) );
            $key     = self::sanitize_license_key( uupd_compat_metadata_key( $request ) );
            $domain  = sanitize_text_field( (string) $request->get_param( 'domain' ) );
            $item_id = absint( (string) $request->get_param( 'item_id' ) );
            $token   = sanitize_text_field( (string) $request->get_param( 'token' ) );

            list( $data, $status ) = self::build_uupd_metadata( $slug, $key, $domain, $item_id, $token );
            return new WP_REST_Response( $data, (int) $status );
        }

        protected static function build_uupd_metadata( $slug, $license_key, $domain, $item_id = 0, $token = '' ) {
            if ( '' === $slug && ! $item_id ) {
                return [ [ 'success' => false, 'message' => 'Missing slug or item_id.' ], 400 ];
            }

            $product = self::resolve_product( $slug, $item_id );

            if ( empty( $product['item_id'] ) ) {
                return [ [ 'success' => false, 'message' => 'Could not resolve a Hoster product for the supplied slug/item_id.' ], 404 ];
            }

            $site_url = self::normalize_site_value( $domain );
            $check    = self::hoster_license_request( 'check', (int) $product['item_id'], $license_key, $site_url );
            $status   = self::map_hoster_status( $check, 'check' );
            $token    = (string) $token;

            if ( '' === $token && ! empty( $check['token'] ) ) {
                $token = (string) $check['token'];
            }

            if ( '' === $token ) {
                $token = self::get_saved_token( (int) $product['item_id'], $license_key, $site_url );
            }

            $response = [
                'slug'             => (string) $product['slug'],
                'name'             => (string) $product['name'],
                'version'          => (string) $product['current_version'],
                'new_version'      => (string) $product['current_version'],
                'author'           => (string) $product['author'],
                'author_homepage'  => (string) $product['author_homepage'],
                'author_profile'   => (string) $product['author_homepage'],
                'homepage'         => (string) $product['homepage'],
                'download_url'     => '',
                'package'          => '',
                'download_link'    => '',
                'requires'         => (string) $product['requires'],
                'tested'           => (string) $product['tested'],
                'requires_php'     => (string) $product['requires_php'],
                'last_updated'     => '',
                'sections'         => (array) $product['sections'],
                'banners'          => (array) $product['banners'],
                'icons'            => (array) $product['icons'],
                'license_required' => true,
                'license_status'   => $status,
                'item_id'          => (int) $product['item_id'],
                'activation_limit' => self::calculate_activation_limit( $check ),
                'max_activations'  => self::calculate_activation_limit( $check ),
                'activations'      => isset( $check['used_activations'] ) ? (int) $check['used_activations'] : 0,
                'date_expires'     => isset( $check['expiry_date'] ) ? (string) $check['expiry_date'] : '',
                'token'            => $token,
                'raw_response'     => $check,
            ];

            if ( 'active' !== $status ) {
                return [ $response, 200 ];
            }

            /*
             * Native Hoster updater ultimately reads:
             * wp-content/uploads/downloads/{download_id}/info.json
             *
             * Because this plugin runs on the Hoster site, the most reliable compatibility
             * approach is to read that JSON file directly after validating the license.
             * The protected secure-download.php?file=json endpoint is kept as a fallback.
             */
            // Native Hoster 1.4+ generates licence-bound, short-lived package links
            // via hoster_build_info(). Static info.json is intended for legacy clients
            // and must not take precedence for authenticated update requests.
            $remote = [];
            if ( function_exists( 'hoster_build_info' ) ) {
                $remote = hoster_build_info( (int) $product['item_id'], [
                    'license_key' => (string) $license_key,
                    'site_url'    => (string) $site_url,
                ] );
                if ( ! is_array( $remote ) ) {
                    $remote = [];
                }
            }

            // Compatibility with older Hoster releases lacking native helpers.
            // Never use unbound static package URLs when a native helper exists.
            if ( empty( $remote ) && ! function_exists( 'hoster_build_info' ) ) {
                $remote = self::read_hoster_metadata_json_file( (int) $product['item_id'] );
            }

            if ( empty( $remote ) ) {
                $metadata_token = self::generate_hoster_metadata_token( (int) $product['item_id'] );

                if ( '' !== $metadata_token ) {
                    $remote = self::fetch_hoster_metadata_json( (int) $product['item_id'], $metadata_token );
                }
            }

            if ( ! empty( $remote ) ) {
                $mapped   = self::map_hoster_metadata( $remote, $product );
                $response = array_merge( $response, $mapped );
            }

            if ( isset( $_GET['uupd_debug'] ) && current_user_can( 'manage_options' ) ) {
                $response['uupd_debug'] = [
                    'info_json_path' => self::get_hoster_info_json_path( (int) $product['item_id'] ),
                    'info_json_read' => ! empty( $remote ),
                    'info_json_keys' => is_array( $remote ) ? array_keys( $remote ) : [],
                ];
            }

            return [ $response, 200 ];
        }

        protected static function get_hoster_info_json_path( $download_id ) {
            $download_id = absint( $download_id );

            if ( ! $download_id ) {
                return '';
            }

            $upload_dir = wp_upload_dir();

            if ( empty( $upload_dir['basedir'] ) ) {
                return '';
            }

            return trailingslashit( $upload_dir['basedir'] ) . 'downloads/' . $download_id . '/info.json';
        }

        protected static function read_hoster_metadata_json_file( $download_id ) {
            $path = self::get_hoster_info_json_path( $download_id );

            if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
                return [];
            }

            $body = file_get_contents( $path );

            if ( false === $body || '' === trim( $body ) ) {
                return [];
            }

            $data = json_decode( $body, true );

            return is_array( $data ) ? $data : [];
        }

        protected static function generate_hoster_metadata_token( $download_id ) {
            $download_id = absint( $download_id );

            if ( ! $download_id ) {
                return '';
            }

            $post_name = get_post_field( 'post_name', $download_id );

            if ( ! is_string( $post_name ) || '' === $post_name ) {
                return '';
            }

            $secret = $post_name . ' SECURE KEY';

            if ( function_exists( 'HosterTransformValue' ) ) {
                $transformed = HosterTransformValue( $secret );
                $secret      = is_array( $transformed ) && isset( $transformed['upper'] )
                    ? (string) $transformed['upper']
                    : strtoupper( $secret );
            } else {
                $secret = strtoupper( $secret );
            }

            return hash_hmac( 'sha256', (string) $download_id, $secret );
        }

        protected static function hoster_license_request( $action, $download_id, $license_key, $site_url ) {
            $base = untrailingslashit( home_url( '/wp-json/hoster/v1' ) );
            $map  = [
                'activate'   => 'hoster-activate-license',
                'deactivate' => 'hoster-deactivate-license',
                'check'      => 'hoster-check-license',
            ];

            $payload = wp_json_encode(
                [
                    'download_id' => (int) $download_id,
                    'license_key' => (string) $license_key,
                    'site_url'    => (string) $site_url,
                ]
            );

            $args = [
                'timeout' => 20,
                'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
                'body'    => $payload,
            ];

            if ( 'activate' === $action ) {
                wp_remote_post( $base . '/' . $map['deactivate'] . '/', $args );
            }

            $endpoint = $map[ $action ] ?? $map['check'];
            $response = wp_remote_post( $base . '/' . $endpoint . '/', $args );

            if ( is_wp_error( $response ) ) {
                return [
                    'success' => false,
                    'error'   => $response->get_error_code(),
                    'message' => $response->get_error_message(),
                ];
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( ! is_array( $data ) ) {
                $data = [
                    'success' => false,
                    'message' => 'Invalid API response.',
                    'body'    => $body,
                ];
            }

            return $data;
        }

        protected static function fetch_hoster_metadata_json( $download_id, $token ) {
            $url = trailingslashit( home_url() )
                . 'wp-content/plugins/hoster/inc/secure-download.php?file=json&download='
                . rawurlencode( (string) $download_id )
                . '&token='
                . rawurlencode( (string) $token );

            $response = wp_remote_get(
                $url,
                [
                    'timeout' => 20,
                    'headers' => [ 'Accept' => 'application/json' ],
                ]
            );

            if ( is_wp_error( $response ) ) {
                return [];
            }

            $code = (int) wp_remote_retrieve_response_code( $response );

            if ( 200 !== $code ) {
                return [];
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            return is_array( $data ) ? $data : [];
        }

        protected static function map_hoster_metadata( array $body, array $product ) {
            $new_version = (string) ( $body['new_version'] ?? $body['version'] ?? $product['current_version'] );

            $download = '';
            foreach ( [ 'download_url', 'package', 'download_link', 'trunk' ] as $download_field ) {
                if ( ! empty( $body[ $download_field ] ) && is_string( $body[ $download_field ] ) ) {
                    $download = esc_url_raw( $body[ $download_field ] );
                    break;
                }
            }

            $homepage = (string) ( $body['homepage'] ?? $body['url'] ?? $product['homepage'] );

            $mapped = [
                'name'            => (string) ( $body['name'] ?? $product['name'] ),
                'slug'            => (string) ( $body['slug'] ?? $product['slug'] ),
                'version'         => $new_version,
                'new_version'     => $new_version,
                'author'          => (string) ( $body['author'] ?? $product['author'] ),
                'author_homepage' => (string) ( $body['author_homepage'] ?? $body['author_profile'] ?? $product['author_homepage'] ),
                'author_profile'  => (string) ( $body['author_profile'] ?? $body['author_homepage'] ?? $product['author_homepage'] ),
                'homepage'        => $homepage,
                'download_url'    => $download,
                'package'         => $download,
                'download_link'   => $download,
                'requires'        => (string) ( $body['requires'] ?? $product['requires'] ),
                'tested'          => (string) ( $body['tested'] ?? $product['tested'] ),
                'requires_php'    => (string) ( $body['requires_php'] ?? $product['requires_php'] ),
                'last_updated'    => (string) ( $body['last_updated'] ?? '' ),
                'sections'        => isset( $body['sections'] ) && is_array( $body['sections'] ) ? $body['sections'] : (array) $product['sections'],
                'banners'         => isset( $body['banners'] ) && is_array( $body['banners'] ) ? $body['banners'] : (array) $product['banners'],
                'icons'           => isset( $body['icons'] ) && is_array( $body['icons'] ) ? $body['icons'] : (array) $product['icons'],
            ];

            foreach ( [ 'description', 'installation', 'changelog', 'faq' ] as $section_key ) {
                if ( ! empty( $body[ $section_key ] ) && empty( $mapped['sections'][ $section_key ] ) ) {
                    $mapped['sections'][ $section_key ] = $body[ $section_key ];
                }
            }

            return $mapped;
        }

        protected static function resolve_product( $slug, $item_id = 0 ) {
            if ( $item_id ) {
                $post = get_post( $item_id );

                if ( $post instanceof WP_Post && $post->post_type === self::get_product_post_type() ) {
                    return self::hydrate_product_from_post( $post );
                }
            }

            $slug = sanitize_key( (string) $slug );

            if ( '' !== $slug ) {
                $post = self::find_product_post_by_slug( $slug );

                if ( $post ) {
                    return self::hydrate_product_from_post( $post );
                }
            }

            return [];
        }

        protected static function find_product_post_by_slug( $slug ) {
            $posts = get_posts(
                [
                    'post_type'      => self::get_product_post_type(),
                    'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
                    'posts_per_page' => 1,
                    'meta_query'     => [ [ 'key' => self::SLUG_META_KEY, 'value' => $slug ] ],
                ]
            );

            if ( ! empty( $posts[0] ) ) {
                return $posts[0];
            }

            $posts = get_posts(
                [
                    'post_type'      => self::get_product_post_type(),
                    'name'           => $slug,
                    'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
                    'posts_per_page' => 1,
                ]
            );

            return ! empty( $posts[0] ) ? $posts[0] : null;
        }

        protected static function hydrate_product_from_post( WP_Post $post ) {
            $slug = get_post_meta( $post->ID, self::SLUG_META_KEY, true );
            $slug = is_string( $slug ) && '' !== $slug ? $slug : $post->post_name;

            $version = get_post_meta( $post->ID, self::VERSION_META_KEY, true );

            if ( ! is_string( $version ) || '' === trim( $version ) ) {
                foreach ( (array) apply_filters( 'uupd_hoster_compat/version_meta_keys', [ '_version', 'version', 'current_version' ] ) as $meta_key ) {
                    $candidate = get_post_meta( $post->ID, $meta_key, true );

                    if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
                        $version = $candidate;
                        break;
                    }
                }
            }

            if ( ! is_string( $version ) || '' === trim( $version ) ) {
                $version = '1.0.0';
            }

            $product = [
                'item_id'         => (int) $post->ID,
                'slug'            => sanitize_key( $slug ),
                'name'            => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
                'homepage'        => get_permalink( $post ),
                'author'          => (string) get_bloginfo( 'name' ),
                'author_homepage' => home_url( '/' ),
                'requires'        => '',
                'requires_php'    => '',
                'tested'          => '',
                'current_version' => sanitize_text_field( $version ),
                'sections'        => [],
                'icons'           => [],
                'banners'         => [],
            ];

            return apply_filters( 'uupd_hoster_compat/product', $product, $post );
        }

        protected static function map_hoster_status( array $data, $action = 'check' ) {
            $candidates = [ $data['license_status'] ?? null, $data['status'] ?? null, $data['license'] ?? null ];

            foreach ( $candidates as $candidate ) {
                $value = strtolower( trim( (string) $candidate ) );

                if ( in_array( $value, [ 'active', 'valid', 'activated' ], true ) ) {
                    return 'active';
                }

                if ( in_array( $value, [ 'inactive', 'invalid', 'expired', 'disabled', 'deactivated' ], true ) ) {
                    return 'inactive';
                }
            }

            if ( ! empty( $data['success'] ) && 'deactivate' !== $action ) {
                return 'active';
            }

            return 'inactive';
        }

        protected static function extract_message( array $data, $action ) {
            if ( ! empty( $data['message'] ) ) {
                return (string) $data['message'];
            }

            if ( 'activate' === $action ) {
                return 'License activated.';
            }

            if ( 'deactivate' === $action ) {
                return 'License deactivated.';
            }

            return 'License checked.';
        }

        protected static function calculate_activation_limit( array $data ) {
            if ( isset( $data['activation_limit'] ) ) {
                return $data['activation_limit'];
            }

            if ( isset( $data['max_activations'] ) ) {
                return $data['max_activations'];
            }

            if ( isset( $data['used_activations'] ) || isset( $data['remaining_activations'] ) ) {
                return (int) ( $data['used_activations'] ?? 0 ) + (int) ( $data['remaining_activations'] ?? 0 );
            }

            return '';
        }

        protected static function save_token( $item_id, $license_key, $site_url, $token ) {
            if ( ! $item_id || '' === (string) $license_key || '' === (string) $site_url || '' === (string) $token ) {
                return;
            }

            update_option( self::TOKEN_OPTION_PREFIX . md5( implode( '|', [ (int) $item_id, (string) $license_key, untrailingslashit( (string) $site_url ) ] ) ), (string) $token, false );
        }

        protected static function get_saved_token( $item_id, $license_key, $site_url ) {
            if ( ! $item_id || '' === (string) $license_key || '' === (string) $site_url ) {
                return '';
            }

            return (string) get_option( self::TOKEN_OPTION_PREFIX . md5( implode( '|', [ (int) $item_id, (string) $license_key, untrailingslashit( (string) $site_url ) ] ) ), '' );
        }

        protected static function delete_token( $item_id, $license_key, $site_url ) {
            if ( ! $item_id || '' === (string) $license_key || '' === (string) $site_url ) {
                return;
            }

            delete_option( self::TOKEN_OPTION_PREFIX . md5( implode( '|', [ (int) $item_id, (string) $license_key, untrailingslashit( (string) $site_url ) ] ) ) );
        }

        protected static function sanitize_license_key( $value ) {
            $value = preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $value );
            return substr( (string) $value, 0, 256 );
        }

        protected static function normalize_site_value( $value ) {
            $value = trim( (string) $value );

            if ( '' === $value ) {
                return home_url();
            }

            if ( false === strpos( $value, '://' ) ) {
                $value = 'https://' . $value;
            }

            return untrailingslashit( $value );
        }

        protected static function generate_bootloader( array $product ) {
            $plugin_name = trim( (string) $product['name'] );
            $server      = trailingslashit( home_url() );
            $vendor      = self::get_vendor_slug();

            if ( '' === $vendor ) {
                $vendor = 'yourvendor';
            }

            $vendor = apply_filters( 'uupd_hoster_compat/vendor', $vendor, $product );
            $vendor = sanitize_key( $vendor );

            if ( '' === $vendor ) {
                $vendor = 'yourvendor';
            }

            $const_base = self::build_vendor_slug_constant_base( $vendor, $product );

            $code  = "define( 'UUPD_{$const_base}_VENDOR', '" . addslashes( $vendor ) . "' );\n";
            $code .= "define( 'UUPD_{$const_base}_VERSION', '" . addslashes( $product['current_version'] ) . "' );\n";
            $code .= "define( 'UUPD_{$const_base}_MAIN_FILE', __FILE__ );\n";
            $code .= "define( 'UUPD_{$const_base}_ITEM_NUMBER', " . (int) $product['item_id'] . " );\n";
            $code .= "define( 'UUPD_{$const_base}_FRIENDLY_NAME', '" . addslashes( $plugin_name ) . "' );\n";
            $code .= "define( 'UUPD_{$const_base}_SLUG', '" . addslashes( $product['slug'] ) . "' );\n";
            $code .= "define( 'UUPD_{$const_base}_SERVER_BASE', '" . addslashes( $server ) . "' );\n";
            $code .= "define( 'UUPD_{$const_base}_MENU_SLUG', UUPD_{$const_base}_VENDOR . '-' . UUPD_{$const_base}_SLUG );\n\n";
            $code .= "add_action( 'plugins_loaded', function() {\n";
            $code .= "    require_once __DIR__ . '/inc/updater.php';\n";
            $code .= "    require_once __DIR__ . '/inc/class-uupd-license-ui.php';\n\n";
            $code .= "    \\UUPD\\UI\\License\\V1\\UUPD_License_UI::register([\n";
            $code .= "        'vendor'         => UUPD_{$const_base}_VENDOR,\n";
            $code .= "        'slug'           => UUPD_{$const_base}_SLUG,\n";
            $code .= "        'item_id'        => UUPD_{$const_base}_ITEM_NUMBER,\n";
            $code .= "        'license_server' => UUPD_{$const_base}_SERVER_BASE,\n";
            $code .= "        'metadata_base'  => UUPD_{$const_base}_SERVER_BASE,\n";
            $code .= "        'plugin_name'    => UUPD_{$const_base}_FRIENDLY_NAME,\n";
            $menu_lines = uupd_host_gen_menu_lines('hoster', 'UUPD_' . $const_base . '_MENU_SLUG', 'UUPD_' . $const_base . '_FRIENDLY_NAME');
            if ($menu_lines === null) return '';
            $code .= $menu_lines;
            $code .= "        'capability'     => 'manage_options',\n";
            $code .= "    ]);\n\n";
            $code .= "    \\UUPD\\V2\\UUPD_Updater_V2::register([\n";
            $code .= "        'vendor'      => UUPD_{$const_base}_VENDOR,\n";
            $code .= "        'plugin_file' => plugin_basename( UUPD_{$const_base}_MAIN_FILE ),\n";
            $code .= "        'slug'        => UUPD_{$const_base}_SLUG,\n";
            $code .= "        'name'        => UUPD_{$const_base}_FRIENDLY_NAME,\n";
            $code .= "        'version'     => UUPD_{$const_base}_VERSION,\n";
            $code .= "        'server'      => '',\n";
		$code .= "        'license_key_transport' => 'header',\n";
            $code .= "    ]);\n";
            $code .= "}, 1 );";

            return $code;
        }

        protected static function generate_inline( array $product ) {
            $vendor = self::get_vendor_slug();

            if ( '' === $vendor ) {
                $vendor = 'yourvendor';
            }

            $vendor = apply_filters( 'uupd_hoster_compat/vendor', $vendor, $product );
            $vendor = sanitize_key( $vendor );

            if ( '' === $vendor ) {
                $vendor = 'yourvendor';
            }

            $const_base = self::build_vendor_slug_constant_base( $vendor, $product );

            return "<?php \\UUPD\\UI\\License\\V1\\UUPD_License_UI::render_box_for( UUPD_{$const_base}_SLUG, UUPD_{$const_base}_VENDOR ); ?>";
        }

        protected static function get_vendor_slug() {
            $value = get_option( self::OPTION_VENDOR, '' );
            return is_string( $value ) ? sanitize_key( $value ) : '';
        }

        protected static function build_vendor_slug_constant_base( $vendor, array $product ) {
            $slug   = isset( $product['slug'] ) ? (string) $product['slug'] : '';
            $source = trim( (string) $vendor ) . '_' . trim( $slug );

            $source = strtoupper( remove_accents( $source ) );
            $source = preg_replace( '/[^A-Z0-9]+/', '_', $source );
            $source = preg_replace( '/_+/', '_', (string) $source );
            $source = trim( (string) $source, '_' );

            if ( '' === $source ) {
                $source = 'PLUGIN';
            }

            return $source;
        }

        protected static function build_constant_base( array $product ) {
            $source = '';

            if ( ! empty( $product['name'] ) ) {
                $source = (string) $product['name'];
            } elseif ( ! empty( $product['slug'] ) ) {
                $source = (string) $product['slug'];
            }

            $source = strtoupper( remove_accents( $source ) );
            $source = preg_replace( '/[^A-Z0-9]+/', '_', $source );
            $source = preg_replace( '/_+/', '_', (string) $source );
            $source = trim( (string) $source, '_' );

            if ( '' === $source ) {
                $source = 'PLUGIN';
            }

            return $source;
        }
    }

    UUPD_Hoster_Compatibility_Layer::init();
}
