<?php
/**
 * Plugin Name: UUPD > Digital License Manager
 * Description: UUPD compatibility layer for Digital License Manager + Digital License Manager Pro software releases.
 * Version: 0.4.0
 * Author: Reallyusefulplugins.com
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'UUPD_DLM_Compatibility_Layer' ) ) {
final class UUPD_DLM_Compatibility_Layer {
    const VERSION       = '0.4.0';
    const MENU_SLUG     = 'uupd-dlm-generator';
    const OPTION_VENDOR   = 'uupd_dlm_global_vendor';
    const OPTION_SETTINGS = 'uupd_dlm_settings';
    const SETTINGS_SLUG = 'uupd-dlm-settings';
    const SLUG_META_KEY = '_uupd_slug';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        add_action( 'init', [ __CLASS__, 'maybe_handle_legacy_metadata' ], 0 );
        add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_dlm_admin_enhancements' ], 50 );
        add_action( 'wp_ajax_uupd_dlm_get_snippet', [ __CLASS__, 'ajax_get_snippet' ] );
        add_action( 'admin_post_uupd_dlm_save_settings', [ __CLASS__, 'save_settings' ] );
    }

    public static function register_routes() {
        register_rest_route( 'uupd/v1', '/license/(?P<action>activate|deactivate|check)', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'handle_license_request' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'uupd/v1', '/metadata', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'handle_metadata_request' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'uupd/v1', '/dlm/download/(?P<token>[\w-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'handle_download_request' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function maybe_handle_legacy_metadata() {
        if ( empty( $_GET['action'] ) || 'get_metadata' !== strtolower( (string) $_GET['action'] ) ) {
            return;
        }

        $slug    = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
        $key     = isset( $_GET['key'] ) ? self::sanitize_license_key( wp_unslash( $_GET['key'] ) ) : '';
        $domain  = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '';
        $channel = isset( $_GET['release_channel'] ) ? sanitize_key( wp_unslash( $_GET['release_channel'] ) ) : 'stable';
        $allow   = ! empty( $_GET['allow_prerelease'] );
        $item_id = isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : 0;

        list( $data, $status ) = self::build_uupd_metadata( $slug, $key, $domain, $item_id, $channel, $allow );
        status_header( (int) $status );
        header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
        echo wp_json_encode( $data );
        exit;
    }

    protected static function dependencies_ready() {
        return class_exists( '\\IdeoLogix\\DigitalLicenseManager\\Core\\Services\\LicensesService' )
            && class_exists( '\\IdeoLogix\\DigitalLicenseManager\\Database\\Repositories\\LicenseActivations' )
            && class_exists( '\\IdeoLogix\\DigitalLicenseManagerPro\\Database\\Repositories\\Software' )
            && class_exists( '\\IdeoLogix\\DigitalLicenseManagerPro\\Database\\Repositories\\SoftwareReleases' )
            && class_exists( '\\IdeoLogix\\DigitalLicenseManagerPro\\Core\\Storage\\Manager' );
    }

    protected static function dependency_error() {
        return [
            'success' => false,
            'status'  => 'inactive',
            'message' => 'Digital License Manager and Digital License Manager Pro software module are required.',
        ];
    }

    public static function handle_license_request( WP_REST_Request $request ) {
        if ( ! self::dependencies_ready() ) {
            return new WP_REST_Response( self::dependency_error(), 503 );
        }

        $action  = strtolower( (string) $request['action'] );
        $payload = (array) $request->get_json_params();
        if ( empty( $payload ) ) {
            $payload = (array) $request->get_body_params();
        }

        $license_key = isset( $payload['license_key'] ) ? self::sanitize_license_key( $payload['license_key'] ) : '';
        $slug        = isset( $payload['slug'] ) ? sanitize_key( (string) $payload['slug'] ) : '';
        $domain      = isset( $payload['domain'] ) ? self::normalize_domain( $payload['domain'] ) : '';
        $item_id     = isset( $payload['item_id'] ) ? absint( $payload['item_id'] ) : 0;
        $token       = isset( $payload['activation_hash'] ) ? sanitize_text_field( (string) $payload['activation_hash'] ) : '';
        if ( '' === $token && isset( $payload['activation_token'] ) ) {
            $token = sanitize_text_field( (string) $payload['activation_token'] );
        }

        if ( '' === $license_key ) {
            return new WP_REST_Response( [ 'success' => false, 'status' => 'inactive', 'message' => 'license_key is required.' ], 400 );
        }

        $license = self::find_license( $license_key );
        if ( is_wp_error( $license ) ) {
            return new WP_REST_Response( self::license_response( null, null, 'invalid', $license->get_error_message() ), 404 );
        }

        $software = self::resolve_software( $slug, $item_id, $license );
        if ( ! $software ) {
            return new WP_REST_Response( self::license_response( $license, null, 'invalid', 'License is not assigned to the requested software.' ), 404 );
        }

        $invalid = self::license_invalid_status( $license );
        if ( $invalid ) {
            return new WP_REST_Response( self::license_response( $license, null, $invalid, ucfirst( $invalid ) . ' license.' ), 200 );
        }

        $activation = $token ? self::find_activation_by_token( $token, $license->getId() ) : self::find_activation_for_domain( $license, $domain );
        $service    = new \IdeoLogix\DigitalLicenseManager\Core\Services\LicensesService();

        if ( 'activate' === $action ) {
            if ( ! $activation ) {
                $activation = $service->activate( $license_key, [
                    'label' => $domain ?: 'UUPD',
                    'meta'  => [ 'domain' => $domain, 'uupd_slug' => self::software_slug( $software ), 'source' => 'uupd' ],
                ] );
                if ( is_wp_error( $activation ) ) {
                    return new WP_REST_Response( self::license_response( $license, null, 'inactive', $activation->get_error_message() ), 200 );
                }
            }
            return new WP_REST_Response( self::license_response( $license, $activation, 'active', 'License activated.' ), 200 );
        }

        if ( 'deactivate' === $action ) {
            if ( $activation ) {
                $activation_token = (string) $activation->getToken();

                // DLM's deactivate() only timestamps the activation. UUPD deactivation
                // is expected to release the seat completely, so remove the activation
                // record after DLM has run its normal deactivation logic.
                if ( is_null( $activation->getDeactivatedAt() ) ) {
                    $result = $service->deactivate( $activation_token );
                    if ( is_wp_error( $result ) ) {
                        return new WP_REST_Response( self::license_response( $license, $activation, 'inactive', $result->get_error_message() ), 500 );
                    }
                }

                $deleted = $service->deleteActivation( $activation_token );
                if ( is_wp_error( $deleted ) ) {
                    return new WP_REST_Response( self::license_response( $license, null, 'inactive', $deleted->get_error_message() ), 500 );
                }
                if ( ! $deleted ) {
                    return new WP_REST_Response( self::license_response( $license, null, 'inactive', 'License was deactivated but the activation record could not be removed.' ), 500 );
                }
            }
            return new WP_REST_Response( self::license_response( $license, null, 'inactive', 'License deactivated and activation removed.' ), 200 );
        }

        $status = $activation && is_null( $activation->getDeactivatedAt() ) ? 'active' : 'inactive';
        return new WP_REST_Response( self::license_response( $license, $activation, $status, 'License checked.' ), 200 );
    }

    protected static function license_response( $license, $activation, $status, $message ) {
        $limit = $license ? $license->getActivationsLimit() : '';
        $used  = $license ? (int) $license->getTimesActivatedCount() : 0;
        $left  = ( is_numeric( $limit ) && (int) $limit > 0 ) ? max( 0, (int) $limit - $used ) : '';
        $token = $activation && is_null( $activation->getDeactivatedAt() ) ? (string) $activation->getToken() : '';

        return [
            'success'          => 'active' === $status || 'inactive' === $status,
            'message'          => (string) $message,
            'status'           => $status,
            'license_status'   => $status,
            'license'          => $status,
            'activation_hash'  => $token,
            'activation_token' => $token,
            'activation_limit' => $limit,
            'max_activations'  => $limit,
            'activations'      => $used,
            'activations_left' => $left,
            'expiry'           => $license ? (string) $license->getExpiresAt() : '',
            'date_expires'     => $license ? (string) $license->getExpiresAt() : '',
            'domain_active'    => 'active' === $status,
            'product_id'       => $license ? (int) $license->getProductId() : 0,
        ];
    }

    public static function handle_metadata_request( WP_REST_Request $request ) {
        $slug    = sanitize_key( (string) $request->get_param( 'slug' ) );
        $key     = self::sanitize_license_key( (string) $request->get_param( 'key' ) );
        $domain  = sanitize_text_field( (string) $request->get_param( 'domain' ) );
        $channel = sanitize_key( (string) $request->get_param( 'release_channel' ) );
        $allow   = ! empty( $request->get_param( 'allow_prerelease' ) );
        $item_id = absint( (string) $request->get_param( 'item_id' ) );
        list( $data, $status ) = self::build_uupd_metadata( $slug, $key, $domain, $item_id, $channel, $allow );
        return new WP_REST_Response( $data, (int) $status );
    }

    protected static function build_uupd_metadata( $slug, $license_key, $domain, $item_id = 0, $channel = 'stable', $allow_prerelease = false ) {
        if ( ! self::dependencies_ready() ) {
            return [ self::dependency_error(), 503 ];
        }
        if ( '' === $slug && ! $item_id ) {
            return [ [ 'success' => false, 'message' => 'Missing slug or item_id.' ], 400 ];
        }

        $license = self::find_license( $license_key );
        if ( is_wp_error( $license ) ) {
            return [ [ 'success' => false, 'license_status' => 'invalid', 'message' => $license->get_error_message() ], 403 ];
        }
        $invalid = self::license_invalid_status( $license );
        if ( $invalid ) {
            return [ [ 'success' => false, 'license_status' => $invalid, 'message' => ucfirst( $invalid ) . ' license.' ], 403 ];
        }

        $software = self::resolve_software( $slug, $item_id, $license );
        if ( ! $software ) {
            return [ [ 'success' => false, 'license_status' => 'invalid', 'message' => 'License is not assigned to the requested software.' ], 404 ];
        }

        $activation = self::find_activation_for_domain( $license, self::normalize_domain( $domain ) );
        $active     = $activation && is_null( $activation->getDeactivatedAt() );

        $stable = method_exists( $software, 'getStableRelease' ) ? $software->getStableRelease() : null;
        if ( ! $stable ) {
            return [ [ 'success' => false, 'message' => 'No stable release is configured for this software.' ], 404 ];
        }

        $requested = self::normalize_uupd_channel( $channel, $allow_prerelease );
        $pre       = self::best_prerelease( $software, $requested );

        $stable_url = $active ? self::download_url( $activation, $software, $stable ) : '';
        $pre_url    = ( $active && $pre ) ? self::download_url( $activation, $software, $pre ) : '';

        $meta = self::release_metadata( $software, $stable );
        $meta['slug']                = self::software_slug( $software );
        $meta['version']             = (string) $stable->getVersion();
        $meta['new_version']         = (string) $stable->getVersion();
        $meta['stable_version']      = (string) $stable->getVersion();
        $meta['download_url']        = $stable_url;
        $meta['package']             = $stable_url;
        $meta['download_link']       = $stable_url;
        $meta['stable_download_url'] = $stable_url;
        $meta['license_required']    = true;
        $meta['license_status']      = $active ? 'active' : 'inactive';
        $meta['item_id']             = (int) $software->getId();
        $meta['software_id']         = (int) $software->getId();
        $meta['release_channel']     = $requested;

        if ( $pre ) {
            $meta['prerelease_version']      = (string) $pre->getVersion();
            $meta['prerelease_channel']      = (string) $pre->getChannel();
            $meta['prerelease_download_url'] = $pre_url;
        }

        return [ $meta, 200 ];
    }

    protected static function release_metadata( $software, $release ) {
        $sections = [];
        $description = method_exists( $software, 'getDescription' ) ? (string) $software->getDescription() : '';
        if ( '' !== $description ) {
            $sections['description'] = wpautop( $description );
        }
        $changelog = method_exists( $release, 'getChangelog' ) ? (string) $release->getChangelog() : '';
        if ( '' !== $changelog ) {
            $sections['changelog'] = wpautop( esc_html( $changelog ) );
        }

        $author      = (string) $software->getMeta( 'author' );
        $author_url  = (string) $software->getMeta( 'author_url' );
        $homepage    = (string) $software->getMeta( 'homepage' );
        $icon_id     = absint( $software->getMeta( 'icon' ) );
        $cover_id    = absint( $software->getMeta( 'cover_image' ) );
        $icon_url    = $icon_id ? wp_get_attachment_url( $icon_id ) : '';
        $cover_url   = $cover_id ? wp_get_attachment_url( $cover_id ) : '';

        $data = [
            'name'            => (string) $software->getName(),
            'author'          => $author,
            'author_homepage' => $author_url,
            'homepage'        => $homepage ?: home_url( '/' ),
            'requires'        => (string) $release->getMeta( 'requires_wp' ),
            'tested'          => (string) $release->getMeta( 'tested_wp' ),
            'requires_php'    => (string) $release->getMeta( 'requires_php' ),
            'last_updated'    => (string) $release->getCreatedAt(),
            'sections'        => $sections,
            'icons'           => [],
            'banners'         => [],
        ];

        if ( $icon_url ) {
            $data['icons'] = [ '1x' => $icon_url, '2x' => $icon_url, 'default' => $icon_url ];
        }
        if ( $cover_url ) {
            $data['banners'] = [ 'low' => $cover_url, 'high' => $cover_url ];
        }
        $upgrade_notice = (string) $release->getMeta( 'upgrade_notice' );
        if ( '' !== $upgrade_notice ) {
            $data['upgrade_notice'] = $upgrade_notice;
        }
        return $data;
    }

    protected static function normalize_uupd_channel( $channel, $allow ) {
        $channel = sanitize_key( (string) $channel );
        $valid = [ 'stable', 'dev', 'alpha', 'beta', 'rc', 'prerelease' ];
        if ( ! in_array( $channel, $valid, true ) ) {
            $channel = $allow ? 'prerelease' : 'stable';
        }
        if ( ! $allow ) {
            return 'stable';
        }
        return $channel;
    }

    /**
     * Return the newest prerelease permitted by the selected maturity floor.
     *
     * Channel semantics are "this maturity or safer":
     *   stable     => stable only (handled separately, so no prerelease here)
     *   rc         => rc
     *   beta       => beta + rc
     *   alpha      => alpha + beta + rc
     *   prerelease => alpha + beta + rc
     *
     * The stable release is always returned separately in metadata. UUPD then
     * compares stable_version with prerelease_version and selects whichever is
     * newer, so an allowed prerelease never masks a newer stable release.
     *
     * @param object $software DLM Pro Software entity.
     * @param string $channel  Normalized UUPD release channel.
     * @return object|null
     */
    protected static function best_prerelease( $software, $channel ) {
        $methods = [];

        switch ( $channel ) {
            case 'rc':
                $methods = [ 'getRcRelease' ];
                break;

            case 'beta':
                $methods = [ 'getBetaRelease', 'getRcRelease' ];
                break;

            case 'alpha':
            case 'prerelease':
                $methods = [ 'getAlphaRelease', 'getBetaRelease', 'getRcRelease' ];
                break;

            case 'dev':
            case 'stable':
            default:
                // DLM has no native dev channel. Stable is handled separately.
                return null;
        }

        $best = null;

        foreach ( $methods as $method ) {
            if ( ! method_exists( $software, $method ) ) {
                continue;
            }

            $release = $software->{$method}();
            if ( ! $release ) {
                continue;
            }

            if (
                ! $best ||
                version_compare(
                    self::normalize_version( $release->getVersion() ),
                    self::normalize_version( $best->getVersion() ),
                    '>'
                )
            ) {
                $best = $release;
            }
        }

        return $best;
    }

    protected static function normalize_version( $version ) {
        $v = trim( (string) $version );
        $v = preg_replace( '/\+.*$/', '', $v );
        return ltrim( $v, 'vV' );
    }

    protected static function download_url( $activation, $software, $release ) {
        return add_query_arg( [
            'software_id' => (int) $software->getId(),
            'release_id'  => (int) $release->getId(),
        ], rest_url( 'uupd/v1/dlm/download/' . rawurlencode( $activation->getToken() ) ) );
    }

    public static function handle_download_request( WP_REST_Request $request ) {
        if ( ! self::dependencies_ready() ) {
            return new WP_REST_Response( self::dependency_error(), 503 );
        }

        $token       = sanitize_text_field( (string) $request['token'] );
        $software_id = absint( $request->get_param( 'software_id' ) );
        $release_id  = absint( $request->get_param( 'release_id' ) );

        $activation = self::find_activation_by_token( $token );
        if ( ! $activation || ! is_null( $activation->getDeactivatedAt() ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'License activation is invalid.' ], 403 );
        }
        $license = $activation->getLicense();
        if ( ! $license || self::license_invalid_status( $license ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'License is invalid or expired.' ], 403 );
        }

        $software = self::resolve_software( '', $software_id, $license );
        if ( ! $software || (int) $software->getId() !== $software_id ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Software does not match this license.' ], 403 );
        }

        $release = \IdeoLogix\DigitalLicenseManagerPro\Database\Repositories\SoftwareReleases::instance()->find( $release_id );
        if ( ! $release || (int) $release->getSoftwareId() !== $software_id ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Release not found.' ], 404 );
        }

        if ( method_exists( $release, 'isDownloadAllowed' ) && ! $release->isDownloadAllowed( $license ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'This release is newer than the license entitlement period.' ], 403 );
        }

        $result = \IdeoLogix\DigitalLicenseManagerPro\Core\Storage\Manager::instance()->stream( $release );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 500 );
        }
        exit;
    }

    protected static function find_license( $license_key ) {
        $service = new \IdeoLogix\DigitalLicenseManager\Core\Services\LicensesService();
        return $service->find( $license_key );
    }

    protected static function license_invalid_status( $license ) {
        if ( ! $license ) {
            return 'invalid';
        }
        if ( method_exists( $license, 'isExpired' ) && $license->isExpired() ) {
            return 'expired';
        }
        $status = (int) $license->getStatus();
        if ( 5 === $status ) {
            return 'disabled';
        }
        // DLM statuses 1/2/3/4 can exist in legitimate commerce lifecycle states.
        return '';
    }

    protected static function find_activation_by_token( $token, $license_id = 0 ) {
        if ( '' === (string) $token ) {
            return null;
        }
        $query = [ 'token' => $token ];
        if ( $license_id ) {
            $query['license_id'] = (int) $license_id;
        }
        return \IdeoLogix\DigitalLicenseManager\Database\Repositories\LicenseActivations::instance()->findBy( $query );
    }

    protected static function find_activation_for_domain( $license, $domain ) {
        if ( ! $license || '' === (string) $domain ) {
            return null;
        }
        $items = $license->getActivations( [ 'active' => 1 ] );
        if ( ! is_array( $items ) && ! $items instanceof Traversable ) {
            return null;
        }
        $needle = self::normalize_domain( $domain );
        foreach ( $items as $activation ) {
            if ( ! $activation || ! is_null( $activation->getDeactivatedAt() ) ) {
                continue;
            }
            $label = self::normalize_domain( $activation->getLabel() );
            $meta  = $activation->getMetaData();
            $meta_domain = is_array( $meta ) && ! empty( $meta['domain'] ) ? self::normalize_domain( $meta['domain'] ) : '';
            if ( $needle === $label || $needle === $meta_domain ) {
                return $activation;
            }
        }
        return null;
    }

    protected static function resolve_software( $slug = '', $item_id = 0, $license = null ) {
        $repo = \IdeoLogix\DigitalLicenseManagerPro\Database\Repositories\Software::instance();

        if ( $item_id ) {
            $software = $repo->find( $item_id );
            if ( $software && self::software_matches_license( $software, $license ) ) {
                return $software;
            }
        }

        if ( $license && $license->getProductId() ) {
            $software = $repo->findByProduct( $license->getProductId() );
            if ( $software && ( '' === $slug || self::software_slug( $software ) === sanitize_key( $slug ) ) ) {
                return $software;
            }
        }

        $slug = sanitize_key( (string) $slug );
        if ( '' === $slug ) {
            return null;
        }
        foreach ( self::all_software() as $software ) {
            if ( self::software_slug( $software ) === $slug && self::software_matches_license( $software, $license ) ) {
                return $software;
            }
        }
        return null;
    }

    protected static function software_matches_license( $software, $license ) {
        if ( ! $license ) {
            return true;
        }
        $product_id = (int) $license->getProductId();
        if ( ! $product_id ) {
            return false;
        }
        $linked = \IdeoLogix\DigitalLicenseManagerPro\Database\Repositories\Software::instance()->findByProduct( $product_id );
        return $linked && (int) $linked->getId() === (int) $software->getId();
    }

    protected static function software_slug( $software ) {
        if ( ! $software ) {
            return '';
        }
        $override = (string) $software->getMeta( self::SLUG_META_KEY );
        return $override ? sanitize_key( $override ) : sanitize_title( $software->getName() );
    }

    protected static function all_software() {
        $repo = \IdeoLogix\DigitalLicenseManagerPro\Database\Repositories\Software::instance();
        $items = $repo->findAll();
        return is_array( $items ) ? $items : [];
    }

    protected static function sanitize_license_key( $key ) {
        return trim( sanitize_text_field( (string) $key ) );
    }

    protected static function normalize_domain( $domain ) {
        $domain = trim( strtolower( (string) $domain ) );
        if ( '' === $domain ) {
            return '';
        }
        if ( false === strpos( $domain, '://' ) ) {
            $domain = 'https://' . ltrim( $domain, '/' );
        }
        $host = wp_parse_url( $domain, PHP_URL_HOST );
        return $host ? strtolower( $host ) : trim( $domain, '/' );
    }

    public static function register_admin_page() {
        add_submenu_page( 'tools.php', 'UUPD Digital License Manager', 'UUPD DLM', 'manage_options', self::MENU_SLUG, [ __CLASS__, 'render_admin_page' ] );
    }

    protected static function admin_page_url( $tab = 'generator', array $args = [] ) {
        $query = array_merge( [ 'page' => self::MENU_SLUG ], $args );
        if ( 'generator' !== $tab ) {
            $query['tab'] = $tab;
        }
        return add_query_arg( $query, admin_url( 'tools.php' ) );
    }

    protected static function render_admin_tabs( $active = 'generator' ) {
        $tabs = [
            'generator' => 'Generator',
            'settings'  => 'Settings',
        ];
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px">';
        foreach ( $tabs as $tab => $label ) {
            $class = 'nav-tab' . ( $active === $tab ? ' nav-tab-active' : '' );
            echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::admin_page_url( $tab ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }

    protected static function default_settings() {
        return [
            'vendor'             => '',
            'updater_path'       => '/inc/updater.php',
            'license_ui_path'    => '/inc/class-uupd-license-ui.php',
            'allow_prerelease'   => 0,
            'release_channel'    => 'stable',
            'menu_parent'        => 'top_level',
            'menu_title_pattern' => '{name} License',
            'server_base'        => '',
            'explicit_server'    => 1,
        ];
    }

    protected static function get_settings() {
        $saved = get_option( self::OPTION_SETTINGS, [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        $settings = wp_parse_args( $saved, self::default_settings() );
        if ( empty( $settings['vendor'] ) ) {
            $legacy = sanitize_key( (string) get_option( self::OPTION_VENDOR, '' ) );
            if ( $legacy ) {
                $settings['vendor'] = $legacy;
            }
        }
        return $settings;
    }

    public static function save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'uupd_dlm_save_settings' );

        $channel = isset( $_POST['release_channel'] ) ? sanitize_key( wp_unslash( $_POST['release_channel'] ) ) : 'stable';
        if ( ! in_array( $channel, [ 'stable', 'dev', 'alpha', 'beta', 'rc', 'prerelease' ], true ) ) {
            $channel = 'stable';
        }

        $settings = [
            'vendor'             => isset( $_POST['vendor'] ) ? sanitize_key( wp_unslash( $_POST['vendor'] ) ) : '',
            'updater_path'       => self::sanitize_include_path( $_POST['updater_path'] ?? '/inc/updater.php' ),
            'license_ui_path'    => self::sanitize_include_path( $_POST['license_ui_path'] ?? '/inc/class-uupd-license-ui.php' ),
            'allow_prerelease'   => ! empty( $_POST['allow_prerelease'] ) ? 1 : 0,
            'release_channel'    => $channel,
            'menu_parent'        => isset( $_POST['menu_parent'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_parent'] ) ) : 'top_level',
            'menu_title_pattern' => isset( $_POST['menu_title_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_title_pattern'] ) ) : '{name} License',
            'server_base'        => isset( $_POST['server_base'] ) ? esc_url_raw( trim( wp_unslash( $_POST['server_base'] ) ) ) : '',
            'explicit_server'    => ! empty( $_POST['explicit_server'] ) ? 1 : 0,
        ];
        update_option( self::OPTION_SETTINGS, $settings, false );
        if ( $settings['vendor'] ) {
            update_option( self::OPTION_VENDOR, $settings['vendor'], false );
        }
        wp_safe_redirect( self::admin_page_url( 'settings', [ 'updated' => '1' ] ) );
        exit;
    }

    protected static function sanitize_include_path( $path ) {
        $path = trim( sanitize_text_field( wp_unslash( (string) $path ) ) );
        if ( '' === $path ) {
            return '';
        }
        return '/' . ltrim( $path, '/' );
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        $s = self::get_settings();
        ?>
        <div class="wrap">
            <h1>UUPD Digital License Manager</h1>
            <?php self::render_admin_tabs( 'settings' ); ?>
            <h2>Settings</h2>
            <?php if ( ! empty( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>UUPD DLM settings saved.</p></div><?php endif; ?>
            <p>These defaults are used by the DLM Software <strong>Copy UUPD</strong> button and by the generator.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="uupd_dlm_save_settings" />
                <?php wp_nonce_field( 'uupd_dlm_save_settings' ); ?>
                <table class="form-table"><tbody>
                    <tr><th><label for="vendor">Vendor slug</label></th><td><input class="regular-text" id="vendor" name="vendor" value="<?php echo esc_attr( $s['vendor'] ); ?>" placeholder="ffffff" /></td></tr>
                    <tr><th><label for="updater_path">Updater include path</label></th><td><input class="regular-text code" id="updater_path" name="updater_path" value="<?php echo esc_attr( $s['updater_path'] ); ?>" /><p class="description">Relative to the plugin main file directory, e.g. <code>/inc/updater.php</code>.</p></td></tr>
                    <tr><th><label for="license_ui_path">License UI include path</label></th><td><input class="regular-text code" id="license_ui_path" name="license_ui_path" value="<?php echo esc_attr( $s['license_ui_path'] ); ?>" /></td></tr>
                    <tr><th>Prereleases</th><td><label><input type="checkbox" name="allow_prerelease" value="1" <?php checked( ! empty( $s['allow_prerelease'] ) ); ?> /> Allow prerelease versions by default</label></td></tr>
                    <tr><th><label for="release_channel">Default release channel</label></th><td><select id="release_channel" name="release_channel"><?php foreach ( [ 'stable', 'dev', 'alpha', 'beta', 'rc', 'prerelease' ] as $ch ) : ?><option value="<?php echo esc_attr( $ch ); ?>" <?php selected( $s['release_channel'], $ch ); ?>><?php echo esc_html( $ch ); ?></option><?php endforeach; ?></select><p class="description">DLM has stable/alpha/beta/rc. UUPD dev currently falls back to stable.</p></td></tr>
                    <tr><th><label for="menu_parent">License menu parent</label></th><td><input class="regular-text" id="menu_parent" name="menu_parent" value="<?php echo esc_attr( $s['menu_parent'] ); ?>" /><p class="description"><code>top_level</code>, <code>options-general.php</code>, or another admin parent slug.</p></td></tr>
                    <tr><th><label for="menu_title_pattern">Menu/page title pattern</label></th><td><input class="regular-text" id="menu_title_pattern" name="menu_title_pattern" value="<?php echo esc_attr( $s['menu_title_pattern'] ); ?>" /><p class="description">Use <code>{name}</code> for the DLM Software name.</p></td></tr>
                    <tr><th><label for="server_base">Server base override</label></th><td><input class="regular-text code" id="server_base" name="server_base" value="<?php echo esc_attr( $s['server_base'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" /><p class="description">Leave blank to use this site's home URL.</p></td></tr>
                    <tr><th>Updater server line</th><td><label><input type="checkbox" name="explicit_server" value="1" <?php checked( ! empty( $s['explicit_server'] ) ); ?> /> Put the server URL explicitly into <code>UUPD_Updater_V2::register()</code></label><p class="description">The License UI will inject metadata_base anyway; keeping this enabled makes generated code self-explanatory.</p></td></tr>
                </tbody></table>
                <?php submit_button( 'Save UUPD Defaults' ); ?>
            </form>
        </div>
        <?php
    }

    public static function enqueue_dlm_admin_enhancements( $hook ) {
        if ( false === strpos( (string) $hook, 'dlm-software' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings_url = self::admin_page_url( 'settings' );
        wp_register_script( 'uupd-dlm-admin', false, [], self::VERSION, true );
        wp_enqueue_script( 'uupd-dlm-admin' );
        wp_localize_script( 'uupd-dlm-admin', 'UUPDDLMAdmin', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'uupd_dlm_copy_snippet' ),
            'settingsUrl' => $settings_url,
            'copyLabel'   => 'Copy UUPD',
            'copiedLabel' => 'Copied!',
        ] );
        $js = <<<'JS'
(function(){
    if (!window.UUPDDLMAdmin) return;
    function getIdFromHref(href) {
        var m = String(href || '').match(/#\/software\/(\d+)(?:\/|$)/);
        return m ? parseInt(m[1], 10) : 0;
    }
    async function copySnippet(id, btn) {
        if (!id || btn.dataset.busy === '1') return;
        btn.dataset.busy = '1';
        var old = btn.textContent;
        btn.textContent = '…';
        var snippet = '';
        try {
            var body = new URLSearchParams({action:'uupd_dlm_get_snippet', nonce:UUPDDLMAdmin.nonce, software_id:String(id)});
            var res = await fetch(UUPDDLMAdmin.ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:body.toString()});
            var json = await res.json();
            if (!json.success || !json.data || !json.data.snippet) throw new Error((json.data && json.data.message) || 'Unable to generate UUPD snippet.');
            snippet = json.data.snippet;
            await navigator.clipboard.writeText(snippet);
            btn.textContent = UUPDDLMAdmin.copiedLabel;
            setTimeout(function(){ btn.textContent = old; }, 1400);
        } catch (e) {
            if (snippet) { window.prompt('Copy UUPD bootstrap:', snippet); } else { window.alert(e && e.message ? e.message : 'Unable to generate UUPD snippet.'); }
            btn.textContent = old;
            console.error('[UUPD DLM]', e);
        } finally { btn.dataset.busy = '0'; }
    }
    function makeButton(id) {
        var b=document.createElement('button'); b.type='button'; b.className='dlm-btn dlm-btn-secondary dlm-btn-sm uupd-dlm-copy'; b.textContent=UUPDDLMAdmin.copyLabel; b.dataset.softwareId=String(id);
        b.style.marginLeft='8px'; b.addEventListener('click',function(ev){ev.preventDefault();ev.stopPropagation();copySnippet(id,b);}); return b;
    }
    function enhance() {
        document.querySelectorAll('#dlm-software a.dlm-link[href*="#/software/"]').forEach(function(a){
            var id=getIdFromHref(a.href); if(!id || a.parentElement.querySelector('.uupd-dlm-copy')) return; a.insertAdjacentElement('afterend', makeButton(id));
        });
        var hm=String(location.hash||'').match(/^#\/software\/(\d+)(?:\/|$)/);
        var actions=document.querySelector('#dlm-software .dlm-page-actions');
        if(actions && hm && !actions.querySelector('.uupd-dlm-copy')){
            var copy=makeButton(parseInt(hm[1],10));
            copy.classList.remove('dlm-btn-sm');
            copy.style.marginLeft='0';
            actions.insertBefore(copy, actions.firstChild);
        }
        if(actions && !actions.querySelector('.uupd-dlm-settings')){ var a=document.createElement('a'); a.className='dlm-btn dlm-btn-secondary uupd-dlm-settings'; a.href=UUPDDLMAdmin.settingsUrl; a.textContent='UUPD Settings'; var copyBtn=actions.querySelector('.uupd-dlm-copy'); if(copyBtn && copyBtn.nextSibling){actions.insertBefore(a,copyBtn.nextSibling);}else{actions.insertBefore(a, actions.firstChild);} }
    }
    var obs=new MutationObserver(enhance); obs.observe(document.getElementById('dlm-software')||document.body,{childList:true,subtree:true}); window.addEventListener('hashchange',function(){setTimeout(enhance,50)}); enhance();
})();
JS;
        wp_add_inline_script( 'uupd-dlm-admin', $js );
    }

    public static function ajax_get_snippet() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }
        check_ajax_referer( 'uupd_dlm_copy_snippet', 'nonce' );
        $id = isset( $_POST['software_id'] ) ? absint( $_POST['software_id'] ) : 0;
        $software = $id ? self::find_software_by_id( $id ) : null;
        if ( ! $software ) {
            wp_send_json_error( [ 'message' => 'Software not found.' ], 404 );
        }
        wp_send_json_success( [ 'snippet' => self::build_bootloader_snippet( $software ) ] );
    }

    protected static function find_software_by_id( $id ) {
        foreach ( self::all_software() as $software ) {
            if ( (int) $software->getId() === (int) $id ) {
                return $software;
            }
        }
        return null;
    }

    protected static function get_vendor() {
        $settings = self::get_settings();
        return sanitize_key( (string) ( $settings['vendor'] ?? '' ) );
    }

    protected static function current_version( $software ) {
        $stable = $software && method_exists( $software, 'getStableRelease' ) ? $software->getStableRelease() : null;
        return $stable ? (string) $stable->getVersion() : '0.0.0';
    }

    protected static function build_bootloader_snippet( $software ) {
        $settings = self::get_settings();
        $vendor   = self::get_vendor() ?: 'yourvendor';
        $slug     = self::software_slug( $software );
        $name     = (string) $software->getName();
        $version  = self::current_version( $software );
        $id       = (int) $software->getId();
        $server   = ! empty( $settings['server_base'] ) ? trailingslashit( $settings['server_base'] ) : trailingslashit( home_url( '/' ) );
        $prefix   = strtoupper( preg_replace( '/[^A-Za-z0-9]+/', '_', $vendor . '_' . $slug ) );
        $updater_path = self::sanitize_include_path( $settings['updater_path'] ?? '/inc/updater.php' );
        $ui_path      = self::sanitize_include_path( $settings['license_ui_path'] ?? '/inc/class-uupd-license-ui.php' );
        $allow        = ! empty( $settings['allow_prerelease'] ) ? 'true' : 'false';
        $channel      = in_array( $settings['release_channel'] ?? 'stable', [ 'stable', 'dev', 'alpha', 'beta', 'rc', 'prerelease' ], true ) ? $settings['release_channel'] : 'stable';
        $menu_parent  = (string) ( $settings['menu_parent'] ?? 'top_level' );
        $title        = str_replace( '{name}', $name, (string) ( $settings['menu_title_pattern'] ?? '{name} License' ) );
        $esc = static function( $v ) { return str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], (string) $v ); };

        $server_line = ! empty( $settings['explicit_server'] )
            ? "        'server'           => UUPD_{$prefix}_SERVER_BASE,"
            : "        'server'           => '', // License UI injects metadata_base";

        return implode( "\n", [
            "define( 'UUPD_{$prefix}_VENDOR', '" . $esc( $vendor ) . "' );",
            "define( 'UUPD_{$prefix}_VERSION', '" . $esc( $version ) . "' );",
            "define( 'UUPD_{$prefix}_MAIN_FILE', __FILE__ );",
            "define( 'UUPD_{$prefix}_ITEM_NUMBER', {$id} ); // DLM Pro software ID",
            "define( 'UUPD_{$prefix}_FRIENDLY_NAME', '" . $esc( $name ) . "' );",
            "define( 'UUPD_{$prefix}_SLUG', '" . $esc( $slug ) . "' );",
            "define( 'UUPD_{$prefix}_SERVER_BASE', '" . $esc( untrailingslashit( $server ) ) . "' );",
            "define( 'UUPD_{$prefix}_MENU_SLUG', UUPD_{$prefix}_VENDOR . '-' . UUPD_{$prefix}_SLUG );",
            '',
            "add_action( 'plugins_loaded', function() {",
            "    require_once __DIR__ . '" . $esc( $updater_path ) . "';",
            "    require_once __DIR__ . '" . $esc( $ui_path ) . "';",
            '',
            "    \UUPD\UI\License\V1\UUPD_License_UI::register([",
            "        'vendor'         => UUPD_{$prefix}_VENDOR,",
            "        'slug'           => UUPD_{$prefix}_SLUG,",
            "        'item_id'        => UUPD_{$prefix}_ITEM_NUMBER,",
            "        'license_server' => UUPD_{$prefix}_SERVER_BASE,",
            "        'metadata_base'  => UUPD_{$prefix}_SERVER_BASE,",
            "        'plugin_name'    => UUPD_{$prefix}_FRIENDLY_NAME,",
            "        'menu_parent'    => '" . $esc( $menu_parent ) . "',",
            "        'menu_slug'      => UUPD_{$prefix}_MENU_SLUG,",
            "        'page_title'     => '" . $esc( $title ) . "',",
            "        'menu_title'     => '" . $esc( $title ) . "',",
            "        'capability'     => 'manage_options',",
            "    ]);",
            '',
            "    \UUPD\V2\UUPD_Updater_V2::register([",
            "        'vendor'           => UUPD_{$prefix}_VENDOR,",
            "        'plugin_file'      => plugin_basename( UUPD_{$prefix}_MAIN_FILE ),",
            "        'slug'             => UUPD_{$prefix}_SLUG,",
            "        'name'             => UUPD_{$prefix}_FRIENDLY_NAME,",
            "        'version'          => UUPD_{$prefix}_VERSION,",
            $server_line,
            "        'allow_prerelease' => {$allow},",
            "        'release_channel'  => '" . $esc( $channel ) . "',",
            "    ]);",
            "}, 1 );",
        ] );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'generator';
        if ( 'settings' === $tab ) {
            self::render_settings_page();
            return;
        }

        $items    = self::dependencies_ready() ? self::all_software() : [];
        $selected = isset( $_GET['software_id'] ) ? absint( $_GET['software_id'] ) : 0;
        $software = null;
        foreach ( $items as $row ) {
            if ( (int) $row->getId() === $selected ) { $software = $row; break; }
        }
        $snippet = $software ? self::build_bootloader_snippet( $software ) : '';
        ?>
        <div class="wrap">
            <h1>UUPD Digital License Manager</h1>
            <?php self::render_admin_tabs( 'generator' ); ?>
            <h2>Generator</h2>
            <p>Maps DLM licensing and DLM Pro software releases into the UUPD license/metadata contract. Stable, alpha, beta and RC releases are read from DLM Pro; UUPD <code>dev</code> has no native DLM equivalent.</p>
            <?php if ( ! self::dependencies_ready() ) : ?>
                <div class="notice notice-error"><p>Digital License Manager and Digital License Manager Pro must both be active.</p></div>
            <?php endif; ?>
            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                <table class="form-table"><tbody>
                    <tr><th><label for="software_id">DLM software</label></th><td><select id="software_id" name="software_id"><option value="">Select software…</option><?php foreach ( $items as $row ) : ?><option value="<?php echo (int) $row->getId(); ?>" <?php selected( $selected, (int) $row->getId() ); ?>><?php echo esc_html( $row->getName() . ' (#' . $row->getId() . ')' ); ?></option><?php endforeach; ?></select></td></tr>
                </tbody></table>
                <?php submit_button( 'Generate' ); ?>
            </form>
            <?php if ( $software ) : ?>
                <h2>Resolved shape</h2>
                <table class="widefat striped" style="max-width:900px"><tbody>
                    <tr><th>Software ID</th><td><code><?php echo (int) $software->getId(); ?></code></td></tr>
                    <tr><th>UUPD slug</th><td><code><?php echo esc_html( self::software_slug( $software ) ); ?></code></td></tr>
                    <tr><th>Stable</th><td><code><?php echo esc_html( self::current_version( $software ) ); ?></code></td></tr>
                    <tr><th>Alpha</th><td><code><?php $r=$software->getAlphaRelease(); echo esc_html( $r ? $r->getVersion() : '—' ); ?></code></td></tr>
                    <tr><th>Beta</th><td><code><?php $r=$software->getBetaRelease(); echo esc_html( $r ? $r->getVersion() : '—' ); ?></code></td></tr>
                    <tr><th>RC</th><td><code><?php $r=$software->getRcRelease(); echo esc_html( $r ? $r->getVersion() : '—' ); ?></code></td></tr>
                    <tr><th>Metadata server</th><td><code><?php echo esc_html( home_url( '/' ) ); ?></code></td></tr>
                </tbody></table>
                <h2>Bootloader snippet</h2>
                <textarea class="large-text code" rows="30" readonly><?php echo esc_textarea( $snippet ); ?></textarea>
            <?php endif; ?>
        </div>
        <?php
    }
}

add_action( 'plugins_loaded', [ 'UUPD_DLM_Compatibility_Layer', 'init' ], 20 );
}
