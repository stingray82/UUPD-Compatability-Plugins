<?php
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



/**
 * GetPaid ↔ UUPD Compatibility + Custom Update Endpoint
 *
 * This file provides:
 *
 * 1) Native GetPaid update metadata endpoint (used internally by this compat layer):
 *    GET /wp-json/getpaid/v1/uupd/{license_key}/{item_id}?domain={site-host-or-url}
 *
 * 2) UUPD server compatibility endpoints (expected by updater.php + class-uupd-license-ui.php):
 *
 *    License UI (JSON body: { license_key, slug, domain } ):
 *      POST /wp-json/uupd/v1/license/activate
 *      POST /wp-json/uupd/v1/license/deactivate
 *      POST /wp-json/uupd/v1/license/check
 *
 *    Metadata endpoint (for REST-style UUPD servers):
 *      GET  /wp-json/uupd/v1/metadata?action=get_metadata&slug={slug}&key={license}&domain={site}
 *
 *    Legacy updater.php style (query string against the site root):
 *      GET  /?action=get_metadata&slug={slug}&key={license}&domain={site}
 *
 * The compatibility layer works by:
 *  - Resolving {slug} -> {item_id} using the wpi_item meta key "_wpi_plugin_slug" (fallback: post_name)
 *  - Proxying activate/deactivate/check to GetPaid License Manager endpoints:
 *      POST /wp-json/getpaid/v1/licenses/{license_key}/{item_id}/activate?app={host}
 *      POST /wp-json/getpaid/v1/licenses/{license_key}/{item_id}/deactivate?app={host}
 *      POST /wp-json/getpaid/v1/licenses/{license_key}/{item_id}/check?app={host}
 *      GET  /wp-json/getpaid/v1/licenses/{license_key}/{item_id}
 *  - Proxying metadata to:
 *      GET /wp-json/getpaid/v1/uupd/{license_key}/{item_id}?domain={site}
 */

/**
 * -----------------------------------------------------------------------------
 * Routes
 * -----------------------------------------------------------------------------
 */
add_action( 'rest_api_init', 'getpaid_uupd_register_routes' );
function getpaid_uupd_register_routes() {

	// Native GetPaid UUPD-style endpoint (already used by your system).
	register_rest_route(
		'getpaid/v1',
		'/uupd/(?P<license_key>[A-Za-z0-9\-]+)/(?P<item_id>\d+)',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'getpaid_uupd_handle_request',
			'permission_callback' => '__return_true',
			'args'                => [
				'license_key' => [ 'required' => true ],
				'item_id'     => [ 'required' => true ],
			],
		]
	);

	// UUPD compatibility: license activate/deactivate/check.
	register_rest_route(
		'uupd/v1',
		'/license/(?P<action>activate|deactivate|check)',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'getpaid_uupd_compat_license_handler',
			'permission_callback' => '__return_true',
			'args'                => [
				'action' => [ 'required' => true ],
			],
		]
	);

	// UUPD compatibility: REST-style metadata endpoint.
	register_rest_route(
		'uupd/v1',
		'/metadata',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'getpaid_uupd_compat_metadata_handler',
			'permission_callback' => '__return_true',
		]
	);
}

/**
 * -----------------------------------------------------------------------------
 * Legacy updater.php endpoint: {server}?action=get_metadata...
 * -----------------------------------------------------------------------------
 *
 * updater.php often calls the server root (not REST) with:
 *   /?action=get_metadata&slug=...&key=...&domain=...
 *
 * We intercept and output JSON early.
 */
add_action( 'init', 'getpaid_uupd_maybe_handle_legacy_metadata', 0 );
function getpaid_uupd_maybe_handle_legacy_metadata() {

	if ( empty( $_GET['action'] ) || 'get_metadata' !== (string) $_GET['action'] ) {
		return;
	}

	$slug   = isset( $_GET['slug'] )   ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
	$key    = uupd_compat_metadata_key();
	$domain = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '';

	if ( $slug === '' || trim( $key ) === '' || $domain === '' ) {
		getpaid_uupd_output_json_and_exit(
			[ 'success' => false, 'message' => 'Missing slug, key or domain.' ],
			400
		);
	}

	list( $data, $status ) = getpaid_uupd_fetch_metadata( $slug, $key, $domain );

	getpaid_uupd_output_json_and_exit( $data, $status );
}

/**
 * Output JSON and stop execution.
 */
function getpaid_uupd_output_json_and_exit( $data, $status = 200 ) {
	status_header( (int) $status );
	header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
	echo wp_json_encode( $data );
	exit;
}

/**
 * -----------------------------------------------------------------------------
 * Helpers (shared by REST + legacy)
 * -----------------------------------------------------------------------------
 */

/**
 * Resolve a wpi_item ID from a plugin slug.
 *
 * Primary: meta key "_wpi_plugin_slug" == $slug
 * Fallback: post_name == $slug
 */
function getpaid_uupd_resolve_item_id_from_slug( $slug ) {

	$slug = sanitize_key( (string) $slug );
	if ( $slug === '' ) {
		return 0;
	}

	$ids = get_posts( [
		'post_type'      => 'wpi_item',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_key'       => '_wpi_plugin_slug',
		'meta_value'     => $slug,
	] );

	if ( ! empty( $ids[0] ) ) {
		return (int) $ids[0];
	}

	$ids = get_posts( [
		'post_type'      => 'wpi_item',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'name'           => $slug,
	] );

	return ! empty( $ids[0] ) ? (int) $ids[0] : 0;
}

/**
 * Normalize a UUPD "domain" value (often a URL) into GetPaid "app" host string.
 */
function getpaid_uupd_normalize_app( $domain ) {

	$domain = trim( (string) $domain );
	if ( $domain === '' ) {
		return '';
	}

	$host = wp_parse_url( $domain, PHP_URL_HOST );
	if ( $host ) {
		return $host;
	}

	$domain = preg_replace( '#^https?://#i', '', $domain );
	$domain = rtrim( $domain, '/' );

	return $domain;
}

/**
 * Fetch metadata by proxying into the native GetPaid UUPD endpoint.
 *
 * @return array{0:mixed,1:int} [data, http_status]
 */
function getpaid_uupd_fetch_metadata( $slug, $key, $domain ) {

	$slug   = sanitize_key( (string) $slug );
	$key    = trim( (string) $key );
	$domain = (string) $domain;

	$item_id = getpaid_uupd_resolve_item_id_from_slug( $slug );
	if ( ! $item_id ) {
		return [ [ 'success' => false, 'message' => 'Could not resolve item_id for slug.' ], 404 ];
	}

	// Proxy into the existing GetPaid endpoint (it enforces domain activation).
	$route = sprintf( '/getpaid/v1/uupd/%s/%d', rawurlencode( $key ), (int) $item_id );
	$req   = new WP_REST_Request( 'GET', $route );
	$req->set_query_params( [ 'domain' => $domain ] );

	$resp = rest_do_request( $req );

	if ( $resp instanceof WP_Error ) {
		return [ [ 'success' => false, 'message' => $resp->get_error_message() ], 400 ];
	}

	return [ $resp->get_data(), (int) $resp->get_status() ];
}

/**
 * Build a UUPD-style license response object from GetPaid license data.
 */
function getpaid_uupd_format_license_response( $license_data, $success, $message = '' ) {

	$out = [
		'success'        => (bool) $success,
		'message'        => (string) $message,
		'status'         => 'inactive',
		'license_status' => 'inactive',
	];

	if ( is_array( $license_data ) ) {
		$out = array_merge( $out, $license_data );
	}

	$status = isset( $out['status'] ) ? strtolower( (string) $out['status'] ) : '';

	if ( $status === 'active' ) {
		$out['status']         = 'active';
		$out['license_status'] = 'active';
		$out['success']        = true;
	} elseif ( $status !== '' ) {
		$out['license_status'] = $status;
	}

	if ( isset( $out['date_expires'] ) && ! isset( $out['expiry'] ) ) {
		$out['expiry'] = $out['date_expires'];
	}

	return $out;
}

/**
 * -----------------------------------------------------------------------------
 * UUPD compatibility: License activate/deactivate/check
 * -----------------------------------------------------------------------------
 *
 * Incoming JSON (from class-uupd-license-ui.php):
 *   { "license_key": "...", "slug": "my-plugin", "domain": "https://site.tld" }
 */
function getpaid_uupd_compat_license_handler( WP_REST_Request $request ) {

	$action      = strtolower( (string) $request->get_param( 'action' ) );
	$payload     = (array) $request->get_json_params();

	$license_key = isset( $payload['license_key'] ) ? trim( (string) $payload['license_key'] ) : '';
	$slug        = isset( $payload['slug'] ) ? sanitize_key( (string) $payload['slug'] ) : '';
	$domain      = isset( $payload['domain'] ) ? (string) $payload['domain'] : '';
	$app         = getpaid_uupd_normalize_app( $domain );

	if ( $license_key === '' || $slug === '' || $app === '' ) {
		return new WP_Error( 'missing_params', 'license_key, slug and domain are required.', [ 'status' => 400 ] );
	}

	$item_id = getpaid_uupd_resolve_item_id_from_slug( $slug );
	if ( ! $item_id ) {
		return new WP_Error( 'unknown_slug', 'Could not resolve item_id for slug.', [ 'status' => 404 ] );
	}

	if ( $action === 'activate' ) {

		$route = sprintf( '/getpaid/v1/licenses/%s/%d/activate', rawurlencode( $license_key ), (int) $item_id );
		$req   = new WP_REST_Request( 'POST', $route );
		$req->set_query_params( [ 'app' => $app ] );
		$resp  = rest_do_request( $req );

		if ( $resp instanceof WP_Error ) {
			return new WP_Error( 'activation_failed', $resp->get_error_message(), [ 'status' => 400 ] );
		}

		// If already active, return a friendly "Already activated" but still success.
		if ( (int) $resp->get_status() >= 400 ) {
			$err      = $resp->get_data();
			$err_code = is_array( $err ) ? ( $err['code'] ?? '' ) : '';

			if ( $err_code === 'already_activated' ) {
				$val_route = sprintf( '/getpaid/v1/licenses/%s/%d', rawurlencode( $license_key ), (int) $item_id );
				$val_req   = new WP_REST_Request( 'GET', $val_route );
				$val_resp  = rest_do_request( $val_req );

				if ( $val_resp instanceof WP_Error || (int) $val_resp->get_status() >= 400 ) {
					return new WP_Error( 'activation_failed', 'Activation failed.', [ 'status' => 400 ] );
				}

				return new WP_REST_Response(
					getpaid_uupd_format_license_response( $val_resp->get_data(), true, 'Already activated.' ),
					200
				);
			}

			$msg = is_array( $err ) && ! empty( $err['message'] ) ? (string) $err['message'] : 'Activation failed.';
			return new WP_Error( 'activation_failed', $msg, [ 'status' => (int) $resp->get_status() ] );
		}

		return new WP_REST_Response(
			getpaid_uupd_format_license_response( $resp->get_data(), true, 'Activated.' ),
			200
		);
	}

	if ( $action === 'deactivate' ) {

		$route = sprintf( '/getpaid/v1/licenses/%s/%d/deactivate', rawurlencode( $license_key ), (int) $item_id );
		$req   = new WP_REST_Request( 'POST', $route );
		$req->set_query_params( [ 'app' => $app ] );
		$resp  = rest_do_request( $req );

		if ( $resp instanceof WP_Error ) {
			return new WP_Error( 'deactivation_failed', $resp->get_error_message(), [ 'status' => 400 ] );
		}

		return new WP_REST_Response(
			getpaid_uupd_format_license_response( $resp->get_data(), true, 'Deactivated.' ),
			200
		);
	}

	// check
	$check_route = sprintf( '/getpaid/v1/licenses/%s/%d/check', rawurlencode( $license_key ), (int) $item_id );
	$check_req   = new WP_REST_Request( 'POST', $check_route );
	$check_req->set_query_params( [ 'app' => $app ] );
	$check_resp  = rest_do_request( $check_req );

	$is_active = false;
	if ( ! ( $check_resp instanceof WP_Error ) ) {
		$check_data = $check_resp->get_data();
		$is_active  = ( is_array( $check_data ) && ! empty( $check_data['is_active'] ) );
	}

	$val_route = sprintf( '/getpaid/v1/licenses/%s/%d', rawurlencode( $license_key ), (int) $item_id );
	$val_req   = new WP_REST_Request( 'GET', $val_route );
	$val_resp  = rest_do_request( $val_req );
	$val_data  = ( $val_resp instanceof WP_Error ) ? [] : $val_resp->get_data();

	$msg = $is_active ? 'License is active for this site.' : 'License is not active for this site.';
	$out = getpaid_uupd_format_license_response( $val_data, $is_active, $msg );
	$out['status']         = $is_active ? 'active' : 'inactive';
	$out['license_status'] = $out['status'];

	return new WP_REST_Response( $out, 200 );
}

/**
 * -----------------------------------------------------------------------------
 * UUPD compatibility: REST-style metadata endpoint
 * -----------------------------------------------------------------------------
 *
 * GET /wp-json/uupd/v1/metadata?action=get_metadata&slug=...&key=...&domain=...
 */
function getpaid_uupd_compat_metadata_handler( WP_REST_Request $request ) {

	$action = (string) $request->get_param( 'action' );
	if ( $action !== '' && strtolower( $action ) !== 'get_metadata' ) {
		return new WP_Error( 'invalid_action', 'Invalid action.', [ 'status' => 400 ] );
	}

	$slug   = sanitize_key( (string) $request->get_param( 'slug' ) );
	$key    = uupd_compat_metadata_key( $request );
	$domain = (string) $request->get_param( 'domain' );

	if ( $slug === '' || trim( $key ) === '' || $domain === '' ) {
		return new WP_Error( 'missing_params', 'slug, key, and domain are required.', [ 'status' => 400 ] );
	}

	list( $data, $status ) = getpaid_uupd_fetch_metadata( $slug, $key, $domain );

	return new WP_REST_Response( $data, (int) $status );
}

/**
 * -----------------------------------------------------------------------------
 * Native GetPaid update endpoint handler
 * -----------------------------------------------------------------------------
 *
 * GET /wp-json/getpaid/v1/uupd/{license_key}/{item_id}?domain=...
 */
function getpaid_uupd_handle_request( WP_REST_Request $request ) {

	$license_key = (string) $request->get_param( 'license_key' );
	$item_id     = absint( $request->get_param( 'item_id' ) );

	$domain = (string) $request->get_param( 'domain' );
	if ( $domain === '' ) {
		return new WP_Error(
			'missing_domain',
			'The "domain" query parameter is required.',
			[ 'status' => 400 ]
		);
	}

	$domain = sanitize_text_field( $domain );
	$domain = preg_replace( '#^https?://#i', '', $domain );
	$domain = rtrim( $domain, '/' );

	if ( $license_key === '' || ! $item_id ) {
		return new WP_Error(
			'invalid_params',
			'Missing or invalid license key or item ID.',
			[ 'status' => 400 ]
		);
	}

	$license_ok = getpaid_uupd_validate_license( $license_key, $item_id, $license_data, $domain );

	if ( ! $license_ok ) {
		return new WP_Error(
			'invalid_license',
			'License is not valid, expired, or not activated for this domain.',
			[
				'status'  => 403,
				'license' => $license_data,
			]
		);
	}

	$post = get_post( $item_id );
	if ( ! $post ) {
		return new WP_Error( 'invalid_item', 'Item not found.', [ 'status' => 404 ] );
	}

	$slug          = get_post_meta( $item_id, '_wpi_plugin_slug', true );
	$name          = get_the_title( $item_id );
	$version       = get_post_meta( $item_id, 'getpaid_license_manager_item_version', true );
	$changelog_raw = get_post_meta( $item_id, 'getpaid_license_manager_changelog', true );

	$requires_php    = get_post_meta( $item_id, '_wpi_requires_php', true );
	$requires        = get_post_meta( $item_id, '_wpi_requires', true );
	$tested          = get_post_meta( $item_id, '_wpi_tested_upto', true );
	$author          = get_post_meta( $item_id, '_wpi_author', true );
	$author_homepage = get_post_meta( $item_id, '_wpi_author_homepage', true );

	// Banner/icon helpers.
	$banner_low  = get_post_meta( $item_id, '_wpi_banner_low_url', true );
	if ( empty( $banner_low ) ) {
		$banner_low_id = get_post_meta( $item_id, '_wpi_banner_low_id', true );
		$banner_low    = $banner_low_id ? wp_get_attachment_image_url( $banner_low_id, 'full' ) : get_post_meta( $item_id, '_wpi_banner_low', true );
	}

	$banner_high = get_post_meta( $item_id, '_wpi_banner_high_url', true );
	if ( empty( $banner_high ) ) {
		$banner_high_id = get_post_meta( $item_id, '_wpi_banner_high_id', true );
		$banner_high    = $banner_high_id ? wp_get_attachment_image_url( $banner_high_id, 'full' ) : get_post_meta( $item_id, '_wpi_banner_high', true );
	}

	$icon_1x = get_post_meta( $item_id, '_wpi_icon_1x_url', true );
	if ( empty( $icon_1x ) ) {
		$icon_1x_id = get_post_meta( $item_id, '_wpi_icon_1x_id', true );
		$icon_1x    = $icon_1x_id ? wp_get_attachment_image_url( $icon_1x_id, 'full' ) : get_post_meta( $item_id, '_wpi_icon_1x', true );
	}

	$icon_2x = get_post_meta( $item_id, '_wpi_icon_2x_url', true );
	if ( empty( $icon_2x ) ) {
		$icon_2x_id = get_post_meta( $item_id, '_wpi_icon_2x_id', true );
		$icon_2x    = $icon_2x_id ? wp_get_attachment_image_url( $icon_2x_id, 'full' ) : get_post_meta( $item_id, '_wpi_icon_2x', true );
	}

	$last_updated = get_post_meta( $item_id, '_wpi_last_updated', true );

	$description_meta = get_post_meta( $item_id, 'getpaid_license_manager_description', true );
	$description      = ( '' === $description_meta )
		? apply_filters( 'the_content', $post->post_content )
		: wpautop( wp_kses_post( $description_meta ) );

	$installation_meta = get_post_meta( $item_id, 'getpaid_license_manager_installation', true );
	$faq_meta          = get_post_meta( $item_id, 'getpaid_license_manager_faq', true );

	$installation   = wpautop( wp_kses_post( $installation_meta ) );
	$faq            = wpautop( wp_kses_post( $faq_meta ) );
	$changelog_html = wpautop( wp_kses_post( $changelog_raw ) );

	$download_url = getpaid_uupd_generate_download_url( $item_id, $license_key, $license_data );
	if ( ! $download_url ) {
		return new WP_Error( 'no_download', 'No download found for this item.', [ 'status' => 500 ] );
	}

	$response_data = [
		'slug'            => $slug,
		'name'            => $name,
		'version'         => $version,
		'author'          => $author,
		'author_homepage' => $author_homepage,
		'requires_php'    => $requires_php,
		'requires'        => $requires,
		'tested'          => $tested,
		'sections'        => [
			'description'                => $description,
			'installation'               => $installation,
			'frequently_asked_questions' => $faq,
			'changelog'                  => $changelog_html,
		],
		'last_updated' => $last_updated,
		'download_url' => $download_url,
		'banners'      => [
			'low'  => $banner_low,
			'high' => $banner_high,
		],
		'icons'        => [
			'1x' => $icon_1x,
			'2x' => $icon_2x,
		],
	];

	return new WP_REST_Response( $response_data, 200 );
}

/**
 * -----------------------------------------------------------------------------
 * License validation + secure-ish downloads (unchanged logic)
 * -----------------------------------------------------------------------------
 */

function getpaid_uupd_validate_license( $license_key, $item_id, &$license_data = null, $domain = null ) {

	$license_data = null;

	$route   = sprintf( '/getpaid/v1/licenses/%s/%d', $license_key, $item_id );
	$request = new WP_REST_Request( 'GET', $route );
	$response = rest_do_request( $request );

	if ( $response instanceof WP_Error ) {
		return false;
	}

	if ( 200 !== (int) $response->get_status() ) {
		return false;
	}

	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return false;
	}

	$license_data = $data;

	if ( empty( $data['status'] ) || 'active' !== strtolower( (string) $data['status'] ) ) {
		return false;
	}

	if ( ! empty( $data['item_id'] ) && (int) $data['item_id'] !== (int) $item_id ) {
		return false;
	}

	if ( ! empty( $data['license_key'] ) && (string) $data['license_key'] !== (string) $license_key ) {
		return false;
	}

	if ( ! empty( $data['date_expires_gmt'] ) && '0000-00-00 00:00:00' !== $data['date_expires_gmt'] ) {
		$expires_ts = strtotime( $data['date_expires_gmt'] );
		if ( $expires_ts && $expires_ts < time() ) {
			return false;
		}
	}

	if ( empty( $domain ) ) {
		return false;
	}

	if ( empty( $data['activated_on'] ) || ! is_array( $data['activated_on'] ) ) {
		return false;
	}

	if ( ! array_key_exists( $domain, $data['activated_on'] ) ) {
		return false;
	}

	return true;
}

function getpaid_uupd_generate_download_url( $item_id, $license_key, $license_data = null ) {

	$invoice_id = 0;
	$user_id    = 0;
	if ( is_array( $license_data ) ) {
		$invoice_id = ! empty( $license_data['invoice_id'] ) ? absint( $license_data['invoice_id'] ) : 0;
		$user_id    = ! empty( $license_data['user_id'] ) ? absint( $license_data['user_id'] ) : 0;
	} elseif ( is_object( $license_data ) ) {
		$invoice_id = ! empty( $license_data->invoice_id ) ? absint( $license_data->invoice_id ) : 0;
		$user_id    = ! empty( $license_data->user_id ) ? absint( $license_data->user_id ) : 0;
	}

	$file_url = '';

	if ( function_exists( 'gpd_uupd_resolve_download_rows_for_purchase' ) ) {
		$rows = gpd_uupd_resolve_download_rows_for_purchase( $item_id, $invoice_id, $user_id );
		if ( ! empty( $rows ) ) {
			$first = reset( $rows );
			if ( is_object( $first ) && ! empty( $first->item_file ) ) {
				$file_url = $first->item_file;
			}
		}
	}

	// Fallback: preserve original behaviour for non-variation installs.
	if ( empty( $file_url ) ) {
		$raw = get_post_meta( $item_id, '_getpaid_item_downloads', true );
		if ( empty( $raw ) ) {
			return false;
		}

		$downloads = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;
		if ( empty( $downloads ) || ! is_array( $downloads ) ) {
			return false;
		}

		if ( isset( $downloads['file'] ) && ! empty( $downloads['file'] ) ) {
			$file_url = $downloads['file'];
		} else {
			$first = reset( $downloads );
			if ( is_array( $first ) && ! empty( $first['file'] ) ) {
				$file_url = $first['file'];
			} else {
				return false;
			}
		}
	}

	$file_url = esc_url_raw( $file_url );
	if ( empty( $file_url ) ) {
		return false;
	}

	$expires     = time() + 13 * HOUR_IN_SECONDS;
	$data_string = implode( '|', [ $item_id, $license_key, $file_url, $expires ] );
	$secret      = wp_salt( 'getpaid_uupd_download' );
	$hmac        = hash_hmac( 'sha256', $data_string, $secret );
	$token       = base64_encode( $data_string . '|' . $hmac );

	$normalized_token = rtrim( $token, '=' );

	set_transient(
		'getpaid_uupd_' . md5( $normalized_token ),
		[
			'item_id'        => $item_id,
			'license_key'    => $license_key,
			'file_url'       => $file_url,
			'expires'        => $expires,
			'download_count' => 0,
		],
		DAY_IN_SECONDS
	);

	return add_query_arg( [ 'getpaid_uupd_download' => $token ], home_url( '/' ) );
}

add_action( 'init', 'getpaid_uupd_maybe_handle_download' );
function getpaid_uupd_maybe_handle_download() {

	if ( empty( $_GET['getpaid_uupd_download'] ) ) {
		return;
	}

	$raw_token = wp_unslash( $_GET['getpaid_uupd_download'] );
	$raw_token = rawurldecode( $raw_token );
	$raw_token = preg_replace( '/\s+/', '', $raw_token );

	$len = strlen( $raw_token );
	$mod = $len % 4;
	if ( $mod > 0 ) {
		$raw_token .= str_repeat( '=', 4 - $mod );
	}

	$decoded = base64_decode( $raw_token );
	if ( false === $decoded ) {
		wp_die( 'Invalid download token.', 'Download Error', [ 'response' => 403 ] );
	}

	$parts = explode( '|', $decoded );
	if ( count( $parts ) < 5 ) {
		wp_die( 'Malformed download token.', 'Download Error', [ 'response' => 403 ] );
	}

	list( $item_id, $license_key, $file_url, $expires, $hmac ) = $parts;

	$item_id     = absint( $item_id );
	$license_key = sanitize_text_field( $license_key );
	$file_url    = esc_url_raw( $file_url );
	$expires     = (int) $expires;

	$data_string = implode( '|', [ $item_id, $license_key, $file_url, $expires ] );
	$secret      = wp_salt( 'getpaid_uupd_download' );
	$expected    = hash_hmac( 'sha256', $data_string, $secret );

	if ( ! hash_equals( $expected, $hmac ) ) {
		wp_die( 'Download token verification failed.', 'Download Error', [ 'response' => 403 ] );
	}

	if ( $expires < time() ) {
		wp_die( 'Download link has expired.', 'Download Error', [ 'response' => 410 ] );
	}

	$normalized_token = rtrim( $raw_token, '=' );
	$transient_key    = 'getpaid_uupd_' . md5( $normalized_token );
	$transient        = get_transient( $transient_key );

	if ( is_array( $transient ) ) {
		$transient['download_count'] = isset( $transient['download_count'] ) ? (int) $transient['download_count'] + 1 : 1;
		$remaining = max( 60, $expires - time() );
		set_transient( $transient_key, $transient, $remaining );
	}

	if ( class_exists( 'GetPaid_Secure_Zip_Uploads' ) ) {
		GetPaid_Secure_Zip_Uploads::stream_zip_download( $item_id, $file_url );
	} else {
		wp_safe_redirect( $file_url );
		exit;
	}
}
