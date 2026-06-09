<?php
/**
 * Plugin Name: UUPD > WSL
 * Description: Adds UUPD-compatible license and metadata endpoints on top of WP Software License
 * Version: 1.0
 * Author:  Reallyusefulplugins.com
 * Author URI: https://reallyusefulplugins.com
 * Requires PHP: 7.4
 * Website: https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'UUPD_WSL_Compatibility_Layer' ) ) {
    final class UUPD_WSL_Compatibility_Layer {
        const VERSION = '1.0';
        const SLUG_META_KEY = '_uupd_plugin_slug';
        const VERSION_META_KEY = '_uupd_current_version';
        const OPTION_VENDOR = 'uupd_wsl_compat_vendor';

        public static function init() {
            add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
            add_action( 'init', [ __CLASS__, 'maybe_handle_legacy_metadata' ], 0 );

            if ( is_admin() ) {
                add_action( 'admin_init', [ __CLASS__, 'maybe_handle_copy' ] );
                add_action( 'admin_menu', [ __CLASS__, 'register_settings_page' ] );
                add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
                add_action( 'admin_head-edit.php', [ __CLASS__, 'output_admin_column_styles' ] );
                add_filter( 'manage_edit-' . self::get_product_post_type() . '_columns', [ __CLASS__, 'add_product_columns' ], 30 );
                add_action( 'manage_' . self::get_product_post_type() . '_posts_custom_column', [ __CLASS__, 'render_product_columns' ], 10, 2 );
            }
        }

        protected static function get_product_post_type() {
            return (string) apply_filters( 'uupd_wsl_compat/post_type', 'product' );
        }

        protected static function get_wsl_server_base() {
            $server = (string) apply_filters( 'uupd_wsl_compat/server_base', home_url( '/' ) );
            return rtrim( $server, '/' );
        }

        protected static function get_wsl_post_url() {
            $server = self::get_wsl_server_base();
            $force_index = (bool) apply_filters( 'uupd_wsl_compat/force_index_php', false );
            if ( $force_index && substr( $server, -9 ) !== 'index.php' ) {
                $server .= '/index.php';
            }
            return $server;
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
            $item_id = isset( $_GET['item_id'] ) ? sanitize_text_field( wp_unslash( $_GET['item_id'] ) ) : '';
            $version = isset( $_GET['version'] ) ? sanitize_text_field( wp_unslash( $_GET['version'] ) ) : '';

            list( $data, $status ) = self::build_uupd_metadata( $slug, $key, $domain, $item_id, $version );
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
            $item_id     = isset( $payload['item_id'] ) ? (string) $payload['item_id'] : '';

            if ( '' === $license_key ) {
                return new WP_REST_Response( [
                    'success' => false,
                    'message' => 'license_key is required.',
                    'status'  => 'inactive',
                ], 400 );
            }

            $product = self::resolve_product( $slug, $item_id );
            if ( empty( $product['product_unique_id'] ) ) {
                return new WP_REST_Response( [
                    'success' => false,
                    'message' => 'Could not resolve a WSL product for the supplied slug/item_id.',
                    'status'  => 'inactive',
                ], 404 );
            }

            $api_data = self::wsl_license_request( $action, (string) $product['product_unique_id'], $license_key, $domain );
            $status   = self::map_wsl_license_status( $api_data, $action );
            $message  = self::extract_message( $api_data, $action );
            $success  = ! empty( $api_data['success'] ) || 'active' === $status || ( 'deactivate' === $action && 'inactive' === $status );
            $license_info = self::lookup_wsl_license_details( $license_key, $domain );
            $limit    = self::read_first_non_empty( $api_data, [ 'activation_limit', 'max_activations', 'licence_max_allowed_instances' ] );
            if ( '' === (string) $limit && isset( $license_info['activation_limit'] ) ) { $limit = $license_info['activation_limit']; }
            $used     = self::read_first_non_empty( $api_data, [ 'activations', 'used_activations', 'instance_count' ] );
            if ( '' === (string) $used && isset( $license_info['activations'] ) ) { $used = $license_info['activations']; }
            $expires  = self::read_first_non_empty( $api_data, [ 'date_expires', 'expiration_date', 'expiry_date', 'expires_at' ] );
            if ( '' === (string) $expires && isset( $license_info['date_expires'] ) ) { $expires = $license_info['date_expires']; }

            $response = [
                'success'          => (bool) $success,
                'message'          => $message,
                'status'           => $status,
                'license_status'   => $status,
                'license'          => ( 'active' === $status ) ? 'valid' : 'invalid',
                'error'            => isset( $api_data['error'] ) ? (string) $api_data['error'] : '',
                'item_id'          => (int) $product['item_id'],
                'product_unique_id'=> (string) $product['product_unique_id'],
                'slug'             => (string) $product['slug'],
                'item_name'        => (string) $product['name'],
                'activation_limit' => '' !== (string) $limit ? $limit : '',
                'max_activations'  => '' !== (string) $limit ? $limit : '',
                'activations'      => '' !== (string) $used ? $used : '',
                'date_expires'     => '' !== (string) $expires ? $expires : '',
                'raw_response'     => $api_data,
            ];

            if ( ! empty( $license_info ) ) {
                $response['license_lookup'] = $license_info;
            }

            $http = $success ? 200 : 400;
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
            $item_id = (string) $request->get_param( 'item_id' );
            $version = (string) $request->get_param( 'version' );

            list( $data, $status ) = self::build_uupd_metadata( $slug, $key, $domain, $item_id, $version );
            return new WP_REST_Response( $data, (int) $status );
        }

        protected static function build_uupd_metadata( $slug, $license_key, $domain, $item_id = '', $installed_version = '' ) {
            if ( '' === $slug && '' === (string) $item_id ) {
                return [ [ 'success' => false, 'message' => 'Missing slug or item_id.' ], 400 ];
            }

            $product = self::resolve_product( $slug, $item_id );
            if ( empty( $product['product_unique_id'] ) ) {
                return [ [ 'success' => false, 'message' => 'Could not resolve a WSL product for the supplied slug/item_id.' ], 404 ];
            }

            $check         = self::wsl_license_request( 'check', (string) $product['product_unique_id'], $license_key, $domain );
            $status        = self::map_wsl_license_status( $check, 'check' );
            $remote_meta   = self::wsl_metadata_request( $product, $license_key, $domain, $installed_version );
            $mapped_remote = self::map_wsl_metadata( $remote_meta, $product );
            $license_info   = self::lookup_wsl_license_details( $license_key, $domain );
            $limit         = self::read_first_non_empty( $check, [ 'activation_limit', 'max_activations', 'licence_max_allowed_instances' ] );
            if ( '' === (string) $limit && isset( $license_info['activation_limit'] ) ) { $limit = $license_info['activation_limit']; }
            $used          = self::read_first_non_empty( $check, [ 'activations', 'used_activations', 'instance_count' ] );
            if ( '' === (string) $used && isset( $license_info['activations'] ) ) { $used = $license_info['activations']; }
            $expires       = self::read_first_non_empty( $check, [ 'date_expires', 'expiration_date', 'expiry_date', 'expires_at' ] );
            if ( '' === (string) $expires && isset( $license_info['date_expires'] ) ) { $expires = $license_info['date_expires']; }

            $response = array_merge(
                [
                    'slug'             => (string) $product['slug'],
                    'name'             => (string) $product['name'],
                    'version'          => (string) $product['current_version'],
                    'new_version'      => (string) $product['current_version'],
                    'author'           => get_bloginfo( 'name' ),
                    'author_homepage'  => home_url( '/' ),
                    'homepage'         => get_permalink( (int) $product['item_id'] ),
                    'download_url'     => '',
                    'package'          => '',
                    'download_link'    => '',
                    'requires'         => '',
                    'tested'           => '',
                    'requires_php'     => '',
                    'last_updated'     => '',
                    'sections'         => [],
                    'banners'          => [],
                    'icons'            => [],
                ],
                $mapped_remote,
                [
                    'license_required' => true,
                    'license_status'   => $status,
                    'item_id'          => (int) $product['item_id'],
                    'product_unique_id'=> (string) $product['product_unique_id'],
                    'activation_limit' => '' !== (string) $limit ? $limit : '',
                    'max_activations'  => '' !== (string) $limit ? $limit : '',
                    'activations'      => '' !== (string) $used ? $used : '',
                    'date_expires'     => '' !== (string) $expires ? $expires : '',
                    'raw_response'     => $check,
                ]
            );

            if ( ! empty( $license_info ) ) {
                $response['license_lookup'] = $license_info;
            }

            return [ $response, 200 ];
        }

        protected static function wsl_license_request( $action, $product_unique_id, $license_key, $domain ) {
            $endpoint_map = [
                'activate'   => 'activate',
                'deactivate' => 'deactivate',
                'check'      => 'status-check',
            ];
            $domain_host = self::normalize_domain_host( $domain );
            $payload = [
                'woo_sl_action'     => $endpoint_map[ $action ] ?? 'status-check',
                'product_unique_id' => (string) $product_unique_id,
                'licence_key'       => (string) $license_key,
                'domain'            => $domain_host,
                'api_version'       => (string) apply_filters( 'uupd_wsl_compat/api_version', '1.2' ),
            ];

            $response = wp_remote_post( self::get_wsl_post_url(), [
                'timeout'   => 20,
                'body'      => $payload,
                'sslverify' => apply_filters( 'uupd_wsl_compat/sslverify', true, $action, $product_unique_id ),
            ] );

            if ( is_wp_error( $response ) ) {
                return [
                    'success' => false,
                    'message' => $response->get_error_message(),
                    'error'   => $response->get_error_code(),
                ];
            }

            return self::normalize_wsl_payload( wp_remote_retrieve_body( $response ) );
        }

        protected static function wsl_metadata_request( array $product, $license_key, $domain, $installed_version = '' ) {
            global $wp_version;

            $base_query = [
                'product_unique_id' => (string) $product['product_unique_id'],
                'domain'            => self::normalize_domain_host( $domain ),
                'version'           => (string) ( $installed_version !== '' ? $installed_version : '0.0.0' ),
                'wp-version'        => (string) $wp_version,
                'api_version'       => (string) apply_filters( 'uupd_wsl_compat/api_version', '1.2' ),
            ];
            if ( '' !== $license_key ) {
                $base_query['licence_key'] = (string) $license_key;
            }

            $actions = [
                (string) apply_filters( 'uupd_wsl_compat/meta_action', 'plugin_update' ),
                'plugin_update',
                'plugin_information',
            ];
            $actions = array_values( array_unique( array_filter( $actions ) ) );

            foreach ( $actions as $meta_action ) {
                $query = $base_query;
                $query['woo_sl_action'] = $meta_action;

                $url = add_query_arg( $query, self::ensure_index_php( self::get_wsl_server_base() ) );
                $response = wp_remote_get( $url, [
                    'timeout'   => 20,
                    'sslverify' => apply_filters( 'uupd_wsl_compat/sslverify', true, 'metadata', $product['product_unique_id'] ),
                ] );

                if ( is_wp_error( $response ) ) {
                    $last_error = [
                        'success' => false,
                        'message' => $response->get_error_message(),
                        'error'   => $response->get_error_code(),
                    ];
                    continue;
                }

                $normalized = self::normalize_wsl_payload( wp_remote_retrieve_body( $response ) );
                $payload = self::extract_wsl_update_payload( $normalized );
                $download = self::read_first_non_empty( $payload, [ 'package', 'download_url', 'download_link', 'download_uri', 'trunk' ] );

                if ( '' !== $download ) {
                    $normalized['_meta_action_used'] = $meta_action;
                    return $normalized;
                }

                $last_error = $normalized;
            }

            return isset( $last_error ) ? $last_error : [
                'success' => false,
                'message' => 'No valid metadata response from WSL server.',
            ];
        }

        protected static function normalize_wsl_payload( $raw ) {
            $json = json_decode( (string) $raw, true );
            if ( ! is_array( $json ) ) {
                return [
                    'success' => false,
                    'message' => 'Invalid response from WSL server.',
                    'raw'     => $raw,
                ];
            }

            if ( isset( $json[0] ) && array_keys( $json ) === range( 0, count( $json ) - 1 ) ) {
                $json = end( $json );
            }

            if ( isset( $json['update_data'] ) && is_array( $json['update_data'] ) ) {
                $json['_update_data'] = $json['update_data'];
            }
            if ( isset( $json['message'] ) && is_array( $json['message'] ) ) {
                $json['_update_data'] = $json['message'];
                $json['message'] = $json['message']['message'] ?? '';
            }

            $status_txt     = isset( $json['status'] ) ? strtolower( (string) $json['status'] ) : '';
            $status_code    = isset( $json['status_code'] ) ? (string) $json['status_code'] : '';
            $licence_status = isset( $json['licence_status'] ) ? strtolower( (string) $json['licence_status'] ) : '';
            $message        = isset( $json['message'] ) && ! is_array( $json['message'] ) ? (string) $json['message'] : '';
            $is_success_code = ( $status_code !== '' && strtolower( $status_code[0] ) === 's' );
            $success = ( $status_txt === 'success' && $is_success_code ) || $licence_status === 'active';
            if ( ! empty( $json['success'] ) ) {
                $success = true;
            }
            if ( ! $success && stripos( $message, 'already activated' ) !== false ) {
                $success = true;
                $licence_status = 'active';
            }
            $json['success'] = $success;
            return $json;
        }

        protected static function map_wsl_license_status( array $data, $action ) {
            $licence_status = strtolower( (string) ( $data['licence_status'] ?? '' ) );
            if ( 'active' === $licence_status ) {
                return 'active';
            }
            if ( in_array( $licence_status, [ 'inactive', 'expired', 'disabled', 'blocked' ], true ) ) {
                return $licence_status;
            }
            if ( 'deactivate' === $action ) {
                return 'inactive';
            }
            if ( ! empty( $data['success'] ) ) {
                return 'active';
            }
            return 'inactive';
        }

        protected static function extract_wsl_update_payload( array $meta ) {
            if ( isset( $meta['_update_data'] ) && is_array( $meta['_update_data'] ) ) {
                return $meta['_update_data'];
            }
            if ( isset( $meta['update_data'] ) && is_array( $meta['update_data'] ) ) {
                return $meta['update_data'];
            }
            if ( isset( $meta['message'] ) && is_array( $meta['message'] ) ) {
                return $meta['message'];
            }
            return $meta;
        }

        protected static function map_wsl_metadata( array $meta, array $product ) {
            $payload = self::extract_wsl_update_payload( $meta );
            $post_id = (int) ( $product['item_id'] ?? 0 );
            $version = self::read_first_non_empty( $payload, [ 'version', 'new_version' ] );
            $download = self::read_first_non_empty( $payload, [ 'download_url', 'package', 'download_link', 'download_uri', 'trunk' ] );
            $sections = (array) ( $payload['sections'] ?? [] );
            $banners  = (array) ( $payload['banners'] ?? [] );
            $icons    = (array) ( $payload['icons'] ?? [] );

            if ( empty( $sections ) && $post_id ) {
                $sections = array_filter( [
                    'description'  => (string) get_post_meta( $post_id, '_sl_description', true ),
                    'installation' => (string) get_post_meta( $post_id, '_sl_installation', true ),
                    'faq'          => (string) get_post_meta( $post_id, '_sl_faq', true ),
                    'changelog'    => (string) get_post_meta( $post_id, '_sl_changelog', true ),
                    'reviews'      => (string) get_post_meta( $post_id, '_sl_reviews', true ),
                    'other_notes'  => (string) get_post_meta( $post_id, '_sl_other_notes', true ),
                ] );
            }
            if ( empty( $banners ) && $post_id ) {
                $banners = array_filter( [
                    'low'  => (string) get_post_meta( $post_id, '_sl_screenshots_low', true ),
                    'high' => (string) get_post_meta( $post_id, '_sl_screenshots_high', true ),
                ] );
            }
            if ( empty( $icons ) && $post_id ) {
                $icons = array_filter( [
                    'svg' => (string) get_post_meta( $post_id, '_sl_icons_svg', true ),
                    '1x'  => (string) get_post_meta( $post_id, '_sl_icons_1x', true ),
                    '2x'  => (string) get_post_meta( $post_id, '_sl_icons_2x', true ),
                ] );
            }

            return [
                'slug'            => (string) ( $payload['slug'] ?? $product['slug'] ),
                'name'            => (string) ( $payload['name'] ?? $payload['plugin_name'] ?? $product['name'] ),
                'version'         => '' !== (string) $version ? (string) $version : (string) $product['current_version'],
                'new_version'     => '' !== (string) $version ? (string) $version : (string) $product['current_version'],
                'author'          => (string) ( $payload['author'] ?? ( $post_id ? get_post_meta( $post_id, '_sl_author', true ) : '' ) ?: get_bloginfo( 'name' ) ),
                'author_homepage' => (string) ( $payload['author_homepage'] ?? $payload['author_profile'] ?? home_url( '/' ) ),
                'homepage'        => (string) ( $payload['homepage'] ?? $payload['url'] ?? ( $post_id ? get_post_meta( $post_id, '_sl_plugin_url', true ) : '' ) ?: get_permalink( (int) $product['item_id'] ) ),
                'download_url'    => (string) $download,
                'package'         => (string) $download,
                'download_link'   => (string) $download,
                'requires'        => (string) ( self::read_first_non_empty( $payload, [ 'requires', 'min_wp_version' ] ) ?: ( $post_id ? get_post_meta( $post_id, '_sl_version_required', true ) : '' ) ),
                'tested'          => (string) ( $payload['tested'] ?? ( $post_id ? get_post_meta( $post_id, '_sl_tested_up_to', true ) : '' ) ),
                'requires_php'    => (string) ( $payload['requires_php'] ?? '7.4' ),
                'last_updated'    => (string) ( $payload['last_updated'] ?? ( $post_id ? get_post_meta( $post_id, '_sl_last_updated', true ) : '' ) ),
                'sections'        => $sections,
                'banners'         => $banners,
                'icons'           => $icons,
            ];
        }

        protected static function resolve_product( $slug, $item_id ) {
            $post_type = self::get_product_post_type();
            $product = [
                'item_id'           => 0,
                'slug'              => '',
                'name'              => '',
                'product_unique_id' => '',
                'current_version'   => '1.0.0',
            ];

            $post = null;
            $item_id = (string) $item_id;
            if ( '' !== $item_id ) {
                if ( ctype_digit( $item_id ) ) {
                    $candidate = get_post( (int) $item_id );
                    if ( $candidate instanceof WP_Post && $candidate->post_type === $post_type ) {
                        $post = $candidate;
                    }
                }
                if ( ! $post ) {
                    $posts = get_posts( [
                        'post_type'      => $post_type,
                        'posts_per_page' => 1,
                        'post_status'    => [ 'publish', 'draft', 'private', 'future' ],
                        'meta_query'     => [
                            [
                                'key'   => self::get_unique_id_meta_key_for_query(),
                                'value' => $item_id,
                            ],
                        ],
                    ] );
                    if ( ! empty( $posts[0] ) ) {
                        $post = $posts[0];
                    }
                }
            }

            if ( ! $post && '' !== $slug ) {
                $posts = get_posts( [
                    'name'           => $slug,
                    'post_type'      => $post_type,
                    'posts_per_page' => 1,
                    'post_status'    => [ 'publish', 'draft', 'private', 'future' ],
                ] );
                if ( ! empty( $posts[0] ) ) {
                    $post = $posts[0];
                }
            }

            if ( ! $post && '' !== $slug ) {
                $slug_meta_keys = apply_filters( 'uupd_wsl_compat/slug_meta_keys', [ self::SLUG_META_KEY, '_sl_software_title' ] );
                foreach ( (array) $slug_meta_keys as $meta_key ) {
                    $posts = get_posts( [
                        'post_type'      => $post_type,
                        'posts_per_page' => 1,
                        'post_status'    => [ 'publish', 'draft', 'private', 'future' ],
                        'meta_query'     => [
                            [
                                'key'   => (string) $meta_key,
                                'value' => $slug,
                            ],
                        ],
                    ] );
                    if ( ! empty( $posts[0] ) ) {
                        $post = $posts[0];
                        break;
                    }
                }
            }

            if ( ! $post ) {
                return $product;
            }

            $resolved_slug = (string) get_post_meta( $post->ID, self::SLUG_META_KEY, true );
            if ( '' === $resolved_slug ) {
                $resolved_slug = (string) get_post_meta( $post->ID, '_sl_software_title', true );
            }
            if ( '' === $resolved_slug ) {
                $resolved_slug = (string) $post->post_name;
            }

            $product = [
                'item_id'           => (int) $post->ID,
                'slug'              => sanitize_key( $resolved_slug ),
                'name'              => (string) get_the_title( $post ),
                'product_unique_id' => self::get_product_unique_id( $post->ID ),
                'current_version'   => self::get_product_version( $post->ID ),
            ];

            return $product;
        }

        protected static function get_unique_id_meta_key_for_query() {
            $keys = self::get_product_unique_id_meta_keys();
            return (string) reset( $keys );
        }

        protected static function get_product_unique_id_meta_keys() {
            $keys = apply_filters( 'uupd_wsl_compat/product_unique_id_meta_keys', [
                '_sl_software_unique_title',
                '_sl_software_title',
                '_software_licence_unique_id',
                '_product_unique_id',
                'product_unique_id',
                '_woo_sl_product_unique_id',
                'woo_sl_product_unique_id',
                '_uupd_product_unique_id',
            ] );
            return array_values( array_filter( array_map( 'strval', (array) $keys ) ) );
        }

        protected static function get_product_version_meta_keys() {
            $keys = apply_filters( 'uupd_wsl_compat/version_meta_keys', [
                '_sl_new_version',
                self::VERSION_META_KEY,
                '_version',
                'version',
                'current_version',
                '_current_version',
            ] );
            return array_values( array_filter( array_map( 'strval', (array) $keys ) ) );
        }

        protected static function get_product_unique_id( $post_id ) {
            foreach ( self::get_product_unique_id_meta_keys() as $key ) {
                $value = get_post_meta( $post_id, $key, true );
                if ( '' !== (string) $value ) {
                    return (string) $value;
                }
            }
            return '';
        }

        protected static function get_product_version( $post_id ) {
            foreach ( self::get_product_version_meta_keys() as $key ) {
                $value = get_post_meta( $post_id, $key, true );
                if ( '' !== (string) $value ) {
                    return (string) $value;
                }
            }
            return '1.0.0';
        }

        protected static function normalize_domain_host( $domain ) {
            $domain = trim( (string) $domain );
            if ( '' === $domain ) {
                $domain = wp_parse_url( home_url(), PHP_URL_HOST );
            }
            $host = wp_parse_url( $domain, PHP_URL_HOST );
            if ( $host ) {
                return (string) $host;
            }
            $domain = preg_replace( '#^https?://#i', '', $domain );
            return rtrim( (string) $domain, '/' );
        }

        protected static function ensure_index_php( $server ) {
            $server = rtrim( (string) $server, '/' );
            if ( substr( $server, -9 ) !== 'index.php' ) {
                $server .= '/index.php';
            }
            return $server;
        }

        protected static function sanitize_license_key( $value ) {
            return trim( preg_replace( '/[^A-Za-z0-9\-_:]/', '', (string) $value ) );
        }

        protected static function extract_message( array $data, $action ) {
            if ( isset( $data['message'] ) && ! is_array( $data['message'] ) ) {
                return (string) $data['message'];
            }
            if ( ! empty( $data['success'] ) ) {
                if ( 'activate' === $action ) {
                    return 'License activated.';
                }
                if ( 'deactivate' === $action ) {
                    return 'License deactivated.';
                }
                return 'License status retrieved.';
            }
            return 'License request failed.';
        }

        protected static function read_first_non_empty( array $source, array $keys ) {
            foreach ( $keys as $key ) {
                if ( array_key_exists( $key, $source ) && '' !== (string) $source[ $key ] ) {
                    return $source[ $key ];
                }
            }
            return '';
        }


        protected static function lookup_wsl_license_details( $license_key, $domain ) {
            global $wpdb;

            $license_key = self::sanitize_license_key( $license_key );
            if ( '' === $license_key || empty( $wpdb ) ) {
                return [];
            }

            $domain_host = self::normalize_domain_host( $domain );
            $license_table = $wpdb->prefix . 'woocommerce_software_licence';
            $itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

            $row = null;
            $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $license_table ) );
            if ( $table_exists === $license_table ) {
                if ( '' !== $domain_host ) {
                    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$license_table} WHERE licence = %s AND active_domain = %s ORDER BY id DESC LIMIT 1", $license_key, $domain_host ), ARRAY_A );
                }
                if ( ! $row ) {
                    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$license_table} WHERE licence = %s ORDER BY id DESC LIMIT 1", $license_key ), ARRAY_A );
                }
            }

            $details = [];
            if ( $row ) {
                $details['license_id'] = isset( $row['id'] ) ? (int) $row['id'] : null;
                $details['order_id'] = isset( $row['order_id'] ) ? (int) $row['order_id'] : null;
                $details['order_item_id'] = isset( $row['order_item_id'] ) ? (int) $row['order_item_id'] : null;
                $details['group_id'] = isset( $row['group_id'] ) ? (int) $row['group_id'] : 0;
                $details['created'] = isset( $row['created'] ) ? (string) $row['created'] : '';
                $details['active_domain'] = isset( $row['active_domain'] ) ? (string) $row['active_domain'] : '';
            }

            if ( ! empty( $details['order_item_id'] ) ) {
                $raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$itemmeta_table} WHERE order_item_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1", (int) $details['order_item_id'], '_woo_Sl' ) );
                $status = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$itemmeta_table} WHERE order_item_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1", (int) $details['order_item_id'], '_woo_sl_licensing_status' ) );
                if ( null !== $status && '' !== (string) $status ) {
                    $details['order_item_status'] = (string) $status;
                }
                $group_meta = maybe_unserialize( $raw );
                if ( is_array( $group_meta ) ) {
                    $idx = isset( $details['group_id'] ) ? (int) $details['group_id'] : 0;
                    $max_instances = self::extract_group_value( $group_meta, 'max_instances_per_key', $idx );
                    if ( '' !== (string) $max_instances ) {
                        $details['activation_limit'] = $max_instances;
                        $details['max_activations'] = $max_instances;
                    }
                    $use_expire = self::extract_group_value( $group_meta, 'product_use_expire', $idx );
                    $expire_value = self::extract_group_value( $group_meta, 'product_expire_units', $idx );
                    $expire_unit = self::extract_group_value( $group_meta, 'product_expire_time', $idx );
                    if ( in_array( strtolower( (string) $use_expire ), [ 'yes', '1', 'true' ], true ) && '' !== (string) $expire_value && ! empty( $details['created'] ) ) {
                        try {
                            $dt = new DateTime( $details['created'], new DateTimeZone( 'UTC' ) );
                            $unit = strtolower( (string) $expire_unit );
                            $spec = '';
                            if ( in_array( $unit, [ 'day', 'days' ], true ) ) {
                                $spec = 'P' . (int) $expire_value . 'D';
                            } elseif ( in_array( $unit, [ 'month', 'months' ], true ) ) {
                                $spec = 'P' . (int) $expire_value . 'M';
                            } elseif ( in_array( $unit, [ 'year', 'years' ], true ) ) {
                                $spec = 'P' . (int) $expire_value . 'Y';
                            }
                            if ( $spec !== '' ) {
                                $dt->add( new DateInterval( $spec ) );
                                $details['date_expires'] = $dt->format( 'Y-m-d H:i:s' );
                            }
                        } catch ( Exception $e ) {
                        }
                    } elseif ( ! empty( $group_meta['product_use_expire'] ) ) {
                        $details['date_expires'] = 'lifetime';
                    }
                }
            }

            if ( $table_exists === $license_table ) {
                $count = 0;
                if ( '' !== $domain_host ) {
                    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$license_table} WHERE licence = %s AND active_domain <> ''", $license_key ) );
                } else {
                    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$license_table} WHERE licence = %s", $license_key ) );
                }
                if ( $count > 0 ) {
                    $details['activations'] = $count;
                }
            }

            return $details;
        }

        protected static function extract_group_value( array $group_meta, $key, $index = 0 ) {
            if ( ! array_key_exists( $key, $group_meta ) ) {
                return '';
            }
            $value = $group_meta[ $key ];
            if ( is_array( $value ) ) {
                if ( array_key_exists( $index, $value ) ) {
                    return $value[ $index ];
                }
                $first = reset( $value );
                return is_scalar( $first ) ? (string) $first : '';
            }
            return is_scalar( $value ) ? (string) $value : '';
        }

        public static function enqueue_admin_assets( $hook ) {
            if ( 'edit.php' !== $hook ) {
                return;
            }
            $screen = get_current_screen();
            if ( ! $screen || $screen->post_type !== self::get_product_post_type() ) {
                return;
            }
            wp_add_inline_script( 'jquery-core', "document.addEventListener('click',async function(e){var b=e.target.closest('.uupd-copy-btn');if(!b)return;var t=b.getAttribute('data-copy')||'';if(!t)return;try{await navigator.clipboard.writeText(t);var o=b.textContent;b.textContent='Copied';setTimeout(function(){b.textContent=o;},1200);}catch(err){window.prompt('Copy to clipboard:',t);}});" );
        }

        public static function output_admin_column_styles() {
            $screen = get_current_screen();
            if ( ! $screen || $screen->post_type !== self::get_product_post_type() ) {
                return;
            }
            echo '<style>'
                . '.column-uupd_bootloader,.column-uupd_inline{width:170px;white-space:nowrap;}'
                . '.uupd-copy-btn{white-space:nowrap;}'
                . '</style>';
        }

        public static function add_product_columns( $columns ) {
            $new = [];
            foreach ( $columns as $key => $label ) {
                $new[ $key ] = $label;
                if ( 'name' === $key ) {
                    $new['uupd_bootloader'] = 'UUPD Bootloader';
                    $new['uupd_inline']     = 'UUPD Inline';
                }
            }
            if ( ! isset( $new['uupd_bootloader'] ) ) {
                $new['uupd_bootloader'] = 'UUPD Bootloader';
                $new['uupd_inline']     = 'UUPD Inline';
            }
            return $new;
        }

        public static function render_product_columns( $column, $post_id ) {
            if ( ! in_array( $column, [ 'uupd_bootloader', 'uupd_inline' ], true ) ) {
                return;
            }
            $product = self::resolve_product( '', (string) $post_id );
            if ( empty( $product['slug'] ) ) {
                echo '&mdash;';
                return;
            }
            if ( 'uupd_bootloader' === $column ) {
                $copy = self::build_bootloader_snippet( $product );
                echo '<button type="button" class="button button-small uupd-copy-btn" data-copy="' . esc_attr( $copy ) . '">Copy bootloader</button>';
                return;
            }
            $copy = self::build_inline_snippet( $product );
            echo '<button type="button" class="button button-small uupd-copy-btn" data-copy="' . esc_attr( $copy ) . '">Copy inline</button>';
        }

        public static function maybe_handle_copy() {
            // reserved for future dedicated generator UI if needed
        }

        public static function register_settings_page() {
            add_options_page(
                'UUPD WSL Settings',
                'UUPD WSL Settings',
                'manage_options',
                'uupd-wsl-settings',
                [ __CLASS__, 'render_settings_page' ]
            );
        }

        public static function render_settings_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'default' ) );
            }

            if (
                isset( $_POST['uupd_wsl_vendor_nonce'] ) &&
                wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uupd_wsl_vendor_nonce'] ) ), 'uupd_wsl_save_vendor' )
            ) {
                $vendor = isset( $_POST['uupd_wsl_vendor'] ) ? sanitize_key( wp_unslash( $_POST['uupd_wsl_vendor'] ) ) : '';

                if ( '' === $vendor ) {
                    delete_option( self::OPTION_VENDOR );
                } else {
                    update_option( self::OPTION_VENDOR, $vendor, false );
                }

                echo '<div class="notice notice-success is-dismissible"><p>Vendor saved.</p></div>';
            }

            $vendor = self::get_vendor_slug_raw();
            ?>
            <div class="wrap">
                <h1>UUPD WSL Settings</h1>
                <form method="post">
                    <?php wp_nonce_field( 'uupd_wsl_save_vendor', 'uupd_wsl_vendor_nonce' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="uupd_wsl_vendor">Global vendor slug</label></th>
                            <td>
                                <input type="text" id="uupd_wsl_vendor" name="uupd_wsl_vendor" value="<?php echo esc_attr( $vendor ); ?>" class="regular-text" placeholder="rup" />
                                <p class="description">Used in generated bootloader constants and vendor-aware UI/updater registration.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Save vendor' ); ?>
                </form>
            </div>
            <?php
        }

        protected static function get_vendor_slug_raw() {
            $value = get_option( self::OPTION_VENDOR, '' );
            return is_string( $value ) ? sanitize_key( $value ) : '';
        }

        protected static function get_vendor_slug( array $product = [] ) {
            $vendor = self::get_vendor_slug_raw();
            if ( '' === $vendor ) {
                $vendor = 'yourvendor';
            }

            $vendor = apply_filters( 'uupd_wsl_compat/vendor', $vendor, $product );
            $vendor = sanitize_key( (string) $vendor );

            return '' !== $vendor ? $vendor : 'yourvendor';
        }

        protected static function build_vendor_slug_constant_stem( $vendor, $slug ) {
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
                $slug_base = 'PRODUCT';
            }

            return 'UUPD_' . $vendor_base . '_' . $slug_base;
        }

        protected static function build_bootloader_snippet( array $product ) {
		    $vendor      = self::get_vendor_slug( $product );
		    $const       = self::build_vendor_slug_constant_stem( $vendor, $product['slug'] );
		    $server      = trailingslashit( home_url( '/' ) );
		    $plugin_name = (string) $product['name'];
		    $menu_title  = $plugin_name . ' License';

		    return "define( '{$const}_VENDOR', '" . addslashes( (string) $vendor ) . "' );
		"
		        . "define( '{$const}_VERSION', '" . addslashes( (string) $product['current_version'] ) . "' );
		"
		        . "define( '{$const}_MAIN_FILE', __FILE__ );
		"
		        . "define( '{$const}_ITEM_NUMBER', " . (int) $product['item_id'] . " );
		"
		        . "define( '{$const}_FRIENDLY_NAME', '" . addslashes( $plugin_name ) . "' );
		"
		        . "define( '{$const}_SLUG', '" . addslashes( (string) $product['slug'] ) . "' );
		"
		        . "define( '{$const}_SERVER_BASE', '" . esc_url_raw( $server ) . "' );
		"
		        . "define( '{$const}_MENU_SLUG', {$const}_VENDOR . '-' . {$const}_SLUG );

		"
		        . "add_action( 'plugins_loaded', function() {
		"
		        . "    require_once __DIR__ . '/inc/updater.php';
		"
		        . "    require_once __DIR__ . '/inc/class-uupd-license-ui.php';

		"
		        . "    \UUPD\UI\License\V1\UUPD_License_UI::register([
		"
		        . "        'vendor'         => {$const}_VENDOR,
		"
		        . "        'slug'           => {$const}_SLUG,
		"
		        . "        'item_id'        => {$const}_ITEM_NUMBER,
		"
		        . "        'license_server' => {$const}_SERVER_BASE,
		"
		        . "        'metadata_base'  => {$const}_SERVER_BASE,
		"
		        . "        'plugin_name'    => {$const}_FRIENDLY_NAME,
		"
		        . "        'menu_parent'    => 'top_level',
		"
		        . "        'menu_slug'      => {$const}_MENU_SLUG,
		"
		        . "        'page_title'     => {$const}_FRIENDLY_NAME . ' License',
		"
		        . "        'menu_title'     => '" . addslashes( $menu_title ) . "',
		"
		        . "        'capability'     => 'manage_options',
		"
		        . "    ]);

		"
		        . "    \UUPD\V2\UUPD_Updater_V2::register([
		"
		        . "        'vendor'      => {$const}_VENDOR,
		"
		        . "        'plugin_file' => plugin_basename( {$const}_MAIN_FILE ),
		"
		        . "        'slug'        => {$const}_SLUG,
		"
		        . "        'name'        => {$const}_FRIENDLY_NAME,
		"
		        . "        'version'     => {$const}_VERSION,
		"
		        . "        'server'      => '',
		"
		        . "    ]);
		"
		        . "}, 1 );";
		}

        protected static function build_inline_snippet( array $product ) {
		    $vendor = self::get_vendor_slug( $product );
		    $const  = self::build_vendor_slug_constant_stem( $vendor, $product['slug'] );

		    return "<?php \$inst = \\UUPD\\UI\\License\\V1\\UUPD_License_UI::get_instance( {$const}_VENDOR, {$const}_SLUG ); if ( \$inst ) { \$inst->render_page(); } ?>";
		}
    }

    UUPD_WSL_Compatibility_Layer::init();
}
