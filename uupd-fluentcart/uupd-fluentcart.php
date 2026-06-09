<?php
/**
 * Plugin Name: UUPD > FluentCart
 * Description: Adds UUPD-compatible license and metadata endpoints on top of native FluentCart licensing
 * Version: 1.0
 * Author:  Reallyusefulplugins.com
 * Author URI: https://reallyusefulplugins.com
 * Requires PHP: 7.4
 * Website: https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'UUPD_FluentCart_Compatibility_Layer' ) ) {

    final class UUPD_FluentCart_Compatibility_Layer {
        const VERSION           = '1.0';
        const SLUG_META_KEY     = '_uupd_plugin_slug';
        const VERSION_META_KEY  = '_uupd_current_version';
        const MENU_SLUG         = 'uupd-fluentcart-generator';
        const PRODUCT_POST_TYPE = 'fluent-products';
        const LICENSE_SETTINGS_META_KEY = 'license_settings';
        const OPTION_VENDOR = 'uupd_fluentcart_global_vendor';

        public static function init() {
            add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
            add_action( 'init', [ __CLASS__, 'maybe_handle_legacy_metadata' ], 0 );

            add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ] );
            add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );

            add_filter( 'manage_edit-' . self::PRODUCT_POST_TYPE . '_columns', [ __CLASS__, 'add_product_columns' ], 20 );
            add_action( 'manage_' . self::PRODUCT_POST_TYPE . '_posts_custom_column', [ __CLASS__, 'render_product_columns' ], 10, 2 );

            add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_box' ] );
            add_action( 'save_post_' . self::PRODUCT_POST_TYPE, [ __CLASS__, 'save_product_meta_box' ], 10, 2 );
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
            $key     = isset( $_GET['key'] ) ? self::sanitize_license_key( wp_unslash( $_GET['key'] ) ) : '';
            $domain  = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '';
            $item_id = isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : 0;

            list( $data, $status ) = self::build_uupd_metadata( $slug, $key, $domain, $item_id );

            status_header( (int) $status );
            header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
            echo wp_json_encode( $data );
            exit;
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
                        'message' => 'Could not resolve a FluentCart item for the supplied slug/item_id.',
                        'status'  => 'inactive',
                    ],
                    404
                );
            }

            $fc_action = [
                'activate'   => 'activate_license',
                'deactivate' => 'deactivate_license',
                'check'      => 'check_license',
            ][ $action ] ?? 'check_license';

            $site_url = self::normalize_site_value( $domain );

            $request_payload = [
                'item_id'          => (int) $product['item_id'],
                'license_key'      => $license_key,
                'site_url'         => $site_url,
                'current_version'  => (string) ( $product['current_version'] ?? '' ),
                'platform_version' => get_bloginfo( 'version' ),
                'server_version'   => PHP_VERSION,
            ];

            $stored_hash = self::get_activation_hash( (int) $product['item_id'], $license_key, $site_url );
            if ( $stored_hash ) {
                $request_payload['activation_hash'] = $stored_hash;
            }

            $fc   = self::fluentcart_request( $fc_action, $request_payload, 'POST' );
            $body = self::normalize_fluentcart_body( $fc['body'] );
            $http = $fc['code'] > 0 ? $fc['code'] : 200;

            $status  = self::map_fluentcart_license_status( $body, $action );
            $message = self::extract_message( $body, $action );
            $success = in_array( $status, [ 'active', 'inactive' ], true ) && empty( $body['error'] );
            if ( 'activate' === $action ) {
                $success = 'active' === $status;
            }

            if ( ! empty( $body['activation_hash'] ) && 'deactivate' !== $action ) {
                self::set_activation_hash( (int) $product['item_id'], $license_key, $site_url, (string) $body['activation_hash'] );
            }

            if ( 'deactivate' === $action ) {
                self::delete_activation_hash( (int) $product['item_id'], $license_key, $site_url );
            }

            $response = [
                'success'          => $success,
                'message'          => $message,
                'status'           => $status,
                'license_status'   => $status,
                'license'          => isset( $body['status'] ) ? (string) $body['status'] : '',
                'error'            => isset( $body['error'] ) ? (string) $body['error'] : '',
                'item_id'          => (int) $product['item_id'],
                'slug'             => (string) $product['slug'],
                'item_name'        => (string) ( $product['name'] ?? $product['slug'] ),
                'activation_hash'  => isset( $body['activation_hash'] ) ? (string) $body['activation_hash'] : $stored_hash,
                'activation_limit' => isset( $body['activation_limit'] ) ? $body['activation_limit'] : ( $body['max_activations'] ?? '' ),
                'max_activations'  => isset( $body['max_activations'] ) ? $body['max_activations'] : ( $body['activation_limit'] ?? '' ),
                'activations'      => isset( $body['activations'] ) ? (int) $body['activations'] : ( isset( $body['used_activations'] ) ? (int) $body['used_activations'] : 0 ),
                'activations_left' => isset( $body['remaining_activations'] ) ? $body['remaining_activations'] : '',
                'expiry'           => isset( $body['expiry'] ) ? (string) $body['expiry'] : ( $body['expiration_date'] ?? '' ),
                'date_expires'     => isset( $body['date_expires'] ) ? (string) $body['date_expires'] : ( $body['expiration_date'] ?? ( $body['expiry'] ?? '' ) ),
                'domain_active'    => ( 'active' === $status ),
                'raw_response'     => $body,
            ];

            return new WP_REST_Response( $response, $http );
        }

        public static function handle_metadata_request( WP_REST_Request $request ) {
            $action = (string) $request->get_param( 'action' );
            if ( '' !== $action && 'get_metadata' !== strtolower( $action ) ) {
                return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid action.' ], 400 );
            }

            $slug    = sanitize_key( (string) $request->get_param( 'slug' ) );
            $key     = self::sanitize_license_key( (string) $request->get_param( 'key' ) );
            $domain  = sanitize_text_field( (string) $request->get_param( 'domain' ) );
            $item_id = absint( (string) $request->get_param( 'item_id' ) );

            list( $data, $status ) = self::build_uupd_metadata( $slug, $key, $domain, $item_id );
            return new WP_REST_Response( $data, (int) $status );
        }

        protected static function build_uupd_metadata( $slug, $license_key, $domain, $item_id = 0 ) {
            if ( '' === $slug && ! $item_id ) {
                return [ [ 'success' => false, 'message' => 'Missing slug or item_id.' ], 400 ];
            }

            $product = self::resolve_product( $slug, $item_id );
            if ( empty( $product['item_id'] ) ) {
                return [ [ 'success' => false, 'message' => 'Could not resolve a FluentCart item for the supplied slug/item_id.' ], 404 ];
            }

            $payload = [
                'item_id'          => (int) $product['item_id'],
                'license_key'      => $license_key,
                'current_version'  => (string) ( $product['current_version'] ?? '' ),
                'site_url'         => self::normalize_site_value( $domain ),
                'platform_version' => get_bloginfo( 'version' ),
                'server_version'   => PHP_VERSION,
            ];

            $fc   = self::fluentcart_request( 'get_license_version', $payload, 'POST' );
            $body = self::normalize_fluentcart_body( $fc['body'] );
            $meta = self::map_fluentcart_metadata( $body, $product, $license_key );
            $code = $fc['code'] > 0 ? $fc['code'] : 200;

            return [ $meta, $code ];
        }

        protected static function map_fluentcart_metadata( array $body, array $product, $license_key ) {
            $new_version = (string) ( $body['new_version'] ?? $body['version'] ?? $product['current_version'] ?? '' );
            $download    = (string) ( $body['download_url'] ?? $body['package'] ?? $body['download_link'] ?? $body['trunk'] ?? '' );
            $homepage    = (string) ( $body['homepage'] ?? $body['url'] ?? home_url( '/' ) );
            $requires     = (string) ( $body['requires'] ?? $product['required_wp'] ?? '' );
            $tested       = (string) ( $body['tested'] ?? '' );
            $requires_php = (string) ( $body['requires_php'] ?? $product['required_php'] ?? '' );

            $mapped = [
                'slug'             => (string) $product['slug'],
                'name'             => (string) ( $body['name'] ?? $product['name'] ?? $product['slug'] ),
                'version'          => $new_version,
                'new_version'      => $new_version,
                'author'           => (string) ( $body['author'] ?? '' ),
                'author_homepage'  => (string) ( $body['author_homepage'] ?? '' ),
                'homepage'         => $homepage,
                'download_url'     => $download,
                'package'          => $download,
                'download_link'    => $download,
                'requires'         => $requires,
                'tested'           => $tested,
                'requires_php'     => $requires_php,
                'last_updated'     => (string) ( $body['last_updated'] ?? '' ),
                'sections'         => isset( $body['sections'] ) && is_array( $body['sections'] ) ? $body['sections'] : [],
                'banners'          => isset( $body['banners'] ) && is_array( $body['banners'] ) ? $body['banners'] : [],
                'icons'            => isset( $body['icons'] ) && is_array( $body['icons'] ) ? $body['icons'] : [],
                'license_required' => true,
                'license_status'   => self::infer_license_status_from_metadata( $body, $license_key ),
                'item_id'          => (int) $product['item_id'],
            ];

            foreach ( [ 'description', 'installation', 'changelog', 'faq' ] as $section_key ) {
                if ( ! empty( $body[ $section_key ] ) && empty( $mapped['sections'][ $section_key ] ) ) {
                    $mapped['sections'][ $section_key ] = $body[ $section_key ];
                }
            }

            if ( empty( $mapped['banners'] ) && ! empty( $product['banner_url'] ) ) {
                $mapped['banners'] = [
                    'low'  => (string) $product['banner_url'],
                    'high' => (string) $product['banner_url'],
                ];
            }

            if ( empty( $mapped['icons'] ) && ! empty( $product['icon_url'] ) ) {
                $mapped['icons'] = [
                    '1x' => (string) $product['icon_url'],
                    '2x' => (string) $product['icon_url'],
                ];
            }

            return $mapped;
        }

        protected static function infer_license_status_from_metadata( array $body, $license_key ) {
            if ( empty( $license_key ) ) {
                return 'missing';
            }

            $status = strtolower( (string) ( $body['license_status'] ?? $body['status'] ?? '' ) );
            if ( in_array( $status, [ 'valid', 'active' ], true ) ) {
                return 'active';
            }
            if ( in_array( $status, [ 'expired', 'disabled' ], true ) ) {
                return $status;
            }
            if ( in_array( $status, [ 'inactive', 'invalid' ], true ) ) {
                return $status;
            }

            return ! empty( $body['download_url'] ) || ! empty( $body['package'] ) ? 'active' : 'invalid';
        }

        protected static function resolve_product( $slug = '', $item_id = 0 ) {
            if ( $item_id ) {
                $post = get_post( $item_id );
                if ( $post && self::PRODUCT_POST_TYPE === $post->post_type ) {
                    return self::hydrate_product_from_post( $post );
                }
            }

            $slug = sanitize_key( (string) $slug );
            if ( '' === $slug ) {
                return [];
            }

            $posts = get_posts(
                [
                    'post_type'      => self::PRODUCT_POST_TYPE,
                    'name'           => $slug,
                    'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
                    'posts_per_page' => 1,
                    'orderby'        => 'ID',
                    'order'          => 'DESC',
                    'fields'         => 'all',
                ]
            );

            if ( ! empty( $posts[0] ) ) {
                return self::hydrate_product_from_post( $posts[0] );
            }

            $posts = get_posts(
                [
                    'post_type'      => self::PRODUCT_POST_TYPE,
                    'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
                    'posts_per_page' => 1,
                    'meta_query'     => [
                        [
                            'key'   => self::SLUG_META_KEY,
                            'value' => $slug,
                        ],
                    ],
                    'fields'         => 'all',
                ]
            );

            if ( ! empty( $posts[0] ) ) {
                return self::hydrate_product_from_post( $posts[0] );
            }

            return [];
        }

        protected static function hydrate_product_from_post( WP_Post $post ) {
            $slug_override     = sanitize_key( (string) get_post_meta( $post->ID, self::SLUG_META_KEY, true ) );
            $generator_version = sanitize_text_field( (string) get_post_meta( $post->ID, self::VERSION_META_KEY, true ) );
            $slug              = $slug_override ?: sanitize_key( $post->post_name );
            $license_settings  = self::get_product_license_settings( $post->ID );
            $resolved_version  = self::extract_product_version( $license_settings );
            $prefix            = self::extract_product_prefix( $license_settings );
            $banner_url        = self::extract_nested_string( $license_settings, [ 'wp', 'banner_url' ] );
            $icon_url          = self::extract_nested_string( $license_settings, [ 'wp', 'icon_url' ] );
            $required_php      = self::extract_nested_string( $license_settings, [ 'wp', 'required_php' ] );
            $required_wp       = self::extract_nested_string( $license_settings, [ 'wp', 'required_wp' ] );

            if ( '' === $resolved_version ) {
                $resolved_version = $generator_version;
            }
            if ( '' === $resolved_version ) {
                $resolved_version = '0.0.0';
            }

            return [
                'item_id'           => (int) $post->ID,
                'slug'              => $slug,
                'name'              => html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ),
                'current_version'   => $resolved_version,
                'generator_version' => $generator_version,
                'license_settings'  => $license_settings,
                'prefix'            => $prefix,
                'banner_url'        => $banner_url,
                'icon_url'          => $icon_url,
                'required_php'      => $required_php,
                'required_wp'       => $required_wp,
                'post_status'       => $post->post_status,
                'edit_url'          => get_edit_post_link( $post->ID, '' ),
            ];
        }

        protected static function get_product_license_settings( $product_id ) {
            global $wpdb;

            $table = $wpdb->prefix . 'fct_product_meta';
            $raw   = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$table} WHERE object_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1",
                    (int) $product_id,
                    self::LICENSE_SETTINGS_META_KEY
                )
            );

            if ( null === $raw || '' === $raw ) {
                return [];
            }

            if ( is_serialized( $raw ) ) {
                $raw = maybe_unserialize( $raw );
            }

            if ( is_array( $raw ) ) {
                return $raw;
            }

            $decoded = json_decode( (string) $raw, true );
            return is_array( $decoded ) ? $decoded : [];
        }

        protected static function extract_product_version( array $license_settings ) {
            if ( isset( $license_settings['version'] ) ) {
                return sanitize_text_field( (string) $license_settings['version'] );
            }
            return '';
        }

        protected static function extract_product_prefix( array $license_settings ) {
            if ( isset( $license_settings['prefix'] ) ) {
                return sanitize_text_field( (string) $license_settings['prefix'] );
            }
            return '';
        }

        protected static function extract_nested_string( array $data, array $path ) {
            $value = $data;
            foreach ( $path as $segment ) {
                if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                    return '';
                }
                $value = $value[ $segment ];
            }
            return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
        }

        protected static function fluentcart_request( $action, array $payload, $method = 'POST' ) {
            $base = apply_filters( 'uupd_fluentcart_compat/base_url', home_url( '/' ) );
            $url  = add_query_arg( [ 'fluent-cart' => $action ], $base );

            $args = [
                'method'    => strtoupper( $method ),
                'timeout'   => 20,
                'sslverify' => false,
            ];

            if ( 'GET' === strtoupper( $method ) ) {
                $url = add_query_arg( array_map( 'rawurlencode', $payload ), $url );
            } else {
                $args['body'] = $payload;
            }

            $response = wp_remote_request( $url, $args );
            if ( is_wp_error( $response ) ) {
                return [
                    'code' => 500,
                    'body' => [
                        'success' => false,
                        'message' => $response->get_error_message(),
                        'error'   => 'remote_request_failed',
                    ],
                ];
            }

            $body = wp_remote_retrieve_body( $response );
            $json = json_decode( $body, true );
            if ( null === $json ) {
                $json = [ 'success' => false, 'message' => 'Invalid JSON response from FluentCart endpoint.' ];
            }

            return [
                'code' => (int) wp_remote_retrieve_response_code( $response ),
                'body' => $json,
            ];
        }

        protected static function normalize_fluentcart_body( $body ) {
            if ( is_object( $body ) ) {
                $body = json_decode( wp_json_encode( $body ), true );
            }
            if ( ! is_array( $body ) ) {
                return [];
            }
            if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
                $merged = $body['data'];
                foreach ( $body as $key => $value ) {
                    if ( 'data' === $key && is_array( $value ) ) {
                        continue;
                    }
                    if ( ! isset( $merged[ $key ] ) ) {
                        $merged[ $key ] = $value;
                    }
                }
                return $merged;
            }
            return $body;
        }

        protected static function map_fluentcart_license_status( array $body, $action ) {
            $status = strtolower( (string) ( $body['status'] ?? '' ) );
            $error  = strtolower( (string) ( $body['error_type'] ?? $body['error'] ?? '' ) );

            if ( 'deactivate' === $action && ( empty( $status ) || 'success' === $status ) ) {
                return 'inactive';
            }
            if ( in_array( $status, [ 'valid', 'active' ], true ) ) {
                return 'active';
            }
            if ( in_array( $status, [ 'inactive', 'deactivated', 'invalid' ], true ) ) {
                return 'inactive';
            }
            if ( 'expired' === $status || 'expired' === $error ) {
                return 'expired';
            }
            if ( in_array( $status, [ 'disabled', 'blocked' ], true ) || in_array( $error, [ 'disabled', 'blocked' ], true ) ) {
                return 'disabled';
            }
            if ( ! empty( $body['success'] ) && 'activate' === $action ) {
                return 'active';
            }
            if ( ! empty( $body['success'] ) && in_array( $action, [ 'deactivate', 'check' ], true ) ) {
                return 'inactive' === $status ? 'inactive' : 'active';
            }
            return 'invalid';
        }

        protected static function extract_message( array $body, $action ) {
            if ( ! empty( $body['message'] ) ) {
                return (string) $body['message'];
            }
            if ( ! empty( $body['error_message'] ) ) {
                return (string) $body['error_message'];
            }
            switch ( $action ) {
                case 'activate':
                    return 'License activated.';
                case 'deactivate':
                    return 'License deactivated.';
                default:
                    return 'License checked.';
            }
        }

        protected static function sanitize_license_key( $license_key ) {
            return trim( sanitize_text_field( (string) $license_key ) );
        }

        protected static function normalize_site_value( $domain ) {
            $domain = trim( (string) $domain );
            if ( '' === $domain ) {
                return home_url();
            }
            return preg_match( '#^https?://#i', $domain ) ? $domain : 'https://' . ltrim( $domain, '/' );
        }

        protected static function get_activation_hash_option_key( $item_id, $license_key, $site_url ) {
            return 'uupd_fc_hash_' . md5( (int) $item_id . '|' . strtolower( (string) $license_key ) . '|' . strtolower( (string) $site_url ) );
        }

        protected static function get_activation_hash( $item_id, $license_key, $site_url ) {
            return (string) get_option( self::get_activation_hash_option_key( $item_id, $license_key, $site_url ), '' );
        }

        protected static function set_activation_hash( $item_id, $license_key, $site_url, $hash ) {
            update_option( self::get_activation_hash_option_key( $item_id, $license_key, $site_url ), sanitize_text_field( (string) $hash ), false );
        }

        protected static function delete_activation_hash( $item_id, $license_key, $site_url ) {
            delete_option( self::get_activation_hash_option_key( $item_id, $license_key, $site_url ) );
        }

        public static function register_admin_page() {
            add_submenu_page(
                'tools.php',
                'UUPD FluentCart Generator',
                'UUPD FluentCart',
                'edit_posts',
                self::MENU_SLUG,
                [ __CLASS__, 'render_admin_page' ]
            );
        }

        public static function add_product_columns( $columns ) {
            $new = [];
            foreach ( $columns as $key => $label ) {
                $new[ $key ] = $label;
                if ( 'title' === $key ) {
                    $new['uupd_fluentcart'] = __( 'UUPD', 'default' );
                }
            }
            if ( ! isset( $new['uupd_fluentcart'] ) ) {
                $new['uupd_fluentcart'] = __( 'UUPD', 'default' );
            }
            return $new;
        }

        public static function render_product_columns( $column, $post_id ) {
            if ( 'uupd_fluentcart' !== $column ) {
                return;
            }

            $product = self::resolve_product( '', $post_id );
            if ( empty( $product['item_id'] ) ) {
                echo '&mdash;';
                return;
            }

            $url = add_query_arg(
                [
                    'page'       => self::MENU_SLUG,
                    'product_id' => $post_id,
                ],
                admin_url( 'tools.php' )
            );

            echo '<a class="button button-small" href="' . esc_url( $url ) . '">Generate</a>';
            echo '<div style="margin-top:6px;font-size:11px;color:#646970;">';
            echo 'Slug: <code>' . esc_html( $product['slug'] ) . '</code>';
            echo '</div>';
        }

        public static function register_meta_box() {
            add_meta_box(
                'uupd-fc-meta-box',
                'UUPD Compatibility',
                [ __CLASS__, 'render_meta_box' ],
                self::PRODUCT_POST_TYPE,
                'side',
                'high'
            );
        }

        public static function render_meta_box( $post ) {
            if ( ! $post instanceof WP_Post ) {
                return;
            }

            $product = self::hydrate_product_from_post( $post );
            $url     = add_query_arg(
                [
                    'page'       => self::MENU_SLUG,
                    'product_id' => $post->ID,
                ],
                admin_url( 'tools.php' )
            );

            wp_nonce_field( 'uupd_fc_meta_box', 'uupd_fc_meta_box_nonce' );
            ?>
            <p>
                <label for="uupd_fc_slug"><strong>UUPD slug override</strong></label>
                <input type="text" class="widefat" id="uupd_fc_slug" name="uupd_fc_slug" value="<?php echo esc_attr( get_post_meta( $post->ID, self::SLUG_META_KEY, true ) ); ?>" placeholder="Defaults to product post_name" />
            </p>
            <p>
                <label for="uupd_fc_version"><strong>Generator version override</strong></label>
                <input type="text" class="widefat" id="uupd_fc_version" name="uupd_fc_version" value="<?php echo esc_attr( get_post_meta( $post->ID, self::VERSION_META_KEY, true ) ); ?>" placeholder="Defaults to license_settings version" />
            </p>
            <p class="description">Server-side mapping is automatic. Slug override is optional. Version is now pulled automatically from the FluentCart license_settings product meta and this field is only an optional generator fallback.</p>
            <p><a class="button button-secondary" href="<?php echo esc_url( $url ); ?>">Open generator</a></p>
            <div style="margin-top:8px;font-size:12px;color:#646970;">
                Resolved slug: <code><?php echo esc_html( $product['slug'] ); ?></code><br />
                Item ID: <code><?php echo esc_html( (string) $product['item_id'] ); ?></code><br />
                License meta version: <code><?php echo esc_html( $product['current_version'] ); ?></code>
            </div>
            <?php
        }

        public static function save_product_meta_box( $post_id, $post ) {
            if ( ! $post instanceof WP_Post || self::PRODUCT_POST_TYPE !== $post->post_type ) {
                return;
            }
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }
            if ( ! isset( $_POST['uupd_fc_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uupd_fc_meta_box_nonce'] ) ), 'uupd_fc_meta_box' ) ) {
                return;
            }
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }

            $slug    = isset( $_POST['uupd_fc_slug'] ) ? sanitize_key( wp_unslash( $_POST['uupd_fc_slug'] ) ) : '';
            $version = isset( $_POST['uupd_fc_version'] ) ? sanitize_text_field( wp_unslash( $_POST['uupd_fc_version'] ) ) : '';

            if ( '' === $slug ) {
                delete_post_meta( $post_id, self::SLUG_META_KEY );
            } else {
                update_post_meta( $post_id, self::SLUG_META_KEY, $slug );
            }

            if ( '' === $version ) {
                delete_post_meta( $post_id, self::VERSION_META_KEY );
            } else {
                update_post_meta( $post_id, self::VERSION_META_KEY, $version );
            }
        }

        protected static function get_all_products() {
            $posts = get_posts(
                [
                    'post_type'      => self::PRODUCT_POST_TYPE,
                    'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
                    'posts_per_page' => -1,
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                    'fields'         => 'all',
                ]
            );

            $products = [];
            foreach ( $posts as $post ) {
                if ( $post instanceof WP_Post ) {
                    $products[] = self::hydrate_product_from_post( $post );
                }
            }

            return $products;
        }


        protected static function get_vendor_slug() {
            $value = get_option( self::OPTION_VENDOR, '' );
            return is_string( $value ) ? sanitize_key( $value ) : '';
        }

        protected static function build_vendor_slug_constant_prefix( $vendor, $slug, $post_id ) {
            $value = trim( (string) $vendor ) . '_' . trim( (string) $slug );
            $prefix = strtoupper( str_replace( '-', '_', $value ) );
            $prefix = preg_replace( '/[^A-Z0-9_]/', '_', $prefix );
            $prefix = preg_replace( '/_+/', '_', $prefix );
            $prefix = trim( $prefix, '_' );
            return $prefix ?: 'PRODUCT_' . (int) $post_id;
        }

        protected static function slug_to_constant_prefix( $slug, $post_id ) {
            $prefix = strtoupper( str_replace( '-', '_', (string) $slug ) );
            $prefix = preg_replace( '/[^A-Z0-9_]/', '_', $prefix );
            $prefix = preg_replace( '/_+/', '_', $prefix );
            $prefix = trim( $prefix, '_' );
            return $prefix ?: 'PRODUCT_' . (int) $post_id;
        }

        protected static function build_bootloader_snippet( array $product ) {
			$friendly = html_entity_decode( (string) ( $product['name'] ?? '' ), ENT_QUOTES, 'UTF-8' );
			$slug     = (string) ( $product['slug'] ?? '' );
			$version  = (string) ( $product['current_version'] ?? '0.0.0' );
			$item_id  = (int) ( $product['item_id'] ?? 0 );
			$vendor   = self::get_vendor_slug();

			if ( '' === $vendor ) {
				$vendor = 'yourvendor';
			}

			$prefix = self::build_vendor_slug_constant_prefix( $vendor, $slug, $item_id );
			$server = apply_filters( 'uupd_fluentcart_compat/bootloader_server_base', home_url( '/' ), $product, $vendor );

			$esc = static function ( $value ) {
				return str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], (string) $value );
			};

			$c_vendor  = "UUPD_{$prefix}_VENDOR";
			$c_version = "UUPD_{$prefix}_VERSION";
			$c_file    = "UUPD_{$prefix}_MAIN_FILE";
			$c_item    = "UUPD_{$prefix}_ITEM_ID";
			$c_name    = "UUPD_{$prefix}_NAME";
			$c_slug    = "UUPD_{$prefix}_SLUG";
			$c_server  = "UUPD_{$prefix}_SERVER_BASE";
			$c_menu    = "UUPD_{$prefix}_MENU_SLUG";

			$lines   = [];
			$lines[] = "define( '{$c_vendor}', '" . $esc( $vendor ) . "' );";
			$lines[] = "define( '{$c_version}', '" . $esc( $version ) . "' );";
			$lines[] = "define( '{$c_file}', __FILE__ );";
			$lines[] = "define( '{$c_item}', " . $item_id . ' );';
			$lines[] = "define( '{$c_name}', '" . $esc( $friendly ) . "' );";
			$lines[] = "define( '{$c_slug}', '" . $esc( $slug ) . "' );";
			$lines[] = "define( '{$c_server}', '" . $esc( trailingslashit( $server ) ) . "' );";
			$lines[] = "define( '{$c_menu}', {$c_vendor} . '-' . {$c_slug} );";
			$lines[] = '';
			$lines[] = "add_action( 'plugins_loaded', function() {";
			$lines[] = "    require_once __DIR__ . '/inc/updater.php';";
			$lines[] = "    require_once __DIR__ . '/inc/class-uupd-license-ui.php';";
			$lines[] = '';
			$lines[] = "    \\UUPD\\UI\\License\\V1\\UUPD_License_UI::register([";
			$lines[] = "        'vendor'         => {$c_vendor},";
			$lines[] = "        'slug'           => {$c_slug},";
			$lines[] = "        'item_id'        => {$c_item},";
			$lines[] = "        'license_server' => {$c_server},";
			$lines[] = "        'metadata_base'  => {$c_server},";
			$lines[] = "        'plugin_name'    => {$c_name},";
			$lines[] = "        'menu_parent'    => 'top_level',";
			$lines[] = "        'menu_slug'      => {$c_menu},";
			$lines[] = "        'page_title'     => {$c_name} . ' License',";
			$lines[] = "        'menu_title'     => {$c_name} . ' License',";
			$lines[] = "        'capability'     => 'manage_options',";
			$lines[] = "    ]);";
			$lines[] = '';
			$lines[] = "    \\UUPD\\V2\\UUPD_Updater_V2::register([";
			$lines[] = "        'vendor'      => {$c_vendor},";
			$lines[] = "        'plugin_file' => plugin_basename( {$c_file} ),";
			$lines[] = "        'slug'        => {$c_slug},";
			$lines[] = "        'name'        => {$c_name},";
			$lines[] = "        'version'     => {$c_version},";
			$lines[] = "        'server'      => '',";
			$lines[] = "    ]);";
			$lines[] = "}, 1 );";

			return implode( "\n", $lines );
		}

		protected static function build_inline_snippet( array $product ) {
			$vendor = self::get_vendor_slug();

			if ( '' === $vendor ) {
				$vendor = 'yourvendor';
			}

			$prefix = self::build_vendor_slug_constant_prefix(
				$vendor,
				(string) ( $product['slug'] ?? '' ),
				(int) ( $product['item_id'] ?? 0 )
			);

			return "<?php \$inst = \\UUPD\\UI\\License\\V1\\UUPD_License_UI::get_instance( UUPD_{$prefix}_VENDOR, UUPD_{$prefix}_SLUG ); if ( \$inst ) { \$inst->render_page(); } ?>";
		}



        public static function enqueue_admin_assets() {
            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            if ( ! $screen ) {
                return;
            }

            $allowed = [ 'tools_page_' . self::MENU_SLUG, 'edit-' . self::PRODUCT_POST_TYPE, self::PRODUCT_POST_TYPE ];
            if ( ! in_array( $screen->id, $allowed, true ) ) {
                return;
            }

            wp_register_script( 'uupd-fc-admin', '', [ 'jquery' ], self::VERSION, true );
            wp_enqueue_script( 'uupd-fc-admin' );
            wp_add_inline_script( 'uupd-fc-admin', self::admin_js() );
        }

        protected static function admin_js() {
            return <<<'JS'
(function($){
  function copyText(text){
    if(navigator.clipboard && window.isSecureContext){
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function(resolve,reject){
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly','');
        ta.style.position = 'fixed';
        ta.style.top = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        var ok = document.execCommand('copy');
        document.body.removeChild(ta);
        ok ? resolve() : reject();
      } catch(e) { reject(e); }
    });
  }

  $(document).on('click', '.uupd-fc-copy-btn', function(e){
    e.preventDefault();
    var btn = this;
    var target = btn.getAttribute('data-uupd-target');
    var el = target ? document.querySelector(target) : null;
    var payload = el ? (el.value || el.textContent || '') : (btn.getAttribute('data-uupd-copy') || '');
    copyText(payload).then(function(){
      var old = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(function(){ btn.textContent = old; }, 900);
    }).catch(function(){
      alert('Copy failed. Please copy manually.');
    });
  });
})(jQuery);
JS;
        }

        public static function render_admin_page() {
            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'default' ) );
            }

            if ( isset( $_GET['uupd_vendor'] ) && current_user_can( 'manage_options' ) ) {
                $vendor = sanitize_key( wp_unslash( $_GET['uupd_vendor'] ) );
                if ( '' === $vendor ) {
                    delete_option( self::OPTION_VENDOR );
                } else {
                    update_option( self::OPTION_VENDOR, $vendor, false );
                }
            }

            $products   = self::get_all_products();
            $selected   = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
            $vendor     = self::get_vendor_slug();
            if ( '' === $vendor ) {
                $vendor = 'yourvendor';
            }
            $product    = $selected ? self::resolve_product( '', $selected ) : [];
            $bootloader = ! empty( $product ) ? self::build_bootloader_snippet( $product ) : '';
            $inline     = ! empty( $product ) ? self::build_inline_snippet( $product ) : '';
            ?>
            <div class="wrap">
                <h1>UUPD FluentCart Generator</h1>
                <p>This generator creates native UUPD bootloader and inline snippets. Server-side product resolution is automatic from the FluentCart product itself, and plugin version is pulled from the FluentCart <code>license_settings</code> product meta.</p>

                <form method="get" action="">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="uupd_vendor">Global vendor slug</label></th>
                            <td>
                                <input type="text" id="uupd_vendor" name="uupd_vendor" value="<?php echo esc_attr( $vendor ); ?>" placeholder="rup" class="regular-text" />
                                <p class="description">Used for generated bootloader constants and vendor-aware UI/updater registration.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="product_id">FluentCart product</label></th>
                            <td>
                                <select id="product_id" name="product_id" style="min-width:320px;">
                                    <option value="">Select a product…</option>
                                    <?php foreach ( $products as $row ) : ?>
                                        <option value="<?php echo esc_attr( (string) $row['item_id'] ); ?>" <?php selected( $selected, (int) $row['item_id'] ); ?>>
                                            <?php echo esc_html( $row['name'] . ' (#' . $row['item_id'] . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="button button-primary">Generate</button>
                            </td>
                        </tr>
                    </table>
                </form>

                <?php if ( ! empty( $product ) ) : ?>
                    <hr />
                    <h2><?php echo esc_html( $product['name'] ); ?></h2>
                    <p>
                        Item ID: <code><?php echo esc_html( (string) $product['item_id'] ); ?></code><br />
                        Resolved slug: <code><?php echo esc_html( $product['slug'] ); ?></code><br />
                        Vendor: <code><?php echo esc_html( $vendor ); ?></code><br />
                        Plugin version: <code><?php echo esc_html( $product['current_version'] ); ?></code><?php if ( ! empty( $product['prefix'] ) ) : ?><br />Prefix: <code><?php echo esc_html( $product['prefix'] ); ?></code><?php endif; ?>
                    </p>
                    <p>
                        <a class="button" href="<?php echo esc_url( $product['edit_url'] ); ?>">Edit product</a>
                    </p>

                    <h3>Bootloader</h3>
                    <p>
                        <button type="button" class="button button-secondary uupd-fc-copy-btn" data-uupd-target="#uupd-fc-bootloader">Copy bootloader</button>
                    </p>
                    <textarea id="uupd-fc-bootloader" class="large-text code" rows="22" readonly><?php echo esc_textarea( $bootloader ); ?></textarea>

                    <h3 style="margin-top:24px;">Inline display</h3>
                    <p>
                        <button type="button" class="button button-secondary uupd-fc-copy-btn" data-uupd-target="#uupd-fc-inline">Copy inline</button>
                    </p>
                    <textarea id="uupd-fc-inline" class="large-text code" rows="3" readonly><?php echo esc_textarea( $inline ); ?></textarea>
                <?php elseif ( empty( $products ) ) : ?>
                    <p>No FluentCart products were found.</p>
                <?php endif; ?>
            </div>
            <?php
        }
    }

    UUPD_FluentCart_Compatibility_Layer::init();
}
