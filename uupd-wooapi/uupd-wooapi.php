<?php
/**
 * Plugin Name: UUPD > Woo API
 * Version: 0.6.4
 * Author:
 * Requires PHP: 7.4
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


if ( ! class_exists( 'UUPD_WC_AM_Compat_Layer' ) ) {
	final class UUPD_WC_AM_Compat_Layer {
		const VERSION    = '0.6.3';
		const OPTION_KEY    = 'uupd_wc_am_compat_settings';
		const OPTION_VENDOR = 'uupd_wc_am_compat_vendor';
		const OPTION_IGNORE_STAGING_ACTIVATIONS = 'woocommerce_api_manager_ignore_staging_activations';

		const SLUG_META_KEY                 = '_uupd_plugin_slug';
		const VERSION_META_KEY              = '_uupd_current_version';
		const PRODUCT_ID_META_KEY           = '_uupd_product_id';
		const PLUGIN_FILE_META_KEY          = '_uupd_plugin_file';
		const NAME_META_KEY                 = '_uupd_name';
		const REQUIRES_META_KEY             = '_uupd_requires_wp';
		const TESTED_META_KEY               = '_uupd_tested_wp';
		const REQUIRES_PHP_META_KEY         = '_uupd_requires_php';
		const LAST_UPDATED_META_KEY         = '_uupd_last_updated';
		const AUTHOR_META_KEY               = '_uupd_author';
		const AUTHOR_URL_META_KEY           = '_uupd_author_homepage';
		const HOMEPAGE_META_KEY             = '_uupd_homepage';
		const BANNER_LOW_META_KEY           = '_uupd_banner_low';
		const BANNER_HIGH_META_KEY          = '_uupd_banner_high';
		const ICON_1X_META_KEY              = '_uupd_icon_1x';
		const ICON_2X_META_KEY              = '_uupd_icon_2x';
		const SECTION_DESCRIPTION_META_KEY  = '_uupd_section_description';
		const SECTION_INSTALLATION_META_KEY = '_uupd_section_installation';
		const SECTION_CHANGELOG_META_KEY    = '_uupd_section_changelog';
		const SECTION_FAQ_META_KEY          = '_uupd_section_faq';

		public static function init() {
			add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
			add_action( 'init', [ __CLASS__, 'maybe_handle_legacy_metadata' ], 0 );
			add_action( 'admin_menu', [ __CLASS__, 'register_settings_page' ] );
			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_assets' ] );
			add_action( 'admin_footer-post.php', [ __CLASS__, 'render_product_media_template' ] );
			add_action( 'admin_footer-post-new.php', [ __CLASS__, 'render_product_media_template' ] );
			add_action( 'save_post_product', [ __CLASS__, 'save_product_media_meta' ] );
			// Code generation is available under Settings > UUPD Settings.

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
			$key     = uupd_compat_metadata_key();
			$domain  = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '';
			$item_id = isset( $_GET['item_id'] ) ? sanitize_text_field( wp_unslash( $_GET['item_id'] ) ) : '';
			$version = isset( $_GET['version'] ) ? sanitize_text_field( wp_unslash( $_GET['version'] ) ) : '';
			$context = self::request_context_from_array( $_GET );

			list( $data, $status ) = self::build_uupd_metadata( $slug, $key, $domain, $item_id, $version, $context );
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
			$item_id     = isset( $payload['item_id'] ) ? (string) $payload['item_id'] : '';
			$domain      = isset( $payload['domain'] ) ? sanitize_text_field( (string) $payload['domain'] ) : home_url();
			$version     = isset( $payload['version'] ) ? sanitize_text_field( (string) $payload['version'] ) : '';
			$context     = self::request_context_from_array( $payload );

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

			$product = self::resolve_product( $slug, $item_id, $context, '' );
			if ( empty( $product['product_id'] ) ) {
				return new WP_REST_Response(
					[
						'success' => false,
						'message' => 'Could not resolve a Woo API product for the supplied slug/item_id.',
						'status'  => 'inactive',
					],
					404
				);
			}

			$ignore_staging_activations = self::should_ignore_staging_activations_for_domain( $domain );
			$instance                    = self::get_instance_id( $product['slug'], $domain, $ignore_staging_activations );

			$entitlement = self::validate_license_entitlement( $license_key, (int) $product['product_id'], $instance );
			if ( ! $entitlement['key_active'] ) {
				return new WP_REST_Response(
					[
						'success'     => false,
						'message'     => 'The API Key is not active or does not exist.',
						'status'      => 'inactive',
						'license'     => 'invalid',
						'product_id'  => (int) $product['product_id'],
						'slug'        => (string) $product['slug'],
						'entitlement' => $entitlement,
					],
					403
				);
			}

			if ( ! $entitlement['entitled'] ) {
				return new WP_REST_Response(
					[
						'success'     => false,
						'message'     => 'This license key is not entitled to activate or check this product.',
						'status'      => 'inactive',
						'license'     => 'invalid',
						'product_id'  => (int) $product['product_id'],
						'slug'        => (string) $product['slug'],
						'entitlement' => $entitlement,
					],
					403
				);
			}

			if ( $ignore_staging_activations ) {
				$api_data = [
					'success'        => true,
					'staging_bypass' => true,
					'message'        => ( 'deactivate' === $action )
						? 'Staging site detected. Remote deactivation skipped because staging activations are ignored.'
						: 'Staging site detected. Remote activation/status skipped because staging activations are ignored.',
				];

				$status  = ( 'deactivate' === $action ) ? 'inactive' : 'active';
				$success = true;
				$http    = 200;
			} else {
				$api_data = self::wc_am_request(
					self::map_license_action( $action ),
					[
						'api_key'    => $license_key,
						'product_id' => (string) $product['product_id'],
						'instance'   => $instance,
						'version'    => $version,
						'object'     => self::normalize_domain_host( $domain ),
					]
				);

				$status  = self::map_license_status( $api_data, $action );
				$success = ! empty( $api_data['success'] ) || ( 'active' === $status ) || ( 'deactivate' === $action && 'inactive' === $status );
				$http    = $success ? 200 : 400;

				if ( ! $success && ! empty( $api_data['code'] ) && (string) $api_data['code'] === '100' && 'activate' === $action ) {
					if ( ! empty( $api_data['error'] ) && false !== stripos( (string) $api_data['error'], 'already been activated with the same unique instance ID' ) ) {
						$success = true;
						$status  = 'active';
						$http    = 200;
					}
				}
			}

			$response = [
				'success'               => (bool) $success,
				'ignore_staging_domain' => (bool) $ignore_staging_activations,
				'message'               => self::extract_message( $api_data, $action ),
				'status'                => $status,
				'license_status'        => $status,
				'license'               => ( 'active' === $status ) ? 'valid' : 'invalid',
				'error'                 => (string) ( $api_data['error'] ?? '' ),
				'item_id'               => (int) $product['item_id'],
				'product_id'            => (int) $product['product_id'],
				'slug'                  => (string) $product['slug'],
				'item_name'             => (string) $product['name'],
				'instance'              => (string) $instance,
				'activation_limit'      => self::pick( $api_data, [ 'activation_limit', 'max_activations', 'total_activations_purchased' ] ),
				'max_activations'       => self::pick( $api_data, [ 'activation_limit', 'max_activations', 'total_activations_purchased' ] ),
				'activations'           => self::pick( $api_data, [ 'activations', 'used_activations', 'total_activations' ] ),
				'activations_remaining' => self::pick( $api_data, [ 'activations_remaining' ] ),
				'date_expires'          => self::extract_expiry( $api_data ),
				'entitlement'           => $entitlement,
				'raw_response'          => $api_data,
			];

			self::log(
				'license_' . $action,
				[
					'slug'       => $slug,
					'product_id' => $product['product_id'],
					'http'       => $http,
					'success'    => $success,
				]
			);

			return new WP_REST_Response( $response, $http );
		}

		public static function handle_metadata_request( WP_REST_Request $request ) {
			$slug    = sanitize_key( (string) $request->get_param( 'slug' ) );
			$key     = self::sanitize_license_key( uupd_compat_metadata_key( $request ) );
			$domain  = sanitize_text_field( (string) $request->get_param( 'domain' ) );
			$item_id = (string) $request->get_param( 'item_id' );
			$version = (string) $request->get_param( 'version' );
			$context = self::request_context_from_request( $request );

			list( $data, $status ) = self::build_uupd_metadata( $slug, $key, $domain, $item_id, $version, $context );
			return new WP_REST_Response( $data, (int) $status );
		}

		protected static function build_uupd_metadata( $slug, $license_key, $domain, $item_id = '', $installed_version = '', array $context = [] ) {
			if ( '' === $slug && '' === (string) $item_id && empty( $context['product_id'] ) ) {
				return [ [ 'success' => false, 'message' => 'Missing slug or item_id.' ], 400 ];
			}

			$product = self::resolve_product( $slug, $item_id, $context, '' );
			if ( empty( $product['product_id'] ) ) {
				return [ [ 'success' => false, 'message' => 'Could not resolve a Woo API product for the supplied slug/item_id.' ], 404 ];
			}

			$ignore_staging_activations = self::should_ignore_staging_activations_for_domain( $domain );
			$instance                    = self::get_instance_id( $product['slug'], $domain, $ignore_staging_activations );
			$object                      = self::normalize_domain_host( $domain );
			$license_status_payload      = [];
			$license_status              = 'inactive';
			$entitlement                 = [ 'key_active' => false, 'entitled' => false, 'product_ids' => [], 'product_rows' => [] ];

			if ( '' !== $license_key ) {
				$entitlement = self::validate_license_entitlement( $license_key, (int) $product['product_id'], $instance );
				if ( $entitlement['key_active'] && $entitlement['entitled'] ) {
					if ( $ignore_staging_activations ) {
						$license_status_payload = [
							'success'        => true,
							'staging_bypass' => true,
							'message'        => 'Staging site detected. Treating this entitled key as active because staging activations are ignored.',
						];
						$license_status = 'active';
					} else {
						$license_status_payload = self::wc_am_request(
							'status',
							[
								'api_key'    => $license_key,
								'product_id' => (string) $product['product_id'],
								'instance'   => (string) $instance,
								'version'    => (string) $installed_version,
								'object'     => $object,
							]
						);
						$license_status = self::map_license_status( $license_status_payload, 'check' );
					}
				} elseif ( ! $entitlement['key_active'] ) {
					$license_status = 'inactive';
				} else {
					$license_status = 'invalid';
				}
			}

			$update_payload = self::wc_am_request(
				'update',
				[
					'product_id'  => (string) $product['product_id'],
					'plugin_name' => (string) $product['plugin_file'],
					'slug'        => (string) $product['slug'],
					'version'     => (string) ( $installed_version ?: $product['current_version'] ?: '0.0.0' ),
					'instance'    => (string) $instance,
					'api_key'     => (string) $license_key,
					'object'      => $object,
				]
			);

			$info_payload = self::wc_am_request(
				'information',
				[
					'product_id'  => (string) $product['product_id'],
					'plugin_name' => (string) $product['plugin_file'],
					'version'     => (string) ( $installed_version ?: $product['current_version'] ?: '0.0.0' ),
					'instance'    => (string) $instance,
					'api_key'     => (string) $license_key,
					'object'      => $object,
				]
			);

			$mapped = self::map_wc_am_metadata( $update_payload, $info_payload, $product );

			$response = array_merge(
				[
					'success'         => true,
					'slug'            => (string) $product['slug'],
					'name'            => (string) $product['name'],
					'version'         => (string) $product['current_version'],
					'new_version'     => (string) $product['current_version'],
					'author'          => (string) ( $product['author'] ?: get_bloginfo( 'name' ) ),
					'author_homepage' => (string) ( $product['author_homepage'] ?: home_url( '/' ) ),
					'homepage'        => (string) $product['homepage'],
					'download_url'    => '',
					'package'         => '',
					'download_link'   => '',
					'requires'        => '',
					'tested'          => '',
					'requires_php'    => '',
					'last_updated'    => '',
					'sections'        => [],
					'banners'         => [],
					'icons'           => [],
				],
				$mapped,
				[
					'license_required'      => true,
					'license_status'        => $license_status,
					'ignore_staging_domain' => (bool) $ignore_staging_activations,
					'item_id'          => (int) $product['item_id'],
					'product_id'       => (int) $product['product_id'],
					'instance'         => (string) $instance,
					'plugin_file'      => (string) $product['plugin_file'],
					'activation_limit' => self::pick( $license_status_payload, [ 'activation_limit', 'max_activations', 'total_activations_purchased' ] ),
					'max_activations'  => self::pick( $license_status_payload, [ 'activation_limit', 'max_activations', 'total_activations_purchased' ] ),
					'activations'      => self::pick( $license_status_payload, [ 'activations', 'used_activations', 'total_activations' ] ),
					'date_expires'     => self::extract_expiry( $license_status_payload ),
					'entitlement'      => $entitlement,
					'raw_response'     => [
						'status'      => $license_status_payload,
						'update'      => $update_payload,
						'information' => $info_payload,
					],
				]
			);

			if ( empty( $mapped['download_url'] ) && '' !== $license_key && 'active' !== $license_status ) {
				$response['success'] = false;
				$response['message'] = 'No authenticated package URL returned. Check the license status and product mapping.';
			} elseif ( $ignore_staging_activations && ! empty( $mapped['download_url'] ) && ! empty( $entitlement['key_active'] ) && ! empty( $entitlement['entitled'] ) ) {
				$response['success'] = true;
				$response['message'] = 'Staging site detected and allowed. Serving update metadata without enforcing a staging activation.';
			}

			return [ $response, 200 ];
		}

		protected static function wc_am_request( $action, array $args ) : array {
			$query = array_filter(
				array_merge(
					[
						'wc-api'       => 'wc-am-api',
						'wc_am_action' => $action,
					],
					$args
				),
				static function ( $value ) {
					return '' !== (string) $value && null !== $value;
				}
			);

			$trusted_source_ip = (string) apply_filters( 'uupd_wc_am_compat/trusted_source_ip', '' );

			if ( '' !== $trusted_source_ip ) {
				$query['ip_address'] = $trusted_source_ip;
			}

			$endpoint = add_query_arg( $query, trailingslashit( self::get_server_base() ) );

			$response = wp_remote_get(
				$endpoint,
				[
					'timeout'   => 20,
					'sslverify' => true,
					'headers'   => [ 'Accept' => 'application/json' ],
				]
			);

			if ( is_wp_error( $response ) ) {
				return [
					'success' => false,
					'message' => $response->get_error_message(),
					'error'   => $response->get_error_code(),
				];
			}

			$body = wp_remote_retrieve_body( $response );
			$json = json_decode( (string) $body, true );

			if ( ! is_array( $json ) ) {
				return [
					'success' => false,
					'message' => 'Invalid response from Woo API Manager.',
					'raw'     => $body,
				];
			}

			return $json;
		}

		protected static function map_wc_am_metadata( array $update, array $info, array $product ) : array {
			$update_pkg = (array) ( $update['data']['package'] ?? [] );
			$info_pkg   = (array) ( $info['data']['info'] ?? [] );

			$version = self::pick( $update_pkg, [ 'new_version', 'version' ] );
			if ( '' === $version ) {
				$version = self::pick( $info_pkg, [ 'version' ] );
			}

			$download = self::pick( $update_pkg, [ 'package', 'download_url', 'download_link', 'trunk' ] );
			$sections = (array) ( $info_pkg['sections'] ?? [] );
			if ( empty( $sections ) && ! empty( $product['sections'] ) ) {
				$sections = $product['sections'];
			}

			return [
				'slug'            => (string) ( self::pick( $update_pkg, [ 'slug' ] ) ?: $product['slug'] ),
				'name'            => (string) ( self::pick( $info_pkg, [ 'name' ] ) ?: $product['name'] ),
				'version'         => (string) ( $version ?: $product['current_version'] ),
				'new_version'     => (string) ( $version ?: $product['current_version'] ),
				'author'          => (string) ( self::pick( $info_pkg, [ 'author' ] ) ?: $product['author'] ?: get_bloginfo( 'name' ) ),
				'author_homepage' => (string) ( self::pick( $info_pkg, [ 'author_profile' ] ) ?: $product['author_homepage'] ),
				'homepage'        => (string) ( self::pick( $update_pkg, [ 'url' ] ) ?: self::pick( $info_pkg, [ 'homepage' ] ) ?: $product['homepage'] ),
				'download_url'    => (string) $download,
				'package'         => (string) $download,
				'download_link'   => (string) $download,
				'requires'        => (string) ( self::pick( $info_pkg, [ 'requires' ] ) ?: $product['requires'] ),
				'tested'          => (string) ( self::pick( $update_pkg, [ 'tested' ] ) ?: self::pick( $info_pkg, [ 'tested' ] ) ?: $product['tested'] ),
				'requires_php'    => (string) ( self::pick( $info_pkg, [ 'requires_php' ] ) ?: $product['requires_php'] ),
				'last_updated'    => (string) ( self::pick( $info_pkg, [ 'last_updated' ] ) ?: $product['last_updated'] ),
				'upgrade_notice'  => (string) self::pick( $update_pkg, [ 'upgrade_notice' ] ),
				'sections'        => $sections,
				'banners'         => $product['banners'],
				'icons'           => $product['icons'],
			];
		}

		protected static function resolve_product( $slug, $item_id, array $context = [], $license_key = '' ) : array {
			$slug    = sanitize_key( (string) $slug );
			$item_id = absint( $item_id );

			$context = wp_parse_args(
				$context,
				[
					'product_id'      => 0,
					'plugin_file'     => '',
					'plugin_name'     => '',
					'current_version' => '',
					'homepage'        => '',
				]
			);

			$empty = [
				'item_id'         => 0,
				'product_id'      => 0,
				'slug'            => '',
				'name'            => '',
				'plugin_file'     => '',
				'current_version' => '',
				'author'          => '',
				'author_homepage' => '',
				'homepage'        => '',
				'requires'        => '',
				'tested'          => '',
				'requires_php'    => '',
				'last_updated'    => '',
				'sections'        => [],
				'banners'         => [],
				'icons'           => [],
			];

			$post_type = (string) apply_filters( 'uupd_wc_am_compat/post_type', 'product' );

			$resource = [];
			if ( '' !== (string) $license_key ) {
				$resource = self::lookup_resource_by_license_key( $license_key );
			}

			if ( ! empty( $resource ) ) {
				$resource_product_id = absint( $resource['product_id'] ?? 0 );

				if ( ! $resource_product_id ) {
					$resource_product_id = absint( $resource['parent_id'] ?? 0 );
				}

				if ( ! $resource_product_id ) {
					$resource_product_id = absint( $resource['item_id'] ?? 0 );
				}

				if ( $resource_product_id ) {
					$post = get_post( $resource_product_id );

					if ( $post instanceof WP_Post && $post->post_type === $post_type ) {
						$resolved_slug = $slug ?: sanitize_key( $post->post_name );
						$resolved_name = sanitize_text_field( $context['plugin_name'] ?: get_the_title( $post->ID ) );
						$plugin_file   = sanitize_text_field( $context['plugin_file'] ?: $resolved_slug . '/' . $resolved_slug . '.php' );
						$media         = self::get_product_media_meta( (int) $post->ID );

						return [
							'item_id'         => $resource_product_id,
							'product_id'      => $resource_product_id,
							'slug'            => $resolved_slug,
							'name'            => $resolved_name,
							'plugin_file'     => $plugin_file,
							'current_version' => sanitize_text_field( (string) $context['current_version'] ),
							'author'          => '',
							'author_homepage' => '',
							'homepage'        => esc_url_raw( (string) ( $context['homepage'] ?: get_permalink( $post->ID ) ) ),
							'requires'        => '',
							'tested'          => '',
							'requires_php'    => '',
							'last_updated'    => '',
							'sections'        => [],
							'banners'         => $media['banners'],
							'icons'           => $media['icons'],
							'resource'        => $resource,
						];
					}
				}
			}

			$candidate_ids = array_unique(
				array_filter(
					[
						absint( $context['product_id'] ?? 0 ),
						$item_id,
					]
				)
			);

			foreach ( $candidate_ids as $candidate_id ) {
				$post = get_post( $candidate_id );

				if ( $post instanceof WP_Post && $post->post_type === $post_type ) {
					$resolved_slug = $slug ?: sanitize_key( $post->post_name );
					$resolved_name = sanitize_text_field( $context['plugin_name'] ?: get_the_title( $post->ID ) );
					$plugin_file   = sanitize_text_field( $context['plugin_file'] ?: $resolved_slug . '/' . $resolved_slug . '.php' );
					$media         = self::get_product_media_meta( (int) $post->ID );

					return [
						'item_id'         => $candidate_id,
						'product_id'      => $candidate_id,
						'slug'            => $resolved_slug,
						'name'            => $resolved_name,
						'plugin_file'     => $plugin_file,
						'current_version' => sanitize_text_field( (string) $context['current_version'] ),
						'author'          => '',
						'author_homepage' => '',
						'homepage'        => esc_url_raw( (string) ( $context['homepage'] ?: get_permalink( $post->ID ) ) ),
						'requires'        => '',
						'tested'          => '',
						'requires_php'    => '',
						'last_updated'    => '',
						'sections'        => [],
						'banners'         => $media['banners'],
						'icons'           => $media['icons'],
					];
				}
			}

			if ( $slug ) {
				$posts = get_posts(
					[
						'name'           => sanitize_title( $slug ),
						'post_type'      => $post_type,
						'posts_per_page' => 1,
						'post_status'    => [ 'publish', 'draft', 'private', 'future' ],
					]
				);

				if ( ! empty( $posts[0] ) && $posts[0] instanceof WP_Post ) {
					$post          = $posts[0];
					$resolved_slug = sanitize_key( $post->post_name );
					$resolved_name = sanitize_text_field( $context['plugin_name'] ?: get_the_title( $post->ID ) );
					$plugin_file   = sanitize_text_field( $context['plugin_file'] ?: $resolved_slug . '/' . $resolved_slug . '.php' );
					$media         = self::get_product_media_meta( (int) $post->ID );

					return [
						'item_id'         => (int) $post->ID,
						'product_id'      => (int) $post->ID,
						'slug'            => $resolved_slug,
						'name'            => $resolved_name,
						'plugin_file'     => $plugin_file,
						'current_version' => sanitize_text_field( (string) $context['current_version'] ),
						'author'          => '',
						'author_homepage' => '',
						'homepage'        => esc_url_raw( (string) ( $context['homepage'] ?: get_permalink( $post->ID ) ) ),
						'requires'        => '',
						'tested'          => '',
						'requires_php'    => '',
						'last_updated'    => '',
						'sections'        => [],
						'banners'         => $media['banners'],
						'icons'           => $media['icons'],
					];
				}
			}

			$fallback_product_id = absint( $context['product_id'] ?: $item_id );
			$fallback_slug       = $slug ?: sanitize_key( basename( dirname( (string) $context['plugin_file'] ) ) );
			$fallback_name       = sanitize_text_field( (string) $context['plugin_name'] );
			$fallback_file       = sanitize_text_field( (string) $context['plugin_file'] );
			$fallback_version    = sanitize_text_field( (string) $context['current_version'] );

			if ( ! $fallback_slug && $fallback_product_id ) {
				$fallback_slug = 'product-' . $fallback_product_id;
			}

			if ( ! $fallback_file && $fallback_slug ) {
				$fallback_file = $fallback_slug . '/' . $fallback_slug . '.php';
			}

			if ( ! $fallback_name && $fallback_slug ) {
				$fallback_name = ucwords( str_replace( '-', ' ', $fallback_slug ) );
			}

			if ( $fallback_product_id || $fallback_slug ) {
				return [
					'item_id'         => $fallback_product_id,
					'product_id'      => $fallback_product_id,
					'slug'            => $fallback_slug,
					'name'            => $fallback_name,
					'plugin_file'     => $fallback_file,
					'current_version' => $fallback_version,
					'author'          => '',
					'author_homepage' => '',
					'homepage'        => esc_url_raw( (string) $context['homepage'] ),
					'requires'        => '',
					'tested'          => '',
					'requires_php'    => '',
					'last_updated'    => '',
					'sections'        => [],
					'banners'         => [],
					'icons'           => [],
				];
			}

			return $empty;
		}

		protected static function request_context_from_request( WP_REST_Request $request ) : array {
			return self::request_context_from_array(
				[
					'product_id'      => $request->get_param( 'product_id' ),
					'plugin_file'     => $request->get_param( 'plugin_file' ),
					'plugin_name'     => $request->get_param( 'plugin_name' ),
					'name'            => $request->get_param( 'name' ),
					'version'         => $request->get_param( 'version' ),
					'current_version' => $request->get_param( 'current_version' ),
					'homepage'        => $request->get_param( 'homepage' ),
				]
			);
		}

		protected static function request_context_from_array( array $source ) : array {
			return [
				'product_id'      => absint( $source['product_id'] ?? 0 ),
				'plugin_file'     => sanitize_text_field( (string) ( $source['plugin_file'] ?? '' ) ),
				'plugin_name'     => sanitize_text_field( (string) ( $source['plugin_name'] ?? ( $source['name'] ?? '' ) ) ),
				'current_version' => sanitize_text_field( (string) ( $source['current_version'] ?? ( $source['version'] ?? '' ) ) ),
				'homepage'        => esc_url_raw( (string) ( $source['homepage'] ?? '' ) ),
			];
		}

		protected static function map_license_action( $action ) : string {
			switch ( $action ) {
				case 'activate':
					return 'activate';
				case 'deactivate':
					return 'deactivate';
				default:
					return 'status';
			}
		}

		protected static function map_license_status( array $data, $action ) : string {
			$status_check = strtolower( (string) ( $data['status_check'] ?? '' ) );
			if ( in_array( $status_check, [ 'active', 'inactive', 'expired', 'disabled', 'invalid' ], true ) ) {
				return $status_check;
			}
			if ( isset( $data['data']['activated'] ) ) {
				return ! empty( $data['data']['activated'] ) ? 'active' : 'inactive';
			}
			if ( ! empty( $data['activated'] ) ) {
				return 'active';
			}
			if ( ! empty( $data['deactivated'] ) || 'deactivate' === $action ) {
				return 'inactive';
			}
			return ! empty( $data['success'] ) ? 'active' : 'inactive';
		}

		protected static function extract_message( array $data, $action ) : string {
			if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
				return $data['message'];
			}
			if ( ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
				return $data['error'];
			}
			if ( ! empty( $data['data']['error'] ) && is_string( $data['data']['error'] ) ) {
				return $data['data']['error'];
			}

			switch ( $action ) {
				case 'activate':
					return 'License activation processed.';
				case 'deactivate':
					return 'License deactivation processed.';
				default:
					return 'License status checked.';
			}
		}

		protected static function extract_expiry( array $data ) : string {
			$expirations = (array) ( $data['data']['api_key_expirations'] ?? [] );
			$first       = '';
			foreach ( [ 'non_wc_subs_resources', 'wc_subs_resources' ] as $bucket ) {
				if ( empty( $expirations[ $bucket ] ) || ! is_array( $expirations[ $bucket ] ) ) {
					continue;
				}
				foreach ( $expirations[ $bucket ] as $row ) {
					if ( ! empty( $row['friendly_api_key_expiration_date'] ) ) {
						return (string) $row['friendly_api_key_expiration_date'];
					}
					if ( ! empty( $row['api_key_expiration'] ) ) {
						$first = (string) $row['api_key_expiration'];
					}
				}
			}
			return $first;
		}

		protected static function lookup_resource_by_license_key( $license_key ) : array {
			global $wpdb;

			$license_key = self::sanitize_license_key( $license_key );
			if ( '' === $license_key ) {
				return [];
			}

			$candidate_tables = array_unique(
				array_filter(
					[
						$wpdb->prefix . 'wc_am_api_resource',
						$wpdb->base_prefix . 'wc_am_api_resource',
						'wp_wc_am_api_resource',
					]
				)
			);

			foreach ( $candidate_tables as $table ) {
				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
				if ( $exists !== $table ) {
					continue;
				}

				$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
				if ( empty( $columns ) || ! is_array( $columns ) ) {
					continue;
				}

				$where  = [];
				$values = [];
				foreach ( [ 'master_api_key', 'product_order_api_key' ] as $column ) {
					if ( in_array( $column, $columns, true ) ) {
						$where[]  = "{$column} = %s";
						$values[] = $license_key;
					}
				}
				if ( empty( $where ) ) {
					continue;
				}

				$order_by = '';
				foreach ( [ 'api_resource_id', 'pi_resource_id', 'resource_id', 'id' ] as $column ) {
					if ( in_array( $column, $columns, true ) ) {
						$order_by = " ORDER BY {$column} DESC";
						break;
					}
				}

				$sql = "SELECT * FROM {$table} WHERE " . implode( ' OR ', $where ) . $order_by . ' LIMIT 1';
				$row = $wpdb->get_row( $wpdb->prepare( $sql, ...$values ), ARRAY_A );
				if ( is_array( $row ) && ! empty( $row ) ) {
					return $row;
				}
			}

			return [];
		}

		protected static function validate_license_entitlement( $license_key, int $expected_product_id, string $instance ) : array {
			$result = [
				'key_active'   => false,
				'entitled'     => false,
				'product_ids'  => [],
				'product_rows' => [],
				'verify'       => [],
				'product_list' => [],
			];

			$license_key = self::sanitize_license_key( $license_key );
			if ( '' === $license_key || $expected_product_id <= 0 ) {
				return $result;
			}

			$verify               = self::wc_am_request( 'verify_api_key_is_active', [ 'api_key' => $license_key ] );
			$result['verify']     = $verify;
			$result['key_active'] = ! empty( $verify['success'] );
			if ( ! $result['key_active'] ) {
				return $result;
			}

			$product_list           = self::wc_am_request(
				'product_list',
				[
					'api_key'  => $license_key,
					'instance' => $instance,
				]
			);
			$result['product_list'] = $product_list;

			$rows = [];
			$ids  = [];
			$list = (array) ( $product_list['data']['product_list'] ?? [] );
			foreach ( [ 'wc_subs_resources', 'non_wc_subs_resources' ] as $bucket ) {
				if ( empty( $list[ $bucket ] ) || ! is_array( $list[ $bucket ] ) ) {
					continue;
				}
				foreach ( $list[ $bucket ] as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$pid = absint( $row['product_id'] ?? 0 );
					if ( ! $pid ) {
						continue;
					}
					$ids[]  = $pid;
					$rows[] = $row;
				}
			}

			$result['product_ids']  = array_values( array_unique( $ids ) );
			$result['product_rows'] = $rows;
			$result['entitled']     = in_array( $expected_product_id, $result['product_ids'], true );

			return $result;
		}

		protected static function product_field_value( int $post_id, string $meta_key, string $default = '' ) : string {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( '' === (string) $value ) {
				return $default;
			}
			return (string) $value;
		}

		protected static function get_product_media_meta( int $post_id ) : array {
			return [
				'banners' => array_filter(
					[
						'low'  => esc_url_raw( self::product_field_value( $post_id, self::BANNER_LOW_META_KEY ) ),
						'high' => esc_url_raw( self::product_field_value( $post_id, self::BANNER_HIGH_META_KEY ) ),
					]
				),
				'icons'   => array_filter(
					[
						'1x' => esc_url_raw( self::product_field_value( $post_id, self::ICON_1X_META_KEY ) ),
						'2x' => esc_url_raw( self::product_field_value( $post_id, self::ICON_2X_META_KEY ) ),
					]
				),
			];
		}


		public static function register_settings_page() : void {
			add_options_page(
				'UUPD Settings',
				'UUPD Settings',
				'manage_options',
				'uupd-settings',
				[ __CLASS__, 'render_settings_page' ]
			);
		}

		public static function render_settings_page() : void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'default' ) );
			}

			if (
				isset( $_POST['uupd_wc_am_vendor_nonce'] ) &&
				wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uupd_wc_am_vendor_nonce'] ) ), 'uupd_wc_am_save_vendor' )
			) {
				$vendor = isset( $_POST['uupd_wc_am_vendor'] ) ? sanitize_key( wp_unslash( $_POST['uupd_wc_am_vendor'] ) ) : '';

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
				<h1>UUPD Settings</h1>
                <?php uupd_woo_gen_menu_render('wooapi'); ?>
				<form method="post">
					<?php wp_nonce_field( 'uupd_wc_am_save_vendor', 'uupd_wc_am_vendor_nonce' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="uupd_wc_am_vendor">Global vendor slug</label>
							</th>
							<td>
								<input
									type="text"
									id="uupd_wc_am_vendor"
									name="uupd_wc_am_vendor"
									value="<?php echo esc_attr( $vendor ); ?>"
									class="regular-text"
									placeholder="rup"
								/>
								<p class="description">
									Used in generated bootloader constants and vendor-aware UI/updater registration.
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button( 'Save vendor' ); ?>
				</form>
			    <?php
                $products = get_posts(['post_type'=>'product','post_status'=>['publish','draft','private'],'posts_per_page'=>200,'orderby'=>'title','order'=>'ASC']);
                $selected = isset($_GET['uupd_product']) ? absint($_GET['uupd_product']) : 0;
                if (!$selected && $products) $selected = (int)$products[0]->ID;
                $ids = array_map('intval',wp_list_pluck($products,'ID'));
                if (!in_array($selected,$ids,true)) $selected = 0;
                $boot = $selected ? self::generated_code($selected,'bootloader') : '';
                $inline = $selected ? self::generated_code($selected,'inline') : '';
                $valid = $selected && $boot !== '' && strpos($boot,'UUPD_License_UI::register') !== false;
                ?>
                <div class="postbox" style="padding:18px;max-width:1000px">
                    <h2>Generate WooAPI bootloader</h2>
                    <p>Select a WooCommerce product and copy its bootloader or compact inline licence snippet.</p>
                    <form method="get">
                        <input type="hidden" name="page" value="uupd-settings">
                        <select name="uupd_product">
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo (int)$product->ID; ?>" <?php selected($selected,(int)$product->ID); ?>><?php echo esc_html($product->post_title . ' (#' . $product->ID . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="button">Generate code</button>
                    </form>
                    <?php if($valid): ?>
                        <h3>Bootloader</h3>
                        <button type="button" class="button uupd-settings-copy" data-target="uupd-woo-boot">Copy bootloader</button>
                        <textarea readonly id="uupd-woo-boot" rows="12" style="width:100%;font-family:monospace;margin:8px 0 18px"><?php echo esc_textarea($boot); ?></textarea>
                        <h3>Inline licence box</h3>
                        <button type="button" class="button uupd-settings-copy" data-target="uupd-woo-inline">Copy inline</button>
                        <textarea readonly id="uupd-woo-inline" rows="3" style="width:100%;font-family:monospace;margin:8px 0"><?php echo esc_textarea($inline); ?></textarea>
                    <?php else: ?>
                        <p class="description">Choose a product and save a valid licence menu configuration to generate code.</p>
                    <?php endif; ?>
                </div>
                <script>document.querySelectorAll('.uupd-settings-copy').forEach(function(b){b.addEventListener('click',function(){var t=document.getElementById(b.dataset.target);if(!t)return;t.select();if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t.value).catch(function(){document.execCommand('copy');});}else{document.execCommand('copy');}});});</script>
            </div>
            <?php
        }

        protected static function get_vendor_slug_raw() : string {
			$value = get_option( self::OPTION_VENDOR, '' );
			return is_string( $value ) ? sanitize_key( $value ) : '';
		}

		protected static function get_vendor_slug( array $product = [] ) : string {
			$vendor = self::get_vendor_slug_raw();
			if ( '' === $vendor ) {
				$vendor = 'yourvendor';
			}

			$vendor = apply_filters( 'uupd_wc_am_compat/vendor', $vendor, $product );
			$vendor = sanitize_key( (string) $vendor );

			return '' !== $vendor ? $vendor : 'yourvendor';
		}

		protected static function build_vendor_slug_constant_base( string $vendor, string $slug, int $post_id ) : string {
			$vendor_base = sanitize_key( $vendor );
			$vendor_base = strtoupper( str_replace( '-', '_', $vendor_base ) );
			$vendor_base = preg_replace( '/[^A-Z0-9_]+/', '_', $vendor_base );
			$vendor_base = preg_replace( '/_+/', '_', $vendor_base );
			$vendor_base = trim( $vendor_base, '_' );

			if ( '' === $vendor_base ) {
				$vendor_base = 'YOURVENDOR';
			}

			$slug_base = sanitize_key( $slug );
			$slug_base = strtoupper( str_replace( '-', '_', $slug_base ) );
			$slug_base = preg_replace( '/[^A-Z0-9_]+/', '_', $slug_base );
			$slug_base = preg_replace( '/_+/', '_', $slug_base );
			$slug_base = trim( $slug_base, '_' );

			if ( '' === $slug_base ) {
				$slug_base = 'PRODUCT';
			}

			return 'UUPD_WOOAPI_' . $vendor_base . '_' . $slug_base . '_' . $post_id;
		}

		public static function admin_assets( $hook ) : void {
			$is_product_screen = false;

			if ( isset( $_GET['post_type'] ) && 'product' === sanitize_key( (string) $_GET['post_type'] ) ) {
				$is_product_screen = true;
			}
			if ( isset( $_GET['post'] ) ) {
				$post = get_post( absint( $_GET['post'] ) );
				if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
					$is_product_screen = true;
				}
			}

			if ( ! $is_product_screen && false === strpos( (string) $hook, 'edit.php' ) ) {
				return;
			}

			wp_enqueue_media();

			$js = <<<'JS'
(function($){
	function copyText(btn){
		var text = btn.attr('data-uupd-copy') || '';
		if(!text){ return; }
		var done = function(){
			var original = btn.attr('data-uupd-label') || btn.text();
			btn.text('Copied');
			window.clearTimeout(btn.data('uupdTimer'));
			btn.data('uupdTimer', window.setTimeout(function(){ btn.text(original); }, 1200));
		};
		if(navigator.clipboard && navigator.clipboard.writeText){
			navigator.clipboard.writeText(text).then(done).catch(function(){ fallback(); });
		}else{
			fallback();
		}
		function fallback(){
			var ta = document.createElement('textarea');
			ta.value = text;
			document.body.appendChild(ta);
			ta.select();
			document.execCommand('copy');
			document.body.removeChild(ta);
			done();
		}
	}

	function bindCopy(){
		$(document).off('click.uupdcopy').on('click.uupdcopy', '[data-uupd-copy]', function(e){
			e.preventDefault();
			copyText($(this));
		});
	}

	function openMedia(input){
		if(typeof wp === 'undefined' || !wp.media){ return; }
		var frame = wp.media({
			title: 'Select image',
			button: { text: 'Use image' },
			library: { type: 'image' },
			multiple: false
		});
		frame.on('select', function(){
			var attachment = frame.state().get('selection').first().toJSON();
			input.val(attachment.url || '').trigger('change');
		});
		frame.open();
	}

	function bindMedia(){
		$(document).off('click.uupdmediaupload').on('click.uupdmediaupload', '.uupd-media-upload', function(e){
			e.preventDefault();
			var target = $('#' + $(this).data('target'));
			if(target.length){ openMedia(target); }
		});
		$(document).off('click.uupdmediaclear').on('click.uupdmediaclear', '.uupd-media-clear', function(e){
			e.preventDefault();
			var target = $('#' + $(this).data('target'));
			if(target.length){ target.val('').trigger('change'); }
		});
		$(document).off('change.uupdpreview').on('change.uupdpreview', '.uupd-image-url', function(){
			var wrap = $(this).closest('.uupd-image-field');
			var img = wrap.find('.uupd-image-preview img');
			var empty = wrap.find('.uupd-image-empty');
			if(this.value){
				img.attr('src', this.value).show();
				empty.hide();
			}else{
				img.attr('src', '').hide();
				empty.show();
			}
		});
	}

	function injectFields(){
		var template = $('#uupd-product-media-template');
		if(!template.length || template.data('mounted')){ return true; }

		var target = $('#licensing_product_data #api_fields_group_metadata');
		if(!target.length){
			return false;
		}

		if(!target.find('.uupd-media-heading').length){
			template.show().appendTo(target).data('mounted', true);
			return true;
		}

		return true;
	}

	function startInject(){
		var tries = 0;
		var timer = window.setInterval(function(){
			tries++;
			if(injectFields() || tries > 40){
				window.clearInterval(timer);
			}
		}, 250);

		if(window.MutationObserver){
			var obs = new MutationObserver(function(){ injectFields(); });
			obs.observe(document.body, { childList: true, subtree: true });
		}
	}

	$(function(){
		bindCopy();
		bindMedia();
		startInject();
	});
})(jQuery);
JS;
			wp_add_inline_script( 'jquery-core', $js );

			$css = '.post-type-product .wp-list-table{table-layout:auto !important;width:100% !important;}'
				. '.post-type-product .wp-list-table th.column-uupd_bootloader,.post-type-product .wp-list-table td.column-uupd_bootloader{width:180px !important;min-width:180px !important;white-space:nowrap !important;vertical-align:middle !important;}'
				. '.post-type-product .wp-list-table th.column-uupd_inline,.post-type-product .wp-list-table td.column-uupd_inline{width:140px !important;min-width:140px !important;white-space:nowrap !important;vertical-align:middle !important;}'
				. '.post-type-product .wp-list-table .column-uupd_bootloader,.post-type-product .wp-list-table .column-uupd_inline{overflow:visible !important;line-height:1.4 !important;}'
				. '.post-type-product .wp-list-table th.column-uupd_bootloader,.post-type-product .wp-list-table th.column-uupd_inline{line-height:1.2 !important;writing-mode:horizontal-tb !important;}'
				. '.post-type-product .wp-list-table .column-uupd_bootloader .button,.post-type-product .wp-list-table .column-uupd_inline .button{display:inline-flex !important;align-items:center;justify-content:center;white-space:nowrap !important;min-height:30px;padding:0 12px;line-height:28px;}'
				. '.post-type-product .wp-list-table .uupd-copy-stack{display:flex;align-items:center;gap:8px;min-height:32px;flex-wrap:nowrap;}'
				. '.post-type-product .wp-list-table th.column-uupd_bootloader .sorting-indicator,.post-type-product .wp-list-table th.column-uupd_inline .sorting-indicator{display:none !important;}'
				. '#uupd-product-media-template{display:none;margin-top:16px;padding-top:12px;border-top:1px solid #dcdcde;}'
				. '#uupd-product-media-template .uupd-media-heading{margin:0 0 10px;}'
				. '#uupd-product-media-template .uupd-image-field{margin:0 0 16px;}'
				. '#uupd-product-media-template .uupd-image-field label{display:block;font-weight:600;margin:0 0 6px;}'
				. '#uupd-product-media-template .uupd-image-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}'
				. '#uupd-product-media-template .uupd-image-row input[type=text]{width:420px;max-width:100%;}'
				. '#uupd-product-media-template .uupd-image-preview{margin-top:8px;}'
				. '#uupd-product-media-template .uupd-image-preview img{max-width:180px;height:auto;border:1px solid #dcdcde;background:#fff;padding:4px;display:block;}'
				. '#uupd-product-media-template .uupd-image-empty{color:#646970;font-style:italic;}';

			wp_register_style( 'uupd-wc-am-compat-admin', false, [], self::VERSION );
			wp_enqueue_style( 'uupd-wc-am-compat-admin' );
			wp_add_inline_style( 'uupd-wc-am-compat-admin', $css );
		}

		public static function render_product_media_template() : void {
			global $post;
			if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
				return;
			}

			echo '<div id="uupd-product-media-template">';
			echo '<div class="uupd-media-heading">';
			echo '<p><strong>UUPD WordPress.org-style assets</strong><br><em>Add optional icons and banners to return in the update metadata API.</em></p>';
			echo '</div>';

			wp_nonce_field( 'uupd_product_media_save', 'uupd_product_media_nonce' );

			self::render_product_image_field( (int) $post->ID, self::BANNER_LOW_META_KEY, 'Banner (Low)', 'Shown as banners.low in the metadata response.' );
			self::render_product_image_field( (int) $post->ID, self::BANNER_HIGH_META_KEY, 'Banner (High)', 'Shown as banners.high in the metadata response.' );
			self::render_product_image_field( (int) $post->ID, self::ICON_1X_META_KEY, 'Icon (1x)', 'Shown as icons.1x in the metadata response.' );
			self::render_product_image_field( (int) $post->ID, self::ICON_2X_META_KEY, 'Icon (2x)', 'Shown as icons.2x in the metadata response.' );

			echo '</div>';
		}

		protected static function render_product_image_field( int $post_id, string $meta_key, string $label, string $description ) : void {
			$value = esc_url( self::product_field_value( $post_id, $meta_key ) );
			$id    = 'uupd-field-' . sanitize_html_class( trim( $meta_key, '_' ) );

			echo '<p class="form-field uupd-image-field">';
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
			echo '<span class="wrap">';
			echo '<input type="text" class="short uupd-image-url" id="' . esc_attr( $id ) . '" name="' . esc_attr( $meta_key ) . '" value="' . $value . '" />';
			echo '<button type="button" class="button uupd-media-upload" data-target="' . esc_attr( $id ) . '">Upload / Select image</button> ';
			echo '<button type="button" class="button uupd-media-clear" data-target="' . esc_attr( $id ) . '">Clear</button>';
			echo '<span class="description" style="display:block;margin-top:6px;">' . esc_html( $description ) . '</span>';
			echo '<span class="uupd-image-preview">';
			if ( $value ) {
				echo '<img src="' . $value . '" alt="" />';
				echo '<span class="uupd-image-empty" style="display:none;">No image selected.</span>';
			} else {
				echo '<img src="" alt="" style="display:none;" />';
				echo '<span class="uupd-image-empty">No image selected.</span>';
			}
			echo '</span>';
			echo '</span>';
			echo '</p>';
		}

		public static function save_product_media_meta( int $post_id ) : void {
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( ! isset( $_POST['uupd_product_media_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uupd_product_media_nonce'] ) ), 'uupd_product_media_save' ) ) {
				return;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			foreach ( [ self::BANNER_LOW_META_KEY, self::BANNER_HIGH_META_KEY, self::ICON_1X_META_KEY, self::ICON_2X_META_KEY ] as $meta_key ) {
				$value = isset( $_POST[ $meta_key ] ) ? esc_url_raw( wp_unslash( $_POST[ $meta_key ] ) ) : '';
				if ( '' === $value ) {
					delete_post_meta( $post_id, $meta_key );
				} else {
					update_post_meta( $post_id, $meta_key, $value );
				}
			}
		}

		public static function product_columns( array $columns ) : array {
			$result = [];
			foreach ( $columns as $key => $label ) {
				$result[ $key ] = $label;
				if ( 'name' === $key ) {
					$result['uupd_bootloader'] = 'UUPD Bootloader';
					$result['uupd_inline']     = 'UUPD Inline';
				}
			}
			return $result;
		}

		public static function render_product_column( string $column, int $post_id ) : void {
			if ( ! in_array( $column, [ 'uupd_bootloader', 'uupd_inline' ], true ) ) {
				return;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				echo '&mdash;';
				return;
			}

			$slug       = self::product_field_value( $post_id, self::SLUG_META_KEY, $post->post_name );
			$name       = self::product_field_value( $post_id, self::NAME_META_KEY, get_the_title( $post_id ) );
			$server     = self::get_server_base();
			$vendor     = self::get_vendor_slug(
				[
					'post_id' => $post_id,
					'slug'    => $slug,
					'name'    => $name,
				]
			);
			$const_base = self::build_vendor_slug_constant_base( $vendor, $slug, $post_id );

			if ( 'uupd_inline' === $column ) {
                $code = "<?php \\UUPD\\UI\\License\\V1\\UUPD_License_UI::render_box_for( " . $const_base . "_SLUG, " . $const_base . "_VENDOR ); ?>";
				echo '<div class="uupd-copy-stack"><button type="button" class="button" data-uupd-label="Copy inline" data-uupd-copy="' . esc_attr( $code ) . '">Copy inline</button></div>';
				return;
			}

			$code = "define( '" . $const_base . "_VENDOR', '" . self::esc_php_string( $vendor ) . "' );\n"
				. "define( '" . $const_base . "_VERSION', '1.0.0' );\n"
				. "define( '" . $const_base . "_MAIN_FILE', __FILE__ );\n"
				. "define( '" . $const_base . "_ITEM_NUMBER', " . (int) $post_id . " );\n"
				. "define( '" . $const_base . "_FRIENDLY_NAME', '" . self::esc_php_string( $name ) . "' );\n"
				. "define( '" . $const_base . "_SLUG', '" . self::esc_php_string( $slug ) . "' );\n"
				. "define( '" . $const_base . "_SERVER_BASE', '" . self::esc_php_string( $server ) . "' );\n"
				. "define( '" . $const_base . "_MENU_SLUG', " . $const_base . "_VENDOR . '-' . " . $const_base . "_SLUG );\n\n"
				. "add_action( 'plugins_loaded', function() {\n"
				. "    require_once __DIR__ . '/inc/updater.php';\n"
				. "    require_once __DIR__ . '/inc/class-uupd-license-ui.php';\n\n"
				. "    \\UUPD\\UI\\License\\V1\\UUPD_License_UI::register([\n"
				. "        'vendor'         => " . $const_base . "_VENDOR,\n"
				. "        'slug'           => " . $const_base . "_SLUG,\n"
				. "        'item_id'        => " . $const_base . "_ITEM_NUMBER,\n"
				. "        'license_server' => " . $const_base . "_SERVER_BASE,\n"
				. "        'metadata_base'  => " . $const_base . "_SERVER_BASE,\n"
				. "        'plugin_name'    => " . $const_base . "_FRIENDLY_NAME,\n"
				. uupd_woo_gen_menu_lines('wooapi', $const_base . '_MENU_SLUG')
				. self::generated_title_lines($const_base)
				. "        'capability'     => 'manage_options',\n"
				. "    ]);\n\n"
				. "    \\UUPD\\V2\\UUPD_Updater_V2::register([\n"
				. "        'vendor'      => " . $const_base . "_VENDOR,\n"
				. "        'plugin_file' => plugin_basename( " . $const_base . "_MAIN_FILE ),\n"
				. "        'slug'        => " . $const_base . "_SLUG,\n"
				. "        'name'        => " . $const_base . "_FRIENDLY_NAME,\n"
				. "        'version'     => " . $const_base . "_VERSION,\n"
				. "        'server'      => '',\n"
		. "        'license_key_transport' => 'header',\n"
				. "    ]);\n"
				. "}, 1 );";

			echo '<div class="uupd-copy-stack"><button type="button" class="button" data-uupd-label="Copy bootloader" data-uupd-copy="' . esc_attr( $code ) . '">Copy bootloader</button></div>';
		}

        protected static function generated_title_lines(string $const_base): string {
            $settings = uupd_woo_gen_menu_settings('wooapi');
            if ($settings['placement'] === 'embedded') return '';
            $default = $const_base . "_FRIENDLY_NAME . ' License'";
            $page = trim((string)$settings['page_title']);
            $menu = trim((string)$settings['menu_title']);
            return "        'page_title'     => " . ($page !== '' ? var_export($page,true) : $default) . ",\n"
                . "        'menu_title'     => " . ($menu !== '' ? var_export($menu,true) : $default) . ",\n";
        }

        /** Reuse the same generation logic used by the legacy product-list buttons. */
        public static function generated_code(int $product_id, string $kind): string {
            ob_start();
            self::render_product_column($kind === 'inline' ? 'uupd_inline' : 'uupd_bootloader', $product_id);
            $html = ob_get_clean();
            if (preg_match('/data-uupd-copy="([^"]*)"/', $html, $matches)) {
                return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return '';
        }

		protected static function build_constant_base( string $slug, int $post_id ) : string {
			$base = strtoupper( preg_replace( '/[^A-Z0-9]+/', '_', sanitize_key( $slug ) ) );
			$base = trim( $base, '_' );
			if ( '' === $base ) {
				$base = 'PRODUCT';
			}
			return 'UUPD_WOOAPI_PRODUCT_' . $base . '_' . $post_id;
		}

		protected static function esc_php_string( string $value ) : string {
			return str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $value );
		}

		protected static function get_server_base() : string {
			$server = (string) apply_filters( 'uupd_wc_am_compat/server_base', home_url( '/' ) );
			return untrailingslashit( $server );
		}

		protected static function get_ignore_staging_activations_setting() : string {
			$value = get_option( self::OPTION_IGNORE_STAGING_ACTIVATIONS, 'no' );
			return is_string( $value ) ? strtolower( trim( $value ) ) : 'no';
		}

		protected static function should_ignore_staging_activations_for_domain( $domain ) : bool {
			if ( 'yes' !== self::get_ignore_staging_activations_setting() ) {
				return false;
			}

			return self::is_staging_domain( $domain );
		}

		protected static function is_staging_domain( $domain ) : bool {
			$site_class        = '\\Kestrel\\API_Manager\\Scoped\\Kestrel\\Fenix\\WordPress\\Site';
			$normalized_domain = self::normalize_domain_for_staging_check( $domain );

			if ( class_exists( $site_class ) && is_callable( [ $site_class, 'is_staging' ] ) ) {
				try {
					return (bool) call_user_func( [ $site_class, 'is_staging' ], $normalized_domain );
				} catch ( Throwable $exception ) {
					self::log(
						'staging_detection_failed',
						[
							'domain'            => (string) $domain,
							'normalized_domain' => (string) $normalized_domain,
							'message'           => $exception->getMessage(),
						]
					);
				}
			}

			$host = self::normalize_domain_host( $normalized_domain );
			if ( '' === $host ) {
				return false;
			}

			foreach ( [ '.dev', '.local', '.test' ] as $suffix ) {
				if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
					return true;
				}
			}

			foreach ( [ 'dev.', 'stage.', 'staging.', 'test.' ] as $prefix ) {
				if ( 0 === strpos( $host, $prefix ) ) {
					return true;
				}
			}

			return false;
		}

		protected static function get_instance_id( $slug, $domain, bool $ignore_staging_activations = false ) : string {
			$instance_seed = $ignore_staging_activations
				? 'staging-shared'
				: self::normalize_domain_host( $domain );

			$option_key = 'uupd_wc_am_instance_' . md5( sanitize_key( $slug ) . '|' . $instance_seed );
			$instance   = get_option( $option_key );
			if ( ! $instance ) {
				$instance = wp_generate_password( 12, false, false );
				update_option( $option_key, $instance, false );
			}
			return (string) $instance;
		}

		protected static function normalize_domain_for_staging_check( $domain ) : string {
			$domain = trim( (string) $domain );
			if ( '' === $domain ) {
				return home_url();
			}

			if ( preg_match( '#^https?://#i', $domain ) ) {
				return $domain;
			}

			return 'http://' . ltrim( $domain, '/' );
		}

		protected static function normalize_domain_host( $domain ) : string {
			$domain = trim( (string) $domain );
			if ( '' === $domain ) {
				$domain = home_url();
			}
			$host = wp_parse_url( $domain, PHP_URL_HOST );
			return $host ? strtolower( $host ) : preg_replace( '#^https?://#i', '', strtolower( $domain ) );
		}

		protected static function sanitize_license_key( $key ) : string {
			return preg_replace( '/[^A-Za-z0-9\-_.]/', '', (string) $key );
		}

		protected static function pick( array $source, array $keys ) {
			foreach ( $keys as $key ) {
				if ( isset( $source[ $key ] ) && '' !== (string) $source[ $key ] ) {
					return $source[ $key ];
				}
				if ( isset( $source['data'][ $key ] ) && '' !== (string) $source['data'][ $key ] ) {
					return $source['data'][ $key ];
				}
			}
			return '';
		}

		protected static function log( string $message, array $context = [] ) : void {
			$enabled = (bool) apply_filters( 'uupd_wc_am_compat/debug', false );

			if ( ! $enabled ) {
				return;
			}

			$line = '[UUPD WC AM Compat] ' . $message;

			if ( ! empty( $context ) ) {
				$line .= ' ' . wp_json_encode( $context );
			}

			error_log( $line );
		}
	}

	UUPD_WC_AM_Compat_Layer::init();
}
