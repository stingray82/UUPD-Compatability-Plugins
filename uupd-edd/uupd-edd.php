<?php
/**
 * Plugin Name: UUPD > EPD
 * Description: Adds UUPD-compatible license and metadata endpoints on top of Easy Digital Downloads Software Licensing
 * Version: 1.0
 * Author:  Reallyusefulplugins.com
 * Author URI: https://reallyusefulplugins.com
 * Requires PHP: 7.4
 * Website: https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'EDD_UUPD_Compatibility_Layer' ) ) {

	final class EDD_UUPD_Compatibility_Layer {
		const VERSION = '1.0';
		const SLUG_META_KEY = '_uupd_plugin_slug';
		const OPTION_AUTHOR = 'edd_uupd_global_author';
		const OPTION_AUTHOR_URL = 'edd_uupd_global_author_url';
		const OPTION_VENDOR = 'edd_uupd_global_vendor';
		protected static $pending_post_save = [];

		public static function init() {
			add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
			add_action( 'init', [ __CLASS__, 'maybe_handle_legacy_metadata' ], 0 );

			add_filter( 'manage_edit-download_columns', [ __CLASS__, 'add_download_columns' ], 20 );
			add_action( 'manage_download_posts_custom_column', [ __CLASS__, 'render_download_columns' ], 10, 2 );
			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );

			add_action( 'edd_meta_box_fields', [ __CLASS__, 'render_download_settings_section' ] );
			add_action( 'save_post_download', [ __CLASS__, 'save_download_meta_box' ], 999 );
			add_action( 'shutdown', [ __CLASS__, 'apply_pending_post_save' ], 999 );

			// Keep package delivery inside native EDD Software Licensing.
			add_filter( 'edd_sl_package_download_computed_hash', [ __CLASS__, 'filter_package_download_computed_hash' ], 10, 6 );
			add_filter( 'edd_sl_download_package_url', [ __CLASS__, 'filter_download_package_url' ], 10, 3 );
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

		public static function render_download_settings_section( $post_id = 0 ) {
			$post = get_post( $post_id ? $post_id : get_the_ID() );
			if ( ! $post || 'download' !== $post->post_type ) {
				return;
			}

			echo '<div class="edd-admin-box-inside" style="margin-top:20px; padding-top:12px; border-top:1px solid #ddd;">';
			echo '<h2 style="margin:0 0 12px;">UUPD Compatibility</h2>';
			self::render_meta_box( $post );
			echo '</div>';
		}

		protected static function get_vendor_slug() {
			$value = get_option( self::OPTION_VENDOR, '' );
			return is_string( $value ) ? sanitize_key( $value ) : '';
		}

		public static function maybe_handle_legacy_metadata() {
			if ( empty( $_GET['action'] ) || strtolower( (string) $_GET['action'] ) !== 'get_metadata' ) {
				return;
			}

			$slug   = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
			$key    = isset( $_GET['key'] ) ? self::sanitize_license_key( wp_unslash( $_GET['key'] ) ) : '';
			$domain = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '';
			$allow_prerelease = ! empty( $_GET['allow_prerelease'] );
			$release_channel  = isset( $_GET['release_channel'] )
			    ? sanitize_key( wp_unslash( $_GET['release_channel'] ) )
			    : '';

			list( $data, $status ) = self::build_uupd_metadata( $slug, $key, $domain, $allow_prerelease, $release_channel );

			status_header( (int) $status );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			echo wp_json_encode( $data );
			exit;
		}

		public static function handle_license_request( WP_REST_Request $request ) {
			$action  = strtolower( (string) $request['action'] );
			$payload = (array) $request->get_json_params();

			$license_key = isset( $payload['license_key'] ) ? self::sanitize_license_key( $payload['license_key'] ) : '';
			$slug        = isset( $payload['slug'] ) ? sanitize_key( (string) $payload['slug'] ) : '';
			$domain      = isset( $payload['domain'] ) ? sanitize_text_field( (string) $payload['domain'] ) : '';

			if ( ! self::check_rate_limit( 'api', 120, 5 * MINUTE_IN_SECONDS ) ) {
				return new WP_REST_Response(
					[ 'success' => false, 'message' => 'Too many requests.' ],
					429
				);
			}

			if ( '' === $license_key || '' === $slug ) {
				return new WP_REST_Response(
					[
						'success' => false,
						'message' => 'license_key and slug are required.',
						'status'  => 'inactive',
					],
					400
				);
			}

			$download_id = self::resolve_download_id_from_slug( $slug );
			if ( ! $download_id ) {
				return new WP_REST_Response(
					[
						'success' => false,
						'message' => 'Could not resolve a Download for the supplied slug.',
						'status'  => 'inactive',
					],
					404
				);
			}

			$edd_action = [
				'activate'   => 'activate_license',
				'deactivate' => 'deactivate_license',
				'check'      => 'check_license',
			][ $action ] ?? 'check_license';

			$edd = self::edd_api_request(
				$edd_action,
				[
					'item_id' => $download_id,
					'license' => $license_key,
					'url'     => self::normalize_site_value( $domain ),
				]
			);

			$body   = $edd['body'];
			$status = self::map_edd_license_status( $body, $action );
			$http   = $edd['code'] > 0 ? $edd['code'] : ( ! empty( $body['success'] ) ? 200 : 400 );
			$msg    = self::extract_edd_message( $body, $action );

			$response = [
				'success'          => ( 'active' === $status ),
				'message'          => $msg,
				'status'           => $status,
				'license_status'   => $status,
				'license'          => isset( $body['license'] ) ? (string) $body['license'] : '',
				'error'            => isset( $body['error'] ) ? (string) $body['error'] : '',
				'item_id'          => $download_id,
				'item_name'        => get_the_title( $download_id ),
				'site_count'       => isset( $body['site_count'] ) ? (int) $body['site_count'] : 0,
				'license_limit'    => isset( $body['license_limit'] ) ? $body['license_limit'] : '',
				'activation_limit' => isset( $body['license_limit'] ) ? $body['license_limit'] : '',
				'activations'      => isset( $body['site_count'] ) ? (int) $body['site_count'] : 0,
				'activations_left' => isset( $body['activations_left'] ) ? $body['activations_left'] : '',
				'expiry'           => isset( $body['expires'] ) ? (string) $body['expires'] : '',
				'date_expires'     => isset( $body['expires'] ) ? (string) $body['expires'] : '',
				'payment_id'       => isset( $body['payment_id'] ) ? $body['payment_id'] : '',
				'customer_name'    => isset( $body['customer_name'] ) ? $body['customer_name'] : '',
				'customer_email'   => isset( $body['customer_email'] ) ? $body['customer_email'] : '',
				'price_id'         => isset( $body['price_id'] ) ? $body['price_id'] : '',
				'checksum'         => isset( $body['checksum'] ) ? $body['checksum'] : '',
				'domain_active'    => ( 'active' === $status ),
				'raw_response'     => $body,
			];

			if ( 'deactivate' === $action && empty( $body['success'] ) && empty( $body['error'] ) ) {
				$response['success']        = true;
				$response['status']         = 'inactive';
				$response['license_status'] = 'inactive';
				$response['message']        = 'Deactivated.';
				$http                       = 200;
			}

			return new WP_REST_Response( $response, $http );
		}

		public static function handle_metadata_request( WP_REST_Request $request ) {
			$action = (string) $request->get_param( 'action' );
			if ( '' !== $action && 'get_metadata' !== strtolower( $action ) ) {
				return new WP_REST_Response(
					[ 'success' => false, 'message' => 'Invalid action.' ],
					400
				);
			}

			if ( ! self::check_rate_limit( 'api', 120, 5 * MINUTE_IN_SECONDS ) ) {
				return new WP_REST_Response(
					[ 'success' => false, 'message' => 'Too many requests.' ],
					429
				);
			}


			$slug   = sanitize_key( (string) $request->get_param( 'slug' ) );
			$key    = self::sanitize_license_key( (string) $request->get_param( 'key' ) );
			$domain = sanitize_text_field( (string) $request->get_param( 'domain' ) );
			$allow_prerelease = (bool) $request->get_param( 'allow_prerelease' );
			$release_channel  = sanitize_key( (string) $request->get_param( 'release_channel' ) );

			list( $data, $status ) = self::build_uupd_metadata( $slug, $key, $domain, $allow_prerelease, $release_channel );

			return new WP_REST_Response( $data, (int) $status );
		}

		protected static function build_uupd_metadata( $slug, $license_key, $domain, $allow_prerelease = false, $release_channel = '' ) {
			if ( '' === $slug ) {
				return [ [ 'success' => false, 'message' => 'Missing slug.' ], 400 ];
			}

			$download_id = self::resolve_download_id_from_slug( $slug );
			if ( ! $download_id ) {
				return [ [ 'success' => false, 'message' => 'Could not resolve a Download for the supplied slug.' ], 404 ];
			}


			$site_url = self::normalize_site_value( $domain );

			$edd = self::edd_api_request(
				'get_version',
				[
					'item_id' => $download_id,
					'license' => $license_key,
					'url'     => $site_url,
				]
			);

			$body = $edd['body'];
			$code = $edd['code'];

			if ( ! is_array( $body ) || empty( $body ) ) {
				return [ [ 'success' => false, 'message' => 'Unexpected response from EDD.' ], 502 ];
			}

			if ( ! empty( $body['error'] ) && empty( $body['new_version'] ) && empty( $body['stable_version'] ) ) {
				$message = ! empty( $body['msg'] ) ? (string) $body['msg'] : (string) $body['error'];
				return [ [ 'success' => false, 'message' => $message, 'raw_response' => $body ], $code ?: 403 ];
			}

			$required_versions = self::get_required_versions( $download_id );
			$sections          = self::maybe_unserialize_to_array( $body['sections'] ?? [] );
			$changelog         = self::get_download_changelog( $download_id );
			if ( '' !== trim( $changelog ) ) {
				$sections['changelog'] = $changelog;
			}
			$banners           = self::collect_download_banners( $download_id, $body['banners'] ?? [] );
			$icons             = self::collect_download_icons( $download_id );
			$release           = self::resolve_release_for_channel( $download_id, $allow_prerelease, $release_channel );
			$version           = $release['version'];
			$resolved_channel  = $release['channel'];
			$package_mode      = self::get_package_resolution_mode( $download_id );
			$price_id          = self::resolve_price_id_for_license( $download_id, $license_key, $site_url );
			$native_package    = (string) ( $body['package'] ?? $body['download_link'] ?? '' );
			$native_download   = (string) ( $body['download_link'] ?? $body['package'] ?? '' );

			if ( 'stable' !== $resolved_channel && '' !== $license_key ) {
			    $package_url = self::build_package_url_for_file_key(
			        $download_id,
			        $license_key,
			        $site_url,
			        1,
			        true
			    );
			} elseif ( ! empty( $release['file_key'] ) && '' !== $license_key ) {
			    $package_url = self::build_package_url_for_file_key(
			        $download_id,
			        $license_key,
			        $site_url,
			        $release['file_key'],
			        false
			    );
			} elseif ( ! empty( $release['file_url'] ) ) {
			    $package_url = $release['file_url'];
			} elseif ( 'price_id' === $package_mode && '' !== $license_key ) {
			    $package_url = self::build_price_aware_package_url( $download_id, $license_key, $site_url );
			    if ( '' === $package_url ) {
			        $package_url = $native_package;
			    }
			} else {
			    $package_url = $native_package;
			}



			$response = [
				'slug'             => $slug,
				'name'             => $body['name'] ?? get_the_title( $download_id ),
				'version'          => $version,
				'new_version'      => $version,
				'stable_version' => self::get_download_version( $download_id ),
				'homepage'         => $body['homepage'] ?? get_permalink( $download_id ),
				'url'              => $body['url'] ?? get_permalink( $download_id ),
				'author'           => self::get_author_name(),
				'author_homepage'  => self::get_author_url(),
				'last_updated'     => $body['last_updated'] ?? get_the_modified_date( 'Y-m-d H:i:s', $download_id ),
				'download_url'     => $package_url,
				'package'          => $package_url,
				'download_link'    => $package_url,
				'sections'         => is_array( $sections ) ? $sections : [],
				'banners'          => $banners,
				'icons'            => $icons,
				'requires'         => $required_versions['wp'],
				'requires_php'     => $required_versions['php'],
				'tested'           => get_post_meta( $download_id, '_uupd_tested_up_to', true ),
				'upgrade_notice'   => get_post_meta( $download_id, '_edd_sl_upgrade_notice', true ),
				'staged_rollouts'  => self::get_staged_rollout_meta( $download_id ),
				'license_required' => true,
				'license_status'   => ( ! empty( $native_package ) || ! empty( $native_download ) ) ? 'active' : 'missing',
				'price_id'         => $price_id,
				'package_mode'     => $package_mode,
				'raw_response'     => $body,
				'release_channel'  => $resolved_channel,
				'allow_prerelease' => (bool) $allow_prerelease,
			];

			if ( empty( $package_url ) ) {
				$response['license_status'] = 'missing';
				$response['package_error']  = 'No package URL could be resolved.';
			}

			return [ $response, 200 ];
		}

		protected static function edd_api_request( $action, array $params ) {
			$endpoint = apply_filters( 'edd_uupd_compat/api_endpoint', home_url( '/' ), $action, $params );
			$payload  = array_merge(
				[
					'edd_action' => $action,
				],
				$params
			);

			$cacheable_actions = [ 'check_license' ];

			$cache_key = '';
			if ( in_array( $action, $cacheable_actions, true ) ) {
				$cache_key = 'uupd_edd_api_' . md5( $action . '|' . wp_json_encode( $params ) );
				$cached    = get_transient( $cache_key );

				if ( is_array( $cached ) ) {
					return $cached;
				}
			}

			$response = wp_remote_post(
				$endpoint,
				[
					'timeout'   => 20,
					'sslverify' => apply_filters( 'edd_uupd_compat/sslverify', true, $action, $params ),
					'body'      => $payload,
					'headers'   => [ 'Accept' => 'application/json' ],
				]
			);

			if ( is_wp_error( $response ) ) {
				return [
					'code' => 0,
					'body' => [
						'success' => false,
						'error'   => 'http_error',
						'msg'     => $response->get_error_message(),
					],
				];
			}

			$raw  = wp_remote_retrieve_body( $response );
			$body = json_decode( $raw, true );

			if ( ! is_array( $body ) ) {
				return [
					'code' => (int) wp_remote_retrieve_response_code( $response ),
					'body' => [
						'success' => false,
						'error'   => 'invalid_json',
						'msg'     => 'EDD returned invalid JSON.',
						'raw'     => $raw,
					],
				];
			}

			$result = [
				'code' => (int) wp_remote_retrieve_response_code( $response ),
				'body' => $body,
			];

			// Cache only clean responses. Do not cache EDD errors.
			if ( $cache_key && empty( $body['error'] ) ) {
				set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
			}

			return $result;
		}

		protected static function resolve_download_id_from_slug( $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				return 0;
			}

			$ids = get_posts(
				[
					'post_type'      => 'download',
					'post_status'    => [ 'publish', 'private', 'draft' ],
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => [
						'relation' => 'OR',
						[
							'key'   => self::SLUG_META_KEY,
							'value' => $slug,
						],
						[
							'key'   => '_edd_sl_item_shortname',
							'value' => $slug,
						],
					],
				]
			);

			if ( ! empty( $ids[0] ) ) {
				return (int) $ids[0];
			}

			$ids = get_posts(
				[
					'post_type'      => 'download',
					'post_status'    => [ 'publish', 'private', 'draft' ],
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'name'           => $slug,
				]
			);

			return ! empty( $ids[0] ) ? (int) $ids[0] : 0;
		}

		protected static function normalize_site_value( $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return home_url();
			}

			if ( false === strpos( $value, '://' ) ) {
				$value = 'https://' . $value;
			}

			return $value;
		}

		protected static function get_client_ip() {
			$keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];

			foreach ( $keys as $key ) {
				if ( empty( $_SERVER[ $key ] ) ) {
					continue;
				}

				$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				$ip    = trim( explode( ',', $value )[0] );

				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}

			return 'unknown';
		}

		protected static function check_rate_limit( $scope, $limit, $ttl ) {
			$ip        = self::get_client_ip();
			$cache_key = 'uupd_rl_' . md5( $scope . '|' . $ip );
			$count     = (int) get_transient( $cache_key );

			if ( $count >= $limit ) {
				return false;
			}

			set_transient( $cache_key, $count + 1, $ttl );

			return true;
		}

		protected static function sanitize_license_key( $value ) {
			$value = preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $value );
			return substr( (string) $value, 0, 256 );
		}

		protected static function maybe_unserialize_to_array( $value ) {
			if ( is_array( $value ) ) {
				return $value;
			}
			if ( is_string( $value ) ) {
				$unserialized = maybe_unserialize( $value );
				if ( is_array( $unserialized ) ) {
					return $unserialized;
				}
			}
			return [];
		}

		protected static function get_required_versions( $download_id ) {
			$value = get_post_meta( $download_id, '_edd_sl_required_versions', true );
			$data  = self::maybe_unserialize_to_array( $value );

			return [
				'php' => isset( $data['php'] ) ? (string) $data['php'] : '',
				'wp'  => isset( $data['wp'] ) ? (string) $data['wp'] : '',
			];
		}

		protected static function get_download_changelog( $download_id ) {
			$value = get_post_meta( $download_id, '_edd_sl_changelog', true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return $value;
			}

			$value = get_post_meta( $download_id, '_uupd_changelog', true );
			return is_string( $value ) ? $value : '';
		}

		protected static function get_staged_rollout_meta( $download_id ) {
			return [
				'enabled'         => ! empty( get_post_meta( $download_id, 'edd_sr_enabled', true ) ),
				'batch_enabled'   => ! empty( get_post_meta( $download_id, 'edd_sr_batch_enabled', true ) ),
				'batch_max'       => get_post_meta( $download_id, 'edd_sr_batch_max', true ),
				'version_enabled' => ! empty( get_post_meta( $download_id, 'edd_sr_version_enabled', true ) ),
				'version'         => get_post_meta( $download_id, 'edd_sr_version', true ),
				'version_limit'   => get_post_meta( $download_id, 'edd_sr_version_limit', true ),
			];
		}

		protected static function get_package_resolution_mode( $download_id ) {
			$mode = get_post_meta( $download_id, '_uupd_package_mode', true );
			$mode = is_string( $mode ) ? strtolower( trim( $mode ) ) : '';

			if ( ! in_array( $mode, [ 'native', 'price_id' ], true ) ) {
				$mode = 'native';
			}

			return $mode;
		}

		protected static function get_license_object( $license_key ) {
			if ( '' === $license_key || ! function_exists( 'edd_software_licensing' ) ) {
				return null;
			}

			$sl = edd_software_licensing();
			if ( ! $sl || ! method_exists( $sl, 'get_license' ) ) {
				return null;
			}

			$license = $sl->get_license( $license_key, true );

			return is_object( $license ) ? $license : null;
		}

		protected static function resolve_price_id_for_license( $download_id, $license_key, $domain ) {
			$license = self::get_license_object( $license_key );
			if ( $license && isset( $license->price_id ) && '' !== (string) $license->price_id ) {
				return (string) $license->price_id;
			}

			$body = self::get_license_check_data( $download_id, $license_key, $domain );
			return isset( $body['price_id'] ) ? (string) $body['price_id'] : '';
		}

		protected static function get_license_check_data( $download_id, $license_key, $domain ) {
			if ( '' === $license_key ) {
				return [];
			}

			$license = self::edd_api_request(
				'check_license',
				[
					'item_id' => $download_id,
					'license' => $license_key,
					'url'     => self::normalize_site_value( $domain ),
				]
			);

			return is_array( $license['body'] ) ? $license['body'] : [];
		}

		protected static function get_download_files( $download_id ) {
			if ( function_exists( 'edd_get_download_files' ) ) {
				$files = edd_get_download_files( $download_id );
				if ( is_array( $files ) ) {
					return $files;
				}
			}

			$value = get_post_meta( $download_id, 'edd_download_files', true );
			$files = self::maybe_unserialize_to_array( $value );

			return is_array( $files ) ? $files : [];
		}

		protected static function normalize_download_file_key( $key, array $entry ) {
			if ( isset( $entry['index'] ) && '' !== (string) $entry['index'] ) {
				return (string) $entry['index'];
			}

			return (string) $key;
		}

		protected static function is_shared_download_file_condition( $condition ) {
			$condition = is_scalar( $condition ) ? strtolower( trim( (string) $condition ) ) : '';
			return in_array( $condition, [ '', '0', 'all', 'false', 'none' ], true );
		}

		protected static function get_download_file_url_from_entry( array $entry ) {
			if ( ! empty( $entry['file'] ) && is_string( $entry['file'] ) ) {
				return (string) $entry['file'];
			}

			if ( ! empty( $entry['attachment_id'] ) ) {
				$attachment_url = wp_get_attachment_url( (int) $entry['attachment_id'] );
				if ( $attachment_url ) {
					return $attachment_url;
				}
			}

			return '';
		}

		protected static function resolve_conditioned_download_file_key( $download_id, $price_id ) {
			$files = self::get_download_files( $download_id );

			if ( empty( $files ) ) {
				return '';
			}

			$price_id   = trim( (string) $price_id );
			$shared_key = '';
			$first_key  = '';

			foreach ( $files as $key => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$file_url = self::get_download_file_url_from_entry( $entry );
				if ( '' === $file_url ) {
					continue;
				}

				$normalized_key = self::normalize_download_file_key( $key, $entry );
				if ( '' === $first_key ) {
					$first_key = $normalized_key;
				}

				$condition = '';
				if ( function_exists( 'edd_get_file_price_condition' ) ) {
					$condition = (string) edd_get_file_price_condition( $download_id, $key );
				} elseif ( isset( $entry['condition'] ) ) {
					$condition = (string) $entry['condition'];
				}

				if ( '' !== $price_id && trim( $condition ) === $price_id ) {
					return $normalized_key;
				}

				if ( '' === $shared_key && self::is_shared_download_file_condition( $condition ) ) {
					$shared_key = $normalized_key;
				}
			}

			if ( '' !== $shared_key ) {
				return $shared_key;
			}

			return $first_key;
		}

		protected static function resolve_conditioned_download_file_url( $download_id, $price_id ) {
			$target_key = self::resolve_conditioned_download_file_key( $download_id, $price_id );
			if ( '' === $target_key ) {
				return '';
			}

			$files = self::get_download_files( $download_id );
			foreach ( $files as $key => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$normalized_key = self::normalize_download_file_key( $key, $entry );
				if ( (string) $normalized_key === (string) $target_key ) {
					return self::get_download_file_url_from_entry( $entry );
				}
			}

			return '';
		}

		protected static function get_package_download_name( $download_id ) {
			if ( class_exists( '\EDD\SoftwareLicensing\Downloads\LicensedProduct' ) ) {
				$product = new \EDD\SoftwareLicensing\Downloads\LicensedProduct( $download_id );
				if ( is_object( $product ) && method_exists( $product, 'get_name' ) ) {
					return (string) $product->get_name();
				}
			}

			if ( function_exists( 'edd_get_download_name' ) ) {
				$name = edd_get_download_name( $download_id );
				if ( is_string( $name ) && '' !== $name ) {
					return $name;
				}
			}

			return get_the_title( $download_id );
		}

		protected static function build_price_aware_package_url( $download_id, $license_key, $domain, $download_beta = false ) {
			$license_key = self::sanitize_license_key( $license_key );
			if ( $download_id <= 0 || '' === $license_key ) {
				return '';
			}

			$price_id = self::resolve_price_id_for_license( $download_id, $license_key, $domain );
			$file_key = self::resolve_conditioned_download_file_key( $download_id, $price_id );
			if ( '' === $file_key ) {
				return '';
			}

			$download_name = self::get_package_download_name( $download_id );
			$hours         = '+' . absint( edd_get_option( 'download_link_expiration', 24 ) ) . ' hours';
			$expires       = strtotime( $hours, current_time( 'timestamp' ) );
			$hash          = md5( $download_name . $file_key . $download_id . $license_key . (int) $expires );
			$token_domain  = str_replace( ':', '@', self::normalize_site_value( $domain ) );
			$token         = base64_encode( sprintf( '%s:%s:%d:%s:%s:%d', $expires, $license_key, (int) $download_id, $hash, $token_domain, (int) $download_beta ) );

			return trailingslashit( home_url() ) . 'edd-sl/package_download/' . $token;
		}

		protected static function build_package_url_for_file_key( $download_id, $license_key, $domain, $file_key, $download_beta = false ) {
		    $license_key = self::sanitize_license_key( $license_key );

		    if ( $download_id <= 0 || '' === $license_key || '' === $file_key ) {
		        return '';
		    }

		    $download_name = self::get_package_download_name( $download_id );
		    $hours         = '+' . absint( edd_get_option( 'download_link_expiration', 24 ) ) . ' hours';
		    $expires       = strtotime( $hours, current_time( 'timestamp' ) );
		    $hash          = md5( $download_name . $file_key . $download_id . $license_key . (int) $expires );
		    $token_domain  = str_replace( ':', '@', self::normalize_site_value( $domain ) );
		    $token         = base64_encode( sprintf( '%s:%s:%d:%s:%s:%d', $expires, $license_key, (int) $download_id, $hash, $token_domain, (int) $download_beta ) );

		    return trailingslashit( home_url() ) . 'edd-sl/package_download/' . $token;
		}


		public static function filter_package_download_computed_hash( $computed_hash, $download_name, $file_key, $download_id, $license_key, $expires ) {
			$download_id = absint( $download_id );
			if ( $download_id <= 0 || 'price_id' !== self::get_package_resolution_mode( $download_id ) ) {
				return $computed_hash;
			}

			$price_id = self::resolve_price_id_for_license( $download_id, (string) $license_key, '' );
			if ( '' === $price_id ) {
				return $computed_hash;
			}

			$matched_file_key = self::resolve_conditioned_download_file_key( $download_id, $price_id );
			if ( '' === $matched_file_key ) {
				return $computed_hash;
			}

			return md5( (string) $download_name . $matched_file_key . $download_id . (string) $license_key . (int) $expires );
		}

		public static function filter_download_package_url( $file_url, $download_id, $license_key ) {
			$download_id = absint( $download_id );
			if ( $download_id <= 0 || 'price_id' !== self::get_package_resolution_mode( $download_id ) ) {
				return $file_url;
			}

			$price_id = self::resolve_price_id_for_license( $download_id, (string) $license_key, '' );
			if ( '' === $price_id ) {
				return $file_url;
			}

			$matched_url = self::resolve_conditioned_download_file_url( $download_id, $price_id );
			return '' !== $matched_url ? $matched_url : $file_url;
		}

		protected static function get_author_name() {
			$value = get_option( self::OPTION_AUTHOR, '' );
			return ( is_string( $value ) && '' !== trim( $value ) ) ? trim( $value ) : get_bloginfo( 'name' );
		}

		protected static function get_author_url() {
			$value = get_option( self::OPTION_AUTHOR_URL, '' );
			return ( is_string( $value ) && '' !== trim( $value ) ) ? esc_url_raw( trim( $value ) ) : home_url( '/' );
		}

		protected static function collect_download_icons( $download_id ) {
			$icons = [];

			$icon_1x = get_post_meta( $download_id, '_uupd_icon_1x', true );
			$icon_2x = get_post_meta( $download_id, '_uupd_icon_2x', true );
			$thumb   = get_the_post_thumbnail_url( $download_id, 'full' );

			if ( $icon_1x ) {
				$icons['1x'] = $icon_1x;
			} elseif ( $thumb ) {
				$icons['1x'] = $thumb;
			}

			if ( $icon_2x ) {
				$icons['2x'] = $icon_2x;
			} elseif ( $thumb ) {
				$icons['2x'] = $thumb;
			}

			return $icons;
		}

		protected static function collect_download_banners( $download_id, $existing = [] ) {
			$banners = self::maybe_unserialize_to_array( $existing );

			$banner_low  = get_post_meta( $download_id, '_uupd_banner_low', true );
			$banner_high = get_post_meta( $download_id, '_uupd_banner_high', true );

			if ( $banner_low ) {
				$banners['low'] = $banner_low;
			}
			if ( $banner_high ) {
				$banners['high'] = $banner_high;
			}

			return is_array( $banners ) ? $banners : [];
		}

		protected static function map_edd_license_status( array $body, $action ) {
			$license = strtolower( (string) ( $body['license'] ?? '' ) );
			$error   = strtolower( (string) ( $body['error'] ?? '' ) );
			$success = ! empty( $body['success'] );

			if ( 'deactivate' === $action ) {
				return 'inactive';
			}

			if ( 'valid' === $license && $success ) {
				return 'active';
			}

			if ( in_array( $license, [ 'expired', 'disabled' ], true ) ) {
				return $license;
			}

			if ( in_array( $error, [ 'expired', 'disabled' ], true ) ) {
				return $error;
			}

			if ( in_array( $error, [ 'missing', 'invalid', 'site_inactive', 'no_activations_left', 'key_mismatch', 'item_name_mismatch', 'invalid_item_id', 'missing_url', 'license_not_activable' ], true ) ) {
				return 'inactive';
			}

			return $success ? 'active' : 'inactive';
		}

		protected static function extract_edd_message( array $body, $action ) {
			if ( ! empty( $body['msg'] ) ) {
				return (string) $body['msg'];
			}

			$error = isset( $body['error'] ) ? (string) $body['error'] : '';
			if ( '' !== $error ) {
				$map = [
					'missing'               => 'License does not exist.',
					'missing_url'           => 'Site URL is required.',
					'license_not_activable' => 'This license cannot be activated remotely.',
					'disabled'              => 'License has been disabled.',
					'no_activations_left'   => 'No activations left for this license.',
					'expired'               => 'License has expired.',
					'key_mismatch'          => 'License is not valid for this product.',
					'invalid_item_id'       => 'Invalid download ID.',
					'item_name_mismatch'    => 'License is not valid for this product.',
					'site_inactive'         => 'License is not active for this site.',
					'invalid'               => 'License key is invalid.',
				];
				if ( isset( $map[ $error ] ) ) {
					return $map[ $error ];
				}
			}

			if ( 'activate' === $action ) {
				return ! empty( $body['success'] ) ? 'Activated.' : 'Activation failed.';
			}
			if ( 'deactivate' === $action ) {
				return ! empty( $body['success'] ) ? 'Deactivated.' : 'Deactivation failed.';
			}

			return ! empty( $body['success'] ) ? 'License is active.' : 'License is inactive.';
		}

		public static function add_download_columns( $columns ) {
			$new = [];
			foreach ( $columns as $key => $label ) {
				$new[ $key ] = $label;
				if ( 'title' === $key ) {
					$new['uupd_bootloader_copy'] = 'Bootloader';
					$new['uupd_inline_copy']     = 'Inline display';
				}
			}

			if ( ! isset( $new['uupd_bootloader_copy'] ) ) {
				$new['uupd_bootloader_copy'] = 'Bootloader';
				$new['uupd_inline_copy']     = 'Inline display';
			}

			return $new;
		}

		public static function render_download_columns( $column, $post_id ) {
			if ( ! in_array( $column, [ 'uupd_bootloader_copy', 'uupd_inline_copy' ], true ) ) {
				return;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return;
			}

			$friendly = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
			$slug     = self::get_download_slug( $post_id, $post );
			$version  = self::get_download_version( $post_id );
			$vendor   = self::get_vendor_slug();

			if ( '' === $vendor ) {
				$vendor = 'yourvendor';
			}

			$prefix = self::build_bootloader_constant_prefix( $vendor, $slug, $post_id );
			$server = apply_filters( 'edd_uupd_compat/bootloader_server_base', home_url( '/' ), $post_id, $slug, $vendor );

			$esc = static function ( $value ) {
				return str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], (string) $value );
			};

			$c_vendor    = "UUPD_{$prefix}_VENDOR";
			$c_version   = "UUPD_{$prefix}_VERSION";
			$c_main_file = "UUPD_{$prefix}_MAIN_FILE";
			$c_item_no   = "UUPD_{$prefix}_ITEM_NUMBER";
			$c_name      = "UUPD_{$prefix}_FRIENDLY_NAME";
			$c_slug      = "UUPD_{$prefix}_SLUG";
			$c_server    = "UUPD_{$prefix}_SERVER_BASE";
			$c_menu      = "UUPD_{$prefix}_MENU_SLUG";

			$lines   = [];
			$lines[] = "define( '{$c_vendor}', '" . $esc( $vendor ) . "' );";
			$lines[] = "define( '{$c_version}', '" . $esc( $version ) . "' );";
			$lines[] = "define( '{$c_main_file}', __FILE__ );";
			$lines[] = "define( '{$c_item_no}', " . (int) $post_id . ' );';
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
			$lines[] = "        'item_id'        => {$c_item_no},";
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
			$lines[] = "        'plugin_file' => plugin_basename( {$c_main_file} ),";
			$lines[] = "        'slug'        => {$c_slug},";
			$lines[] = "        'name'        => {$c_name},";
			$lines[] = "        'version'     => {$c_version},";
			$lines[] = "        'server'      => '',";
			$lines[] = "    ]);";
			$lines[] = "}, 1 );";

			$bootloader = implode( "\n", $lines );
			$inline     = "<?php \$inst = \\UUPD\\UI\\License\\V1\\UUPD_License_UI::get_instance( {$c_vendor}, {$c_slug} ); if ( \$inst ) { \$inst->render_page(); } ?>";

			if ( 'uupd_bootloader_copy' === $column ) {
				printf(
					'<button type="button" class="button button-small edd-uupd-copy-btn" data-uupd-copy="%s">Copy bootloader</button>',
					esc_attr( $bootloader )
				);
			} else {
				printf(
					'<button type="button" class="button button-small edd-uupd-copy-btn" data-uupd-copy="%s">Copy inline</button>',
					esc_attr( $inline )
				);
			}
		}

		protected static function get_download_slug( $post_id, $post ) {
			$slug = get_post_meta( $post_id, self::SLUG_META_KEY, true );
			if ( is_string( $slug ) && '' !== $slug ) {
				return sanitize_key( $slug );
			}

			$slug = get_post_meta( $post_id, '_edd_sl_item_shortname', true );
			if ( is_string( $slug ) && '' !== $slug ) {
				return sanitize_key( $slug );
			}

			return sanitize_key( $post->post_name );
		}

		protected static function get_download_version( $post_id ) {
			$value = get_post_meta( $post_id, '_edd_sl_version', true );
			return ( is_string( $value ) && '' !== trim( $value ) ) ? trim( $value ) : '1.0.0';
		}


		protected static function sanitize_release_channel( $channel, $allow_prerelease = false ) {
		    $channel = strtolower( sanitize_key( (string) $channel ) );

		    if ( in_array( $channel, [ 'stable', 'dev', 'alpha', 'beta', 'rc', 'prerelease' ], true ) ) {
		        return $channel;
		    }

		    return $allow_prerelease ? 'prerelease' : 'stable';
		}

		protected static function get_allowed_release_channels( $channel ) {
		    $channel = self::sanitize_release_channel( $channel );

		    $map = [
		        'stable'     => [ 'stable' ],
		        'dev'        => [ 'stable', 'dev' ],
		        'alpha'      => [ 'stable', 'dev', 'alpha' ],
		        'beta'       => [ 'stable', 'dev', 'alpha', 'beta' ],
		        'rc'         => [ 'stable', 'dev', 'alpha', 'beta', 'rc' ],
		        'prerelease' => [ 'stable', 'dev', 'alpha', 'beta', 'rc' ],
		    ];

		    return $map[ $channel ] ?? [ 'stable' ];
		}

		protected static function normalize_version_for_compare( $version ) {
		    $version = trim( (string) $version );
		    $version = preg_replace( '/\+.*$/', '', $version );
		    $version = ltrim( $version, 'vV' );
		    $version = str_replace( '_', '-', $version );

		    if ( preg_match( '/^\d+\.\d+$/', $version ) ) {
		        $version .= '.0';
		    } elseif ( preg_match( '/^\d+$/', $version ) ) {
		        $version .= '.0.0';
		    }

		    if ( preg_match( '/^(\d+\.\d+\.\d+)[\.\-]?((?:alpha|a|beta|b|rc|dev|pre|preview))(?:(?:[\.\-]?)(\d+))?$/i', $version, $m ) ) {
		        $core = $m[1];
		        $tag  = strtolower( $m[2] );
		        $num  = isset( $m[3] ) && '' !== $m[3] ? $m[3] : '0';

		        if ( 'a' === $tag ) {
		            $tag = 'alpha';
		        } elseif ( 'b' === $tag ) {
		            $tag = 'beta';
		        } elseif ( in_array( $tag, [ 'pre', 'preview' ], true ) ) {
		            $tag = 'beta';
		        }

		        $version = "{$core}-{$tag}.{$num}";
		    }

		    return preg_replace( '/^(\d+\.\d+\.\d+)-(alpha|beta|rc|dev)$/i', '$1-$2.0', $version );
		}

		protected static function resolve_release_for_channel( $download_id, $allow_prerelease = false, $release_channel = '' ) {
		    $release_channel = self::sanitize_release_channel( $release_channel, $allow_prerelease );
		    $allowed         = self::get_allowed_release_channels( $release_channel );

		    $candidates = [];

		    foreach ( $allowed as $channel ) {
		        if ( 'stable' === $channel ) {
		            $version  = get_post_meta( $download_id, '_edd_sl_version', true );
		            $file_key = get_post_meta( $download_id, '_edd_sl_upgrade_file_key', true );
		            $file_url = '';

		            if ( $file_key ) {
		                $files = self::get_download_files( $download_id );
		                foreach ( $files as $key => $entry ) {
		                    if ( ! is_array( $entry ) ) {
		                        continue;
		                    }

		                    $normal_key = self::normalize_download_file_key( $key, $entry );

		                    if ( (string) $normal_key === (string) $file_key ) {
		                        $file_url = self::get_download_file_url_from_entry( $entry );
		                        break;
		                    }
		                }
		            }
		       } else {
				    $version  = get_post_meta( $download_id, '_edd_sl_beta_version', true );
				    $file_key = 1;
				    $file_url = '';

				    $beta_files = get_post_meta( $download_id, '_edd_sl_beta_files', true );
				    $beta_files = self::maybe_unserialize_to_array( $beta_files );

				    if ( ! empty( $beta_files[1]['file'] ) ) {
				        $file_url = (string) $beta_files[1]['file'];
				    }
				}

		        if ( ! is_string( $version ) || '' === trim( $version ) ) {
		            continue;
		        }

		        $candidates[] = [
		            'channel'            => $channel,
		            'version'            => trim( $version ),
		            'version_normalized' => self::normalize_version_for_compare( $version ),
		            'file_key'           => is_string( $file_key ) ? $file_key : '',
		            'file_url'           => is_string( $file_url ) ? $file_url : '',
		        ];
		    }

		    if ( empty( $candidates ) ) {
		        return [
		            'channel'  => 'stable',
		            'version'  => self::get_download_version( $download_id ),
		            'file_key' => '',
		            'file_url' => '',
		        ];
		    }

		    usort(
		        $candidates,
		        function ( $a, $b ) {
		            return version_compare( $b['version_normalized'], $a['version_normalized'] );
		        }
		    );

		    return $candidates[0];
		}




		protected static function build_bootloader_constant_prefix( $vendor, $slug, $post_id ) {
			$prefix = strtoupper( str_replace( '-', '_', (string) $vendor . '_' . (string) $slug ) );
			$prefix = preg_replace( '/[^A-Z0-9_]/', '_', $prefix );
			$prefix = preg_replace( '/_+/', '_', $prefix );
			$prefix = trim( $prefix, '_' );

			return $prefix ?: 'DOWNLOAD_' . (int) $post_id;
		}

		public static function enqueue_admin_assets( $hook ) {
			if ( 'edit.php' !== $hook && 'post.php' !== $hook && 'post-new.php' !== $hook ) {
				return;
			}

			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || 'download' !== $screen->post_type ) {
				return;
			}

			wp_enqueue_media();

			wp_register_script( 'edd-uupd-admin', '', [ 'jquery' ], self::VERSION, true );
			wp_enqueue_script( 'edd-uupd-admin' );
			wp_add_inline_script( 'edd-uupd-admin', self::admin_js() );
		}

		protected static function admin_js() {
			return <<<'JS'
(function($){
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

  $(document).on('click', '.edd-uupd-copy-btn', function(e){
    e.preventDefault();
    var btn = this;
    var payload = btn.getAttribute('data-uupd-copy') || '';
    copyText(payload).then(function(){
      var old = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(function(){ btn.textContent = old; }, 900);
    }).catch(function(){
      alert('Copy failed. Please copy manually.');
    });
  });

  $(document).on('click', '.edd-uupd-media-select', function(e){
    e.preventDefault();
    var button = $(this);
    var target = $('#' + button.data('target'));
    var preview = $('#' + button.data('preview'));
    var frame = wp.media({
      title: button.data('title') || 'Select image',
      button: { text: button.data('button') || 'Use image' },
      multiple: false,
      library: { type: 'image' }
    });

    frame.on('select', function(){
      var attachment = frame.state().get('selection').first().toJSON();
      target.val(attachment.url).trigger('change');
      if(preview.length){
        preview.html('<img src="' + attachment.url + '" style="max-width:100%;height:auto;display:block;margin-top:8px;" />');
      }
    });

    frame.open();
  });

  $(document).on('click', '.edd-uupd-media-clear', function(e){
    e.preventDefault();
    var button = $(this);
    var target = $('#' + button.data('target'));
    var preview = $('#' + button.data('preview'));
    target.val('');
    if(preview.length){
      preview.empty();
    }
  });
})(jQuery);
JS;
		}

		protected static function render_media_field( $args ) {
			$field_id    = $args['field_id'];
			$field_name  = $args['field_name'];
			$label       = $args['label'];
			$value       = isset( $args['value'] ) ? (string) $args['value'] : '';
			$preview_id  = $field_id . '_preview';
			$description = isset( $args['description'] ) ? $args['description'] : '';
			?>
			<p>
				<label for="<?php echo esc_attr( $field_id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label>
				<input type="url" class="widefat" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<span style="display:flex; gap:8px; margin-top:8px;">
					<button type="button" class="button button-secondary edd-uupd-media-select" data-target="<?php echo esc_attr( $field_id ); ?>" data-preview="<?php echo esc_attr( $preview_id ); ?>" data-title="Select <?php echo esc_attr( $label ); ?>" data-button="Use this image">Select from media library</button>
					<button type="button" class="button button-link-delete edd-uupd-media-clear" data-target="<?php echo esc_attr( $field_id ); ?>" data-preview="<?php echo esc_attr( $preview_id ); ?>">Clear</button>
				</span>
				<?php if ( $description ) : ?>
					<small><?php echo esc_html( $description ); ?></small>
				<?php endif; ?>
				<span id="<?php echo esc_attr( $preview_id ); ?>" style="display:block; margin-top:8px;"><?php if ( $value ) : ?><img src="<?php echo esc_url( $value ); ?>" style="max-width:100%; height:auto; display:block;" /><?php endif; ?></span>
			</p>
			<?php
		}

		public static function render_meta_box( $post ) {
			wp_nonce_field( 'edd_uupd_compat_meta', 'edd_uupd_compat_meta_nonce' );

			$slug              = get_post_meta( $post->ID, self::SLUG_META_KEY, true );
			$version           = self::get_download_version( $post->ID );
			$tested            = get_post_meta( $post->ID, '_uupd_tested_up_to', true );
			$upgrade_notice    = get_post_meta( $post->ID, '_edd_sl_upgrade_notice', true );
			$changelog         = self::get_download_changelog( $post->ID );
			$staged_rollouts   = self::get_staged_rollout_meta( $post->ID );
			$icon_1x           = get_post_meta( $post->ID, '_uupd_icon_1x', true );
			$icon_2x           = get_post_meta( $post->ID, '_uupd_icon_2x', true );
			$banner_low        = get_post_meta( $post->ID, '_uupd_banner_low', true );
			$banner_high       = get_post_meta( $post->ID, '_uupd_banner_high', true );
			$package_mode      = self::get_package_resolution_mode( $post->ID );
			$global_author     = get_option( self::OPTION_AUTHOR, '' );
			$global_author_url = get_option( self::OPTION_AUTHOR_URL, '' );
			$global_vendor = get_option( self::OPTION_VENDOR, '' );
			?>
			<p>
				<label for="edd_uupd_slug"><strong>Plugin slug</strong></label>
				<input type="text" class="widefat" id="edd_uupd_slug" name="edd_uupd_slug" value="<?php echo esc_attr( $slug ); ?>" placeholder="my-plugin-slug" />
				<small>Used by the UUPD bridge to resolve a Download from a plugin slug. Falls back to the EDD shortname or post slug.</small>
			</p>
			<p>
				<label for="edd_uupd_package_mode"><strong>Package resolution</strong></label>
				<select class="widefat" id="edd_uupd_package_mode" name="edd_uupd_package_mode">
					<option value="native" <?php selected( $package_mode, 'native' ); ?>>EDD native package URL</option>
					<option value="price_id" <?php selected( $package_mode, 'price_id' ); ?>>Price-aware native package URL</option>
				</select>
				<small>Price-aware mode keeps downloads inside EDD Software Licensing but uses the license price_id to select the matching conditioned file.</small>
			</p>
			<p>
				<label for="edd_uupd_version"><strong>Version</strong></label>
				<input type="hidden" name="edd_uupd_original_version" value="<?php echo esc_attr( $version ); ?>" />
				<input type="text" class="widefat" id="edd_uupd_version" name="edd_uupd_version" value="<?php echo esc_attr( $version ); ?>" placeholder="1.0.0" />
				<small>Saved directly to EDD Software Licensing version (<code>_edd_sl_version</code>).</small>
			</p>
			<p>
				<label for="edd_uupd_tested_up_to"><strong>Tested up to</strong></label>
				<input type="text" class="widefat" id="edd_uupd_tested_up_to" name="edd_uupd_tested_up_to" value="<?php echo esc_attr( $tested ); ?>" placeholder="6.8.3" />
			</p>
			<p>
				<label for="edd_uupd_upgrade_notice"><strong>Upgrade notice</strong></label>
				<textarea class="widefat" rows="3" id="edd_uupd_upgrade_notice" name="edd_uupd_upgrade_notice"><?php echo esc_textarea( $upgrade_notice ); ?></textarea>
				<small>Saved directly to <code>_edd_sl_upgrade_notice</code>.</small>
			</p>
			<p>
				<label for="edd_uupd_changelog"><strong>Changelog</strong></label>
				<textarea class="widefat" rows="8" id="edd_uupd_changelog" name="edd_uupd_changelog"><?php echo esc_textarea( $changelog ); ?></textarea>
				<small>Mirrored into the update metadata changelog section and saved to <code>_edd_sl_changelog</code>.</small>
			</p>
			<p>
				<label for="edd_uupd_global_vendor"><strong>Global vendor slug</strong></label>
				<input type="text" class="widefat" id="edd_uupd_global_vendor" name="edd_uupd_global_vendor" value="<?php echo esc_attr( $global_vendor ); ?>" placeholder="tdlab" />
				<small>Required for generated UUPD bootloaders. Use a stable machine-readable vendor key such as <code>tdlab</code>.</small>
			</p>
			<div style="margin:16px 0; padding:12px; border:1px solid #ddd; background:#fafafa;">
				<p style="margin-top:0;"><strong>Staged rollouts</strong></p>
				<p>
					<label><input type="checkbox" name="edd_uupd_sr_enabled" value="1" <?php checked( ! empty( $staged_rollouts['enabled'] ) ); ?> /> Enable staged rollouts</label>
				</p>
				<p>
					<label><input type="checkbox" name="edd_uupd_sr_batch_enabled" value="1" <?php checked( ! empty( $staged_rollouts['batch_enabled'] ) ); ?> /> Enable batch rollout</label>
				</p>
				<p>
					<label for="edd_uupd_sr_batch_max"><strong>Batch max (%)</strong></label>
					<input type="number" min="0" max="100" class="small-text" id="edd_uupd_sr_batch_max" name="edd_uupd_sr_batch_max" value="<?php echo esc_attr( $staged_rollouts['batch_max'] ); ?>" />
				</p>
				<p>
					<label><input type="checkbox" name="edd_uupd_sr_version_enabled" value="1" <?php checked( ! empty( $staged_rollouts['version_enabled'] ) ); ?> /> Enable version-based rollout</label>
				</p>
				<p>
					<label for="edd_uupd_sr_version"><strong>Version threshold</strong></label>
					<input type="text" class="regular-text" id="edd_uupd_sr_version" name="edd_uupd_sr_version" value="<?php echo esc_attr( $staged_rollouts['version'] ); ?>" placeholder="1.0.0" />
				</p>
				<p style="margin-bottom:0;">
					<label for="edd_uupd_sr_version_limit"><strong>Version comparison</strong></label>
					<select id="edd_uupd_sr_version_limit" name="edd_uupd_sr_version_limit">
						<option value="" <?php selected( (string) $staged_rollouts['version_limit'], '' ); ?>>Select…</option>
						<option value="above" <?php selected( (string) $staged_rollouts['version_limit'], 'above' ); ?>>Above threshold</option>
						<option value="below" <?php selected( (string) $staged_rollouts['version_limit'], 'below' ); ?>>Below threshold</option>
					</select>
				</p>
			</div>
			<p>
				<label for="edd_uupd_global_author"><strong>Global author name</strong></label>
				<input type="text" class="widefat" id="edd_uupd_global_author" name="edd_uupd_global_author" value="<?php echo esc_attr( $global_author ); ?>" placeholder="Your brand name" />
				<small>Optional global override for the updater author field. Leave blank to use the site title.</small>
			</p>
			<p>
				<label for="edd_uupd_global_author_url"><strong>Global author URL</strong></label>
				<input type="url" class="widefat" id="edd_uupd_global_author_url" name="edd_uupd_global_author_url" value="<?php echo esc_attr( $global_author_url ); ?>" placeholder="https://example.com/" />
				<small>Optional global override for the updater author homepage. Leave blank to use the site home URL.</small>
			</p>
			<?php
			self::render_media_field(
				[
					'field_id'    => 'edd_uupd_icon_1x',
					'field_name'  => 'edd_uupd_icon_1x',
					'label'       => 'Icon 1x',
					'value'       => $icon_1x,
					'description' => 'Used for icons[1x] in the metadata response.',
				]
			);
			self::render_media_field(
				[
					'field_id'    => 'edd_uupd_icon_2x',
					'field_name'  => 'edd_uupd_icon_2x',
					'label'       => 'Icon 2x',
					'value'       => $icon_2x,
					'description' => 'Used for icons[2x] in the metadata response.',
				]
			);
			self::render_media_field(
				[
					'field_id'    => 'edd_uupd_banner_low',
					'field_name'  => 'edd_uupd_banner_low',
					'label'       => 'Banner low',
					'value'       => $banner_low,
					'description' => 'Used for banners[low] in the metadata response.',
				]
			);
			self::render_media_field(
				[
					'field_id'    => 'edd_uupd_banner_high',
					'field_name'  => 'edd_uupd_banner_high',
					'label'       => 'Banner high',
					'value'       => $banner_high,
					'description' => 'Used for banners[high] in the metadata response.',
				]
			);
		}

		protected static function sanitize_package_mode( $value ) {
			$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
			return in_array( $value, [ 'native', 'price_id' ], true ) ? $value : 'native';
		}

		protected static function sanitize_version( $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			return preg_replace( '/[^0-9A-Za-z._\-+]/', '', $value );
		}

		protected static function sanitize_staged_rollout_limit( $value ) {
			$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
			return in_array( $value, [ 'above', 'below' ], true ) ? $value : '';
		}

		public static function save_download_meta_box( $post_id ) {
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			$has_uupd_nonce = isset( $_POST['edd_uupd_compat_meta_nonce'] )
				&& wp_verify_nonce(
					sanitize_text_field( wp_unslash( $_POST['edd_uupd_compat_meta_nonce'] ) ),
					'edd_uupd_compat_meta'
				);

			/*
			* Version sync.
			*
			* Rules:
			* - If only native EDD version changed, native EDD wins.
			* - If UUPD version changed, UUPD wins.
			* - If both changed, UUPD wins.
			* - If neither changed, keep the submitted/current value.
			*/
			$original_version = isset( $_POST['edd_uupd_original_version'] )
				? self::sanitize_version( wp_unslash( $_POST['edd_uupd_original_version'] ) )
				: self::get_download_version( $post_id );

			$uupd_version = isset( $_POST['edd_uupd_version'] )
				? self::sanitize_version( wp_unslash( $_POST['edd_uupd_version'] ) )
				: '';

			$edd_version = '';

			foreach ( [ '_edd_sl_version', 'edd_sl_version', 'edd_sl_download_version', 'edd_sl_product_version' ] as $field ) {
				if ( isset( $_POST[ $field ] ) ) {
					$edd_version = self::sanitize_version( wp_unslash( $_POST[ $field ] ) );
					break;
				}
			}

			$uupd_changed = '' !== $uupd_version && $uupd_version !== $original_version;
			$edd_changed  = '' !== $edd_version && $edd_version !== $original_version;

			$version = '';

			if ( $edd_changed && ! $uupd_changed ) {
				$version = $edd_version;
			} elseif ( $uupd_changed ) {
				$version = $uupd_version;
			} elseif ( '' !== $edd_version ) {
				$version = $edd_version;
			} elseif ( '' !== $uupd_version ) {
				$version = $uupd_version;
			} else {
				$version = $original_version;
			}

			if ( '' !== $version ) {
				update_post_meta( $post_id, '_edd_sl_version', $version );
				self::$pending_post_save[ (int) $post_id ]['_edd_sl_version'] = $version;
			}

			/*
			* Only save the rest of the UUPD fields when the UUPD metabox was submitted.
			*/
			if ( ! $has_uupd_nonce ) {
				return;
			}

			$map = [
				self::SLUG_META_KEY        => [ 'field' => 'edd_uupd_slug', 'sanitize' => 'sanitize_key' ],
				'_edd_sl_upgrade_notice'   => [ 'field' => 'edd_uupd_upgrade_notice', 'sanitize' => 'sanitize_textarea_field' ],
				'_edd_sl_changelog'        => [ 'field' => 'edd_uupd_changelog', 'sanitize' => 'wp_kses_post' ],
				'_uupd_package_mode'       => [ 'field' => 'edd_uupd_package_mode', 'sanitize' => [ __CLASS__, 'sanitize_package_mode' ] ],
				'_uupd_tested_up_to'       => [ 'field' => 'edd_uupd_tested_up_to', 'sanitize' => 'sanitize_text_field' ],
				'_uupd_icon_1x'            => [ 'field' => 'edd_uupd_icon_1x', 'sanitize' => 'esc_url_raw' ],
				'_uupd_icon_2x'            => [ 'field' => 'edd_uupd_icon_2x', 'sanitize' => 'esc_url_raw' ],
				'_uupd_banner_low'         => [ 'field' => 'edd_uupd_banner_low', 'sanitize' => 'esc_url_raw' ],
				'_uupd_banner_high'        => [ 'field' => 'edd_uupd_banner_high', 'sanitize' => 'esc_url_raw' ],
				'edd_sr_batch_max'         => [ 'field' => 'edd_uupd_sr_batch_max', 'sanitize' => 'absint' ],
				'edd_sr_version'           => [ 'field' => 'edd_uupd_sr_version', 'sanitize' => [ __CLASS__, 'sanitize_version' ] ],
				'edd_sr_version_limit'     => [ 'field' => 'edd_uupd_sr_version_limit', 'sanitize' => [ __CLASS__, 'sanitize_staged_rollout_limit' ] ],
			];

			foreach ( $map as $meta_key => $rule ) {
				$field = $rule['field'];
				$value = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
				$value = call_user_func( $rule['sanitize'], $value );

				if ( '' === (string) $value ) {
					delete_post_meta( $post_id, $meta_key );
				} else {
					update_post_meta( $post_id, $meta_key, $value );
				}
			}

			delete_post_meta( $post_id, '_uupd_latest_version' );

			$sr_enabled         = isset( $_POST['edd_uupd_sr_enabled'] ) ? '1' : '';
			$sr_batch_enabled   = isset( $_POST['edd_uupd_sr_batch_enabled'] ) ? '1' : '';
			$sr_version_enabled = isset( $_POST['edd_uupd_sr_version_enabled'] ) ? '1' : '';

			foreach (
				[
					'edd_sr_enabled'         => $sr_enabled,
					'edd_sr_batch_enabled'   => $sr_batch_enabled,
					'edd_sr_version_enabled' => $sr_version_enabled,
				] as $meta_key => $value
			) {
				if ( '' === $value ) {
					delete_post_meta( $post_id, $meta_key );
				} else {
					update_post_meta( $post_id, $meta_key, '1' );
				}
			}

			self::$pending_post_save[ (int) $post_id ] = array_merge(
				self::$pending_post_save[ (int) $post_id ] ?? [],
				[
					'_edd_sl_version'        => $version,
					'_uupd_tested_up_to'     => isset( $_POST['edd_uupd_tested_up_to'] ) ? sanitize_text_field( wp_unslash( $_POST['edd_uupd_tested_up_to'] ) ) : '',
					'_edd_sl_upgrade_notice' => isset( $_POST['edd_uupd_upgrade_notice'] ) ? sanitize_textarea_field( wp_unslash( $_POST['edd_uupd_upgrade_notice'] ) ) : '',
					'_edd_sl_changelog'      => isset( $_POST['edd_uupd_changelog'] ) ? wp_kses_post( wp_unslash( $_POST['edd_uupd_changelog'] ) ) : '',
					'edd_sr_enabled'         => $sr_enabled,
					'edd_sr_batch_enabled'   => $sr_batch_enabled,
					'edd_sr_version_enabled' => $sr_version_enabled,
					'edd_sr_batch_max'       => isset( $_POST['edd_uupd_sr_batch_max'] ) ? absint( wp_unslash( $_POST['edd_uupd_sr_batch_max'] ) ) : '',
					'edd_sr_version'         => isset( $_POST['edd_uupd_sr_version'] ) ? self::sanitize_version( wp_unslash( $_POST['edd_uupd_sr_version'] ) ) : '',
					'edd_sr_version_limit'   => isset( $_POST['edd_uupd_sr_version_limit'] ) ? self::sanitize_staged_rollout_limit( wp_unslash( $_POST['edd_uupd_sr_version_limit'] ) ) : '',
				]
			);

			if ( isset( $_POST['edd_uupd_global_vendor'] ) ) {
				$vendor = sanitize_key( wp_unslash( $_POST['edd_uupd_global_vendor'] ) );

				if ( '' === $vendor ) {
					delete_option( self::OPTION_VENDOR );
				} else {
					update_option( self::OPTION_VENDOR, $vendor, false );
				}
			}

			if ( isset( $_POST['edd_uupd_global_author'] ) ) {
				$author = sanitize_text_field( wp_unslash( $_POST['edd_uupd_global_author'] ) );

				if ( '' === $author ) {
					delete_option( self::OPTION_AUTHOR );
				} else {
					update_option( self::OPTION_AUTHOR, $author, false );
				}
			}

			if ( isset( $_POST['edd_uupd_global_author_url'] ) ) {
				$author_url = esc_url_raw( wp_unslash( $_POST['edd_uupd_global_author_url'] ) );

				if ( '' === $author_url ) {
					delete_option( self::OPTION_AUTHOR_URL );
				} else {
					update_option( self::OPTION_AUTHOR_URL, $author_url, false );
				}
			}
		}



		public static function apply_pending_post_save() {
			if ( empty( self::$pending_post_save ) ) {
				return;
			}

			foreach ( self::$pending_post_save as $post_id => $meta ) {
				$post_id = (int) $post_id;
				if ( $post_id <= 0 ) {
					continue;
				}

				if ( array_key_exists( '_edd_sl_version', $meta ) ) {
					$value = (string) $meta['_edd_sl_version'];
					if ( '' === $value ) {
						delete_post_meta( $post_id, '_edd_sl_version' );
					} else {
						update_post_meta( $post_id, '_edd_sl_version', $value );
					}
				}

				if ( array_key_exists( '_uupd_tested_up_to', $meta ) ) {
					$value = (string) $meta['_uupd_tested_up_to'];
					if ( '' === $value ) {
						delete_post_meta( $post_id, '_uupd_tested_up_to' );
					} else {
						update_post_meta( $post_id, '_uupd_tested_up_to', $value );
					}
				}

				foreach ( [ '_edd_sl_upgrade_notice', '_edd_sl_changelog', 'edd_sr_enabled', 'edd_sr_batch_enabled', 'edd_sr_version_enabled', 'edd_sr_batch_max', 'edd_sr_version', 'edd_sr_version_limit' ] as $key ) {
					if ( ! array_key_exists( $key, $meta ) ) {
						continue;
					}
					$value = $meta[ $key ];
					if ( '' === (string) $value ) {
						delete_post_meta( $post_id, $key );
					} else {
						update_post_meta( $post_id, $key, $value );
					}
				}
			}

			self::$pending_post_save = [];
		}
	}

	EDD_UUPD_Compatibility_Layer::init();
}
