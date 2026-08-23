<?php
/**
 * UUPD_License_UI – Reusable vendor-aware license activation and status UI.
 *
 * This class is intended to be bundled inside WordPress plugins that use the
 * UUPD licensing and updater flow. It supports both a standalone admin page
 * and a compact inline license box, while using vendor + slug scoped identity
 * so separately bundled copies are less likely to collide at runtime.
 *
 * --------------------------------------------------------------------------
 *  OVERVIEW
 * --------------------------------------------------------------------------
 *
 * Responsibilities handled by this class:
 *
 * - Render a standalone license management page.
 * - Render a reusable inline license box for settings screens.
 * - Store and update license state in wp_options.
 * - Resolve license keys from admin input, wp-config.php, or stored options.
 * - Call UUPD Server license activate, deactivate, and check endpoints.
 * - Inject the active resolved key into the vendor-aware updater config.
 * - Flush updater transients after license changes or sync events.
 * - Run opportunistic and cron-based license checks.
 * - Suppress writable UI when a license is code-managed via wp-config.php.
 *
 * --------------------------------------------------------------------------
 *  VENDOR + SLUG IDENTITY MODEL
 * --------------------------------------------------------------------------
 *
 * This class assumes the updater identity model is vendor-aware.
 * A stable `vendor` and `slug` are both required when registering.
 *
 * Example:
 *
 *   vendor = tdlab
 *   slug   = example-plugin
 *
 * Derived identifiers:
 *
 *   instance_key      = tdlab__example_plugin
 *   option_name       = uupd_license_tdlab__example_plugin
 *   menu_slug         = uupd_license_tdlab__example_plugin
 *   cron_hook         = uupd_license_check_tdlab__example_plugin
 *   cache_prefix      = uupd_tdlab__
 *   license_constant  = UUPD_TDLAB_EXAMPLE_PLUGIN_LICENSE_KEY
 *
 * The updater and this UI should be registered with the same vendor and slug.
 *
 * --------------------------------------------------------------------------
 *  LICENSE KEY SOURCES AND PRECEDENCE
 * --------------------------------------------------------------------------
 *
 * The effective license key is resolved in this order:
 *
 *   1) An explicit runtime override passed to resolve_license_key()
 *   2) A wp-config.php constant, when defined and preferred
 *   3) The stored wp_options value
 *
 * By default, a wp-config.php constant overrides the database value.
 *
 * Default constant name format:
 *
 *   UUPD_{VENDOR}_{SLUG}_LICENSE_KEY
 *
 * Example:
 *
 *   define( 'UUPD_TDLAB_EXAMPLE_PLUGIN_LICENSE_KEY', 'XXXX-XXXX-XXXX-XXXX' );
 *
 * You may override the default constant name by passing `license_constant`
 * into register().
 *
 * When a license is managed via wp-config.php:
 *
 * - The admin UI becomes read-only.
 * - Activation and deactivation buttons are hidden.
 * - The stored option does not persist the raw license key.
 * - Admin nags are suppressed when a constant-managed key is present.
 * - The updater receives the resolved key through the vendor-aware filter path.
 *
 * --------------------------------------------------------------------------
 *  REQUIRED CONFIG
 * --------------------------------------------------------------------------
 *
 * register() requires at minimum:
 *
 * - vendor
 * - slug
 * - item_id
 * - license_server
 * - metadata_base
 *
 * Common optional config:
 *
 * - plugin_name
 * - option_name
 * - menu_parent
 * - menu_slug
 * - page_title
 * - menu_title
 * - capability
 * - icon_url
 * - position
 * - cache_prefix
 * - pass_token
 * - token_param
 * - license_constant
 * - prefer_constant
 * - instance_key
 * - cron_hook
 *
 * --------------------------------------------------------------------------
 *  UUPD SERVER ENDPOINTS
 * --------------------------------------------------------------------------
 *
 * This UI communicates with the UUPD Server REST API:
 *
 *   POST /wp-json/uupd/v1/license/activate
 *   POST /wp-json/uupd/v1/license/deactivate
 *   POST /wp-json/uupd/v1/license/check
 *
 * Payload:
 *
 *   {
 *     "license_key": "<string>",
 *     "slug":        "<string>",
 *     "domain":      "https://example.com"
 *   }
 *
 * Stored option shape:
 *
 *   option_name = uupd_license_{vendor}__{slug}
 *
 * Response data is stored alongside status, timestamps, and last error state.
 *
 * --------------------------------------------------------------------------
 *  UPDATER INTEGRATION
 * --------------------------------------------------------------------------
 *
 * This class integrates with the vendor-aware UUPD updater filter hierarchy.
 *
 * Primary scoped filters used by this class:
 *
 *   uupd/server_url/{vendor}/{slug}
 *   uupd/filter_config/{vendor}/{slug}
 *
 * The injected updater config includes:
 *
 * - the correct metadata base or server URL
 * - the resolved effective license key
 * - the key only when the stored status is active
 *
 * Slug-only compatibility paths are intentionally not implemented here.
 * This file is designed for vendor-aware registrations only.
 *
 * --------------------------------------------------------------------------
 *  USAGE EXAMPLE
 * --------------------------------------------------------------------------
 *
 *   use UUPD\V1\UUPD_License_UI;
 *
 *   UUPD_License_UI::register( [
 *       'vendor'         => 'tdlab',
 *       'slug'           => 'example-plugin',
 *       'item_id'        => 7,
 *       'plugin_name'    => 'Example Plugin',
 *       'license_server' => 'https://updates.example.com',
 *       'metadata_base'  => 'https://updates.example.com',
 *   ] );
 *
 *   define( 'UUPD_TDLAB_EXAMPLE_PLUGIN_LICENSE_KEY', 'XXXX-XXXX-XXXX-XXXX' );
 *
 * --------------------------------------------------------------------------
 *  BUNDLING NOTES
 * --------------------------------------------------------------------------
 *
 * This class still uses the namespace UUPD\V1 for distribution continuity.
 * That namespace does not imply slug-only updater behavior.
 *
 * Vendor-aware IDs reduce runtime collisions, but if multiple incompatible
 * bundled copies may load in the same request, build-time namespace prefixing
 * is still the safest isolation strategy.
 *
 * @package UUPD\UI\License\V1
 */

namespace UUPD\UI\License\V1;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\UUPD_License_UI' ) ) {

    class UUPD_License_UI {

        const VERSION = '1.0.0';

        /** @var array<string,self> */
        protected static $instances = [];

        /** @var bool */
        protected static $inline_box_css_printed = false;

        /** @var bool */
        protected static $inline_box_js_printed = false;

        /** @var array */
        protected $config;

        /**
         * Build a stable instance key from vendor + slug.
         *
         * @param string $vendor
         * @param string $slug
         * @return string
         */
        protected static function build_instance_key( $vendor, $slug ) {
            return sanitize_key( $vendor . '__' . str_replace( '-', '_', $slug ) );
        }

        /**
         * Normalize a vendor token for internal IDs.
         *
         * @param string $vendor
         * @return string
         */
        protected static function normalize_vendor_token( $vendor ) {
            return sanitize_key( str_replace( '-', '_', (string) $vendor ) );
        }

        /**
         * Normalize a slug token for internal IDs.
         *
         * @param string $slug
         * @return string
         */
        protected static function normalize_slug_token( $slug ) {
            return sanitize_key( str_replace( '-', '_', (string) $slug ) );
        }

        /**
         * Convert a token into an upper constant-safe fragment.
         *
         * @param string $value
         * @return string
         */
        protected static function constant_fragment( $value ) {
            $value = strtoupper( str_replace( '-', '_', (string) $value ) );
            return preg_replace( '/[^A-Z0-9_]/', '_', $value );
        }

        /**
         * Register a license UI instance.
         *
         * @param array $config
         * @return self|null
         */
        public static function register( array $config ) {
            $defaults = [
                'vendor'             => '',
                'slug'               => '',
                'item_id'            => 0,
                'license_server'     => '',
                'metadata_base'      => '',
                'plugin_name'        => '',
                'option_name'        => '',
                'menu_parent'        => 'options-general.php',
                'menu_slug'          => '',
                'page_title'         => '',
                'menu_title'         => '',
                'license_provider'   => 'uupd', // uupd|surecart
                'capability'         => 'manage_options',
                'icon_url'           => '',
                'position'           => null,
                'cache_prefix'       => '',
                'pass_token'         => true,
                'token_param'        => 'token',
                'license_constant'   => '',
                'prefer_constant'    => true,
                'instance_key'       => '',
                'cron_hook'          => '',
            ];

            $config = wp_parse_args( $config, $defaults );

            if ( empty( $config['vendor'] ) ) {
                _doing_it_wrong( __METHOD__, __( 'Missing vendor in UUPD_License_UI::register()', 'default' ), self::VERSION );
                return null;
            }

            if ( empty( $config['slug'] ) ) {
                _doing_it_wrong( __METHOD__, __( 'Missing slug in UUPD_License_UI::register()', 'default' ), self::VERSION );
                return null;
            }

            if ( empty( $config['item_id'] ) ) {
                _doing_it_wrong( __METHOD__, __( 'Missing item_id in UUPD_License_UI::register()', 'default' ), self::VERSION );
                return null;
            }

            if ( empty( $config['license_server'] ) ) {
                _doing_it_wrong( __METHOD__, __( 'Missing license_server in UUPD_License_UI::register()', 'default' ), self::VERSION );
                return null;
            }

            if ( empty( $config['metadata_base'] ) ) {
                _doing_it_wrong( __METHOD__, __( 'Missing metadata_base in UUPD_License_UI::register()', 'default' ), self::VERSION );
                return null;
            }

            $vendor = self::normalize_vendor_token( $config['vendor'] );
            $slug   = sanitize_key( $config['slug'] );

            if ( empty( $config['plugin_name'] ) ) {
                $config['plugin_name'] = $slug;
            }

            $instance_key = self::build_instance_key( $vendor, $slug );

            if ( empty( $config['option_name'] ) ) {
                $config['option_name'] = 'uupd_license_' . $instance_key;
            }

            if ( empty( $config['menu_slug'] ) ) {
                $config['menu_slug'] = 'uupd_license_' . $instance_key;
            }

            if ( empty( $config['page_title'] ) ) {
                $config['page_title'] = sprintf( __( '%s License', 'default' ), $config['plugin_name'] );
            }

            if ( empty( $config['menu_title'] ) ) {
                $config['menu_title'] = __( 'License', 'default' );
            }

            if ( empty( $config['cache_prefix'] ) ) {
                $config['cache_prefix'] = 'uupd_' . $vendor . '__';
            }

            if ( empty( $config['license_constant'] ) ) {
                $config['license_constant'] = sprintf(
                    'UUPD_%s_%s_LICENSE_KEY',
                    self::constant_fragment( $vendor ),
                    self::constant_fragment( $slug )
                );
            }

            if ( empty( $config['cron_hook'] ) ) {
                $config['cron_hook'] = 'uupd_license_check_' . $instance_key;
            }

            $config['vendor']       = $vendor;
            $config['slug']         = $slug;
            $config['instance_key'] = $instance_key;

            if ( isset( self::$instances[ $instance_key ] ) ) {
                return self::$instances[ $instance_key ];
            }

            $instance = new self( $config );
            self::$instances[ $instance_key ] = $instance;

            return $instance;
        }

        /**
         * Get an already-registered instance.
         *
         * @param string $vendor
         * @param string $slug
         * @return self|null
         */
        public static function get_instance( $vendor, $slug ) {
            $vendor = self::normalize_vendor_token( $vendor );
            $slug   = sanitize_key( $slug );
            $key    = self::build_instance_key( $vendor, $slug );

            return isset( self::$instances[ $key ] ) ? self::$instances[ $key ] : null;
        }

        /**
         * Constructor.
         *
         * @param array $config
         */
        public function __construct( array $config ) {
            $this->config = $config;

            if ( ! empty( $this->config['menu_parent'] ) && $this->config['menu_parent'] !== false ) {
                add_action( 'admin_menu', [ $this, 'register_menu' ] );
            }

            add_action( 'admin_post_uupd_license_action', [ __CLASS__, 'handle_form_post' ] );
            add_action( 'admin_notices', [ $this, 'maybe_show_admin_notice' ] );
            add_action( 'admin_init', [ $this, 'maybe_sync_constant_managed_license' ] );

           add_filter(
			    'uupd/server_url/' . $this->config['vendor'] . '/' . $this->config['slug'],
			    [ __CLASS__, 'filter_server_url_vendorized' ],
			    10,
			    4
			);

			add_filter(
			    'uupd/filter_config/' . $this->config['vendor'] . '/' . $this->config['slug'],
			    [ __CLASS__, 'filter_updater_config_vendorized' ],
			    10,
			    4
			);
            add_action( $this->config['cron_hook'], [ $this, 'cron_check_license' ] );
        }

        /**
         * Register the admin menu/page.
         */
        public function register_menu() {
            $c = $this->config;

            if ( $c['menu_parent'] === 'top_level' ) {
                add_menu_page(
                    $c['page_title'],
                    $c['menu_title'],
                    $c['capability'],
                    $c['menu_slug'],
                    [ $this, 'render_page' ],
                    $c['icon_url'],
                    $c['position']
                );
            } else {
                $parent = $c['menu_parent'] ? $c['menu_parent'] : 'options-general.php';

                add_submenu_page(
                    $parent,
                    $c['page_title'],
                    $c['menu_title'],
                    $c['capability'],
                    $c['menu_slug'],
                    [ $this, 'render_page' ]
                );
            }
        }

        /**
         * Render the main license management page.
         */
        public function render_page() {
            if ( ! current_user_can( $this->config['capability'] ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'default' ) );
            }

            $c       = $this->config;
            $slug    = $c['slug'];
            $vendor  = $c['vendor'];
            $option  = get_option( $c['option_name'], [] );
            $status  = isset( $option['status'] ) ? $option['status'] : 'inactive';

            $license        = $this->resolve_license_key();
            $license_masked = $license ? $this->mask_license_key( $license ) : '';
            $is_const       = $this->license_is_constant_managed();
            $is_active      = ( strtolower( $status ) === 'active' );

            $status_label = $is_active ? __( 'Active', 'default' ) : __( 'Inactive', 'default' );
            $status_class = $is_active ? 'uupd-license-status--active' : 'uupd-license-status--inactive';

            $last_response = isset( $option['last_response'] ) && is_array( $option['last_response'] )
                ? $option['last_response']
                : [];

            $last_checked = ! empty( $option['last_check'] )
                ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $option['last_check'] )
                : __( 'Never', 'default' );

            $last_error = isset( $option['last_error'] ) ? $option['last_error'] : '';
            $expires = isset( $last_response['date_expires'] ) ? $last_response['date_expires'] : ( $last_response['expiry'] ?? '' );
            $activations = isset( $last_response['activations'] ) ? (int) $last_response['activations'] : null;
            $max_activations = isset( $last_response['max_activations'] ) ? (int) $last_response['max_activations']
                : ( isset( $last_response['activation_limit'] ) ? (int) $last_response['activation_limit'] : null );

            $host = parse_url( home_url(), PHP_URL_HOST );
            ?>
            <div class="wrap">
                <h1><?php echo esc_html( $c['page_title'] ); ?></h1>

                <style>
                <?php
                $raw_css = '
                .uupd-root .uupd-license-wrap {
                    max-width: 800px;
                    margin-top: 20px;
                }
                .uupd-root .uupd-license-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 20px;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                }
                .uupd-root .uupd-license-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 10px;
                }
                .uupd-root .uupd-license-title {
                    margin: 0;
                    font-size: 18px;
                }
                .uupd-root .uupd-license-status {
                    padding: 4px 10px;
                    border-radius: 999px;
                    font-size: 12px;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                }
                .uupd-root .uupd-license-status--active {
                    background: #e3f7e5;
                    color: #22863a;
                    border: 1px solid #34a853;
                }
                .uupd-root .uupd-license-status--inactive {
                    background: #fff5f5;
                    color: #d93025;
                    border: 1px solid #ea4335;
                }
                .uupd-root .uupd-license-body {
                    margin-top: 8px;
                }
                .uupd-root .uupd-license-field {
                    margin-bottom: 16px;
                }
                .uupd-root .uupd-license-field label {
                    display: block;
                    font-weight: 500;
                    margin-bottom: 4px;
                }
                .uupd-root .uupd-license-field input[type="text"] {
                    width: 100%;
                    max-width: 380px;
                }
                .uupd-root .uupd-license-meta {
                    display: flex;
                    flex-wrap: wrap;
                    justify-content: space-between;
                    align-items: center;
                    margin-top: 12px;
                    font-size: 12px;
                    color: #666;
                }
                .uupd-root .uupd-license-actions .button {
                    margin-right: 6px;
                }
                .uupd-root .uupd-license-meta small {
                    display: block;
                }
                .uupd-root .uupd-license-error {
                    margin-top: 10px;
                    padding: 8px 10px;
                    border-radius: 3px;
                    background: #fff5f5;
                    border: 1px solid #ea4335;
                    color: #d93025;
                }
                .uupd-root .uupd-license-managed-note {
                    margin: 8px 0 0;
                    color: #555;
                }
                ';

                $filtered_css = apply_filters( 'uupd/license_ui/page_css', $raw_css, $slug, $this );
                echo $filtered_css ? $filtered_css : '';
                ?>
                </style>

                <div class="uupd-root" data-uupd-vendor="<?php echo esc_attr( $vendor ); ?>" data-uupd-slug="<?php echo esc_attr( $slug ); ?>">
                    <div class="uupd-license-wrap">
                        <div class="uupd-license-card">
                            <div class="uupd-license-header">
                                <h2 class="uupd-license-title"><?php echo esc_html( $c['plugin_name'] ); ?></h2>
                                <div class="uupd-license-status <?php echo esc_attr( $status_class ); ?>">
                                    <?php echo esc_html( $status_label ); ?>
                                </div>
                            </div>

                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uupd-license-body">
                                <?php wp_nonce_field( 'uupd_license_action_' . $c['instance_key'], 'uupd_license_nonce' ); ?>
                                <input type="hidden" name="action" value="uupd_license_action" />
                                <input type="hidden" name="uupd_vendor" value="<?php echo esc_attr( $vendor ); ?>" />
                                <input type="hidden" name="uupd_slug" value="<?php echo esc_attr( $slug ); ?>" />

                                <div class="uupd-license-field">
                                    <?php if ( $is_const ) : ?>
                                        <p class="description">
                                            <?php
                                            printf(
                                                esc_html__( 'License key is managed via wp-config.php (%s).', 'default' ),
                                                esc_html( $c['license_constant'] )
                                            );
                                            ?>
                                        </p>
                                        <?php if ( $license_masked ) : ?>
                                            <p class="description">
                                                <?php printf( esc_html__( 'Current key: %s', 'default' ), esc_html( $license_masked ) ); ?>
                                            </p>
                                        <?php endif; ?>
                                    <?php elseif ( ! $is_active ) : ?>
                                        <label for="uupd_license_key"><?php esc_html_e( 'License Key', 'default' ); ?></label>
                                        <input
                                            type="text"
                                            id="uupd_license_key"
                                            name="uupd_license_key"
                                            class="regular-text"
                                            value=""
                                            placeholder="<?php esc_attr_e( 'Enter your license key', 'default' ); ?>"
                                        />
                                        <?php if ( $license_masked ) : ?>
                                            <p class="description">
                                                <?php printf( esc_html__( 'Current key: %s', 'default' ), esc_html( $license_masked ) ); ?>
                                            </p>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <p><?php esc_html_e( 'Your license is active.', 'default' ); ?></p>
                                        <?php if ( $license_masked ) : ?>
                                            <p class="description">
                                                <?php printf( esc_html__( 'Current key: %s', 'default' ), esc_html( $license_masked ) ); ?>
                                            </p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <div class="uupd-license-actions">
                                    <?php if ( ! $is_const && ! $is_active ) : ?>
                                        <button type="submit" name="uupd_action" value="activate" class="button button-primary">
                                            <?php esc_html_e( 'Activate License', 'default' ); ?>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ( ! $is_const && $is_active ) : ?>
                                        <button type="submit" name="uupd_action" value="deactivate" class="button">
                                            <?php esc_html_e( 'Deactivate License', 'default' ); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <?php if ( $is_const ) : ?>
                                    <p class="uupd-license-managed-note">
                                        <?php esc_html_e( 'This license is managed by code and cannot be changed from the admin UI.', 'default' ); ?>
                                    </p>
                                <?php endif; ?>

                                <?php if ( $last_error ) : ?>
                                    <div class="uupd-license-error"><?php echo esc_html( $last_error ); ?></div>
                                <?php endif; ?>

                                <div class="uupd-license-meta">
                                    <div>
                                        <small><?php printf( esc_html__( 'Vendor: %s', 'default' ), esc_html( $vendor ) ); ?></small>
                                        <small><?php printf( esc_html__( 'Site: %s', 'default' ), esc_html( $host ) ); ?></small>
                                        <small><?php printf( esc_html__( 'Last checked: %s', 'default' ), esc_html( $last_checked ) ); ?></small>
                                    </div>
                                    <div>
                                        <?php if ( $expires ) : ?>
                                            <small><?php printf( esc_html__( 'Expires: %s', 'default' ), esc_html( $expires ) ); ?></small>
                                        <?php endif; ?>
                                        <?php if ( $activations !== null && $max_activations !== null ) : ?>
                                            <small><?php printf( esc_html__( 'Activations: %1$d / %2$d', 'default' ), $activations, $max_activations ); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * Global admin notice if license is missing/invalid/expired.
         */
        public function maybe_show_admin_notice() {
            if ( ! current_user_can( $this->config['capability'] ) ) {
                return;
            }

            $c      = $this->config;
            $option = get_option( $c['option_name'], [] );
            $status = isset( $option['status'] ) ? $option['status'] : 'inactive';

            $license  = $this->resolve_license_key();
            $is_const = $this->license_is_constant_managed();

            if ( $is_const && $license !== '' ) {
                return;
            }

            $status_normalized = strtolower( (string) $status );

            $license_array = [
                'status'           => $status_normalized,
                'license'          => $license,
                'option'           => $option,
                'constant_managed' => $is_const,
                'vendor'           => $c['vendor'],
                'slug'             => $c['slug'],
                'instance_key'     => $c['instance_key'],
            ];

            $show = true;
            $show = apply_filters( 'uupd/license_ui/show_notice/' . $c['slug'], $show, $license_array, $this );
            $show = apply_filters( 'uupd/license_ui/show_notice/' . $c['vendor'] . '/' . $c['slug'], $show, $license_array, $this );
            $show = apply_filters( 'uupd/license_ui/show_notice', $show, $c['slug'], $license_array, $this );

            if ( ! $show || $status_normalized === 'active' || ! is_admin() ) {
                return;
            }

            $message = sprintf(
                __( 'Please activate your %s license to enable updates and support.', 'default' ),
                $c['plugin_name']
            );

            $url = add_query_arg(
                [ 'page' => $this->config['menu_slug'] ],
                admin_url( $this->config['menu_parent'] === 'options-general.php' || empty( $this->config['menu_parent'] )
                    ? 'options-general.php'
                    : 'admin.php'
                )
            );
            ?>
            <div class="notice notice-warning">
                <p>
                    <?php echo esc_html( $message ); ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="button button-primary" style="margin-left: 10px;">
                        <?php esc_html_e( 'Activate License', 'default' ); ?>
                    </a>
                </p>
            </div>
            <?php
        }

        /**
         * Handle form POST from the license page or inline license box.
         */
        public static function handle_form_post() {
            $vendor = isset( $_POST['uupd_vendor'] ) ? self::normalize_vendor_token( wp_unslash( $_POST['uupd_vendor'] ) ) : '';
            $slug   = isset( $_POST['uupd_slug'] ) ? sanitize_key( wp_unslash( $_POST['uupd_slug'] ) ) : '';

            if ( ! $slug ) {
                wp_die( esc_html__( 'Missing slug.', 'default' ) );
            }

            if ( ! $vendor ) {
                wp_die( esc_html__( 'Missing vendor.', 'default' ) );
            }

            $inst = self::get_instance( $vendor, $slug );

            if ( ! $inst ) {
                wp_die( esc_html__( 'License handler not found.', 'default' ) );
            }

            if ( ! current_user_can( $inst->config['capability'] ) ) {
                wp_die( esc_html__( 'You do not have permission to perform this action.', 'default' ) );
            }

            if ( ! isset( $_POST['uupd_license_nonce'] )
                || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uupd_license_nonce'] ) ), 'uupd_license_action_' . $inst->config['instance_key'] ) ) {
                wp_die( esc_html__( 'Invalid nonce.', 'default' ) );
            }

            $action = isset( $_POST['uupd_action'] ) ? sanitize_text_field( wp_unslash( $_POST['uupd_action'] ) ) : '';
            $key    = isset( $_POST['uupd_license_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['uupd_license_key'] ) ) ) : '';

            try {
                $inst->process_action( $action, $key );
            } catch ( \Throwable $e ) {
                $option = get_option( $inst->config['option_name'], [] );

                if ( ! is_array( $option ) ) {
                    $option = [];
                }

                $option['status']     = 'inactive';
                $option['last_error'] = 'Activation failed: ' . $e->getMessage();
                $option['last_check'] = time();

                update_option( $inst->config['option_name'], $option, false );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[UUPD License Fatal] ' . $e->getMessage() );
                    error_log( $e->getTraceAsString() );
                }
            }

            $default_redirect = add_query_arg(
                [ 'page' => $inst->config['menu_slug'] ],
                admin_url( $inst->config['menu_parent'] === 'options-general.php' || empty( $inst->config['menu_parent'] )
                    ? 'options-general.php'
                    : 'admin.php'
                )
            );

            $redirect_raw = isset( $_POST['uupd_redirect'] ) ? wp_unslash( $_POST['uupd_redirect'] ) : '';
            $redirect = $redirect_raw ? wp_validate_redirect( $redirect_raw, $default_redirect ) : $default_redirect;

            wp_safe_redirect( $redirect );
            exit;
        }

        /**
         * Process activate/deactivate actions.
         *
         * @param string $action
         * @param string $license_key
         */
        protected function process_action( $action, $license_key ) {
            $c = $this->config;
            $option_name = $c['option_name'];

            $this->log( sprintf( 'Using UUPD_License_UI version %s', self::VERSION ) );

            if ( $action === 'activate' ) {
                if ( empty( $license_key ) ) {
                    delete_option( $option_name );
                    return;
                }

                $this->log( 'Calling activate endpoint.', [ 'license_key_masked' => $this->mask_license_key( $license_key ) ] );
                $result = $this->call_license_endpoint( $license_key, 'activate', home_url() );
                $this->log( 'Activate endpoint result', [ 'code' => isset( $result['code'] ) ? $result['code'] : null ] );
                $this->update_option_from_response( $license_key, $result, 'activate' );

            } elseif ( $action === 'deactivate' ) {
                if ( $this->license_is_constant_managed() ) {
                    $this->update_option_from_response( $this->resolve_license_key(), [
                        'code' => 400,
                        'data' => [
                            'success' => false,
                            'message' => __( 'License key is defined in wp-config.php and cannot be deactivated here.', 'default' ),
                        ],
                    ], 'deactivate' );
                    return;
                }

                $key = $this->resolve_license_key();

                if ( ! $key ) {
                    delete_option( $option_name );
                    return;
                }

                $this->log( 'Calling deactivate endpoint.', [ 'license_key_masked' => $this->mask_license_key( $key ) ] );
                $result = $this->call_license_endpoint( $key, 'deactivate', home_url() );
                $this->log( 'Deactivate endpoint result', [ 'code' => isset( $result['code'] ) ? $result['code'] : null ] );

                $code = isset( $result['code'] ) ? (int) $result['code'] : 0;
                if ( $code >= 200 && $code < 300 ) {
                    delete_option( $option_name );
                } else {
                    $this->update_option_from_response( $key, $result, 'deactivate' );
                }
            }

            $this->flush_updater_cache();
        }

        /**
         * Mask a license key for logging or display.
         *
         * @param string $license
         * @return string
         */
        protected function mask_license_key( $license ) {
            $license = (string) $license;
            $len     = strlen( $license );

            if ( $len <= 8 ) {
                return str_repeat( '*', max( $len - 2, 0 ) ) . substr( $license, -2 );
            }

            return substr( $license, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $license, -4 );
        }

        /**
         * Get license key from wp-config constant if defined.
         *
         * @return string
         */
        protected function get_license_key_from_constant() {
            $const = isset( $this->config['license_constant'] ) ? (string) $this->config['license_constant'] : '';
            if ( $const && defined( $const ) ) {
                $val = constant( $const );
                if ( is_string( $val ) ) {
                    return trim( $val );
                }
            }
            return '';
        }

        /**
         * Get license key stored in wp_options.
         *
         * @return string
         */
        protected function get_license_key_from_option() {
            $opt = get_option( $this->config['option_name'], [] );
            if ( is_array( $opt ) && ! empty( $opt['license_key'] ) ) {
                return trim( (string) $opt['license_key'] );
            }
            return '';
        }

        /**
         * Resolve license key using precedence rules.
         *
         * @param string $override
         * @return string
         */
        protected function resolve_license_key( $override = '' ) {
            $override = trim( (string) $override );
            if ( $override !== '' ) {
                return $override;
            }

            $from_const  = $this->get_license_key_from_constant();
            $from_option = $this->get_license_key_from_option();
            $prefer_const = isset( $this->config['prefer_constant'] ) ? (bool) $this->config['prefer_constant'] : true;

            if ( $from_const !== '' && ( $prefer_const || $from_option === '' ) ) {
                return $from_const;
            }

            return $from_option;
        }

        /**
         * True when the license is managed via wp-config constant.
         *
         * @return bool
         */
        protected function license_is_constant_managed() {
            $from_const  = $this->get_license_key_from_constant();
            $from_option = $this->get_license_key_from_option();
            $prefer_const = isset( $this->config['prefer_constant'] ) ? (bool) $this->config['prefer_constant'] : true;

            return $from_const !== '' && ( $prefer_const || $from_option === '' );
        }

        /**
         * Sync a wp-config-managed license.
         *
         * First sync activates the license for the current site.
         * Later syncs run lightweight checks.
         *
         * For constant-managed licenses, the raw key is never persisted to wp_options.
         *
         * @return void
         */
        public function maybe_sync_constant_managed_license() {
            if ( ! $this->license_is_constant_managed() ) {
                return;
            }

            $option      = get_option( $this->config['option_name'], [] );
            $status      = isset( $option['status'] ) ? strtolower( (string) $option['status'] ) : 'inactive';
            $last_check  = isset( $option['last_check'] ) ? (int) $option['last_check'] : 0;
            $last_action = isset( $option['last_action'] ) ? strtolower( (string) $option['last_action'] ) : '';
            $license_key = $this->resolve_license_key();

            if ( $license_key === '' ) {
                return;
            }

            $now = time();

            if ( $status === 'active' && $last_check > 0 && ( $now - $last_check ) < 12 * HOUR_IN_SECONDS ) {
                return;
            }

            if ( $status !== 'active' && $last_check > 0 && ( $now - $last_check ) < 5 * MINUTE_IN_SECONDS ) {
                return;
            }

            $action = 'check';

            if ( $status !== 'active' || $last_check === 0 || $last_action !== 'activate' ) {
                $action = 'activate';
            }

            $this->log( 'Syncing constant-managed license.', [
                'action'      => $action,
                'status'      => $status,
                'last_check'  => $last_check,
                'last_action' => $last_action,
            ] );

            $result = $this->call_license_endpoint( $license_key, $action, home_url() );
            $this->update_option_from_response( $license_key, $result, $action );
            $this->flush_updater_cache();
        }

        /**
         * Call the license endpoint.
         *
         * @param string $license_key
         * @param string $action
         * @param string $domain
         * @return array
         */
        protected function call_license_endpoint( $license_key, $action, $domain ) {
            $c      = $this->config;
            $server = isset( $c['license_server'] ) ? trim( (string) $c['license_server'] ) : '';
            $slug   = isset( $c['slug'] ) ? (string) $c['slug'] : '';
            $action = strtolower( (string) $action );

            if ( ! $server || ! $slug ) {
                return [
                    'code' => 0,
                    'data' => [
                        'success' => false,
                        'message' => __( 'License server or slug is not configured.', 'default' ),
                    ],
                ];
            }

            $endpoint_base = untrailingslashit( $server ) . '/wp-json/uupd/v1';

            $payload = [
                'license_key' => (string) $license_key,
                'slug'        => (string) $slug,
                'domain'      => (string) $domain,
            ];

            $args = [
                'timeout' => 20,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $payload ),
            ];

            if ( $action === 'activate' ) {
                $pre_url = $endpoint_base . '/license/deactivate';
                wp_remote_post( $pre_url, $args );
            }

            if ( $action === 'deactivate' ) {
                $url = $endpoint_base . '/license/deactivate';
            } elseif ( $action === 'check' || $action === 'check_license' ) {
                $url = $endpoint_base . '/license/check';
            } else {
                $url = $endpoint_base . '/license/activate';
            }

            $this->log( 'UUPD: calling license endpoint.', [ 'url' => $url, 'action' => $action ] );

            $response = wp_remote_post( $url, $args );

            if ( is_wp_error( $response ) ) {
                return [
                    'code' => 0,
                    'data' => [
                        'success' => false,
                        'message' => $response->get_error_message(),
                    ],
                ];
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $raw  = wp_remote_retrieve_body( $response );
            $data = json_decode( $raw, true );

            if ( ! is_array( $data ) ) {
                $data = [
                    'success' => false,
                    'message' => __( 'Unexpected response from license server.', 'default' ),
                ];
            }

            return [
                'code' => $code,
                'data' => $data,
            ];
        }

        /**
         * Update stored option from API response.
         *
         * @param string $license_key
         * @param array  $result
         */
        protected function update_option_from_response( $license_key, array $result, $action = 'check' ) {
            $c           = $this->config;
            $option_name = $c['option_name'];

            $code = isset( $result['code'] ) ? (int) $result['code'] : 0;
            $data = ( isset( $result['data'] ) && is_array( $result['data'] ) ) ? $result['data'] : [];

            $status     = 'unknown';
            $license_id = null;
            $error_msg  = '';

            if ( isset( $data['status'] ) ) {
                $status = strtolower( (string) $data['status'] );
            } elseif ( isset( $data['license_status'] ) ) {
                $status = strtolower( (string) $data['license_status'] );
            }

            if ( $status === 'valid' ) {
                $status = 'active';
            }

            if ( $status === 'unknown' && $code >= 200 && $code < 300 && array_key_exists( 'success', $data ) ) {
                $status = ! empty( $data['success'] ) ? 'active' : 'inactive';
            }

            if ( isset( $data['expiry'] ) && ! isset( $data['date_expires'] ) ) {
                $data['date_expires'] = $data['expiry'];
            }
            if ( isset( $data['expiration_date'] ) && ! isset( $data['date_expires'] ) ) {
                $data['date_expires'] = $data['expiration_date'];
            }
            if ( isset( $data['activation_limit'] ) && ! isset( $data['max_activations'] ) ) {
                $data['max_activations'] = $data['activation_limit'];
            }

            if ( isset( $data['id'] ) ) {
                $license_id = (int) $data['id'];
            } elseif ( isset( $data['license_id'] ) ) {
                $license_id = (int) $data['license_id'];
            }

            if ( $code < 200 || $code >= 300 || ( isset( $data['success'] ) && empty( $data['success'] ) ) ) {
                $error_msg = isset( $data['message'] ) ? (string) $data['message'] : '';
                if ( ! $error_msg && isset( $data['error'] ) ) {
                    $error_msg = (string) $data['error'];
                }
                if ( ! $error_msg && $code ) {
                    $error_msg = sprintf( __( 'License request failed with status code %d.', 'default' ), $code );
                }
            }

            $option = [
                'vendor'        => $c['vendor'],
                'slug'          => $c['slug'],
                'instance_key'  => $c['instance_key'],
                'license_key'   => $this->license_is_constant_managed() ? '' : $license_key,
                'status'        => $status,
                'license_id'    => $license_id,
                'item_id'       => $c['item_id'],
                'last_response' => $data,
                'last_check'    => time(),
                'last_error'    => $error_msg,
                'last_action'   => strtolower( (string) $action ),
            ];

            $this->log( 'Updating stored license option from response.', [
                'code'        => $code,
                'status'      => $status,
                'license_id'  => $license_id,
                'last_error'  => $error_msg,
                'option_name' => $option_name,
                'constant_managed' => $this->license_is_constant_managed(),
            ] );

            update_option( $option_name, $option, false );
        }

        /**
         * Flush updater cache/transients after license changes.
         */
        protected function flush_updater_cache() {
            $c = $this->config;
            $prefix = $c['cache_prefix'];

            global $wpdb;

            if ( empty( $wpdb->options ) ) {
                return;
            }

            $like = $wpdb->esc_like( '_transient_' . $prefix );
            $sql  = $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like . '%',
                $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
            );

            $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

            $this->log( 'Flushed updater cache transients.', [
                'prefix'       => $prefix,
                'instance_key' => $c['instance_key'],
            ] );
        }

        /**
         * Inject resolved license and server URL into updater config.
         *
         * This callback is intended for vendor-aware scoped updater filters:
         * uupd/filter_config/{vendor}/{slug}
         *
         * @param array $updater_config
         * @param mixed $value
         * @return array
         */
        public static function filter_updater_config_vendorized( $updater_config, $vendor = '', $slug = '', $instance_key = '' ) {
		    if ( ! is_array( $updater_config ) ) {
		        return $updater_config;
		    }

		    $vendor = self::normalize_vendor_token( $vendor );
		    $slug   = sanitize_key( $slug );

		    $inst = $vendor && $slug ? self::get_instance( $vendor, $slug ) : null;

		    if ( ! $inst ) {
		        return $updater_config;
		    }

		    return self::inject_updater_config_for_instance( $updater_config, $inst );
		}

        /**
         * Shared updater config injection logic.
         *
         * @param array $updater_config
         * @param self  $inst
         * @return array
         */
        protected static function inject_updater_config_for_instance( array $updater_config, self $inst ) {
            $c      = $inst->config;
            $option = get_option( $c['option_name'], [] );
            $status = isset( $option['status'] ) ? strtolower( $option['status'] ) : 'inactive';
            $license = $inst->resolve_license_key();

            if ( ! empty( $c['metadata_base'] ) ) {
                $updater_config['server'] = $c['metadata_base'];
            } elseif ( ! empty( $c['license_server'] ) ) {
                $updater_config['server'] = $c['license_server'];
            }

            $updater_config['key'] = ( $status === 'active' && $license !== '' ) ? $license : '';

            $inst->log( 'Injecting license into updater config', [
                'server'   => isset( $updater_config['server'] ) ? $updater_config['server'] : '',
                'has_key'  => ! empty( $license ),
                'status'   => $status,
            ] );

            return $updater_config;
        }

        /**
         * Static helper to run a live license check.
         *
         * @param string $vendor
         * @param string $slug
         * @param bool   $flush_updater_cache
         * @return array|null
         */
        public static function check_license_for( $vendor, $slug, $flush_updater_cache = true ) {
            $inst = self::get_instance( $vendor, $slug );
            if ( ! $inst ) {
                return null;
            }

            return $inst->check_license_live( $flush_updater_cache );
        }

        /**
         * Instance method to run a live license check.
         *
         * @param bool $flush_updater_cache
         * @return array|null
         */
        public function check_license_live( $flush_updater_cache = true ) {
            $license_key = $this->resolve_license_key();

            if ( ! $license_key ) {
                return null;
            }

            $this->log( 'Running live license check.', [ 'license_key' => $this->mask_license_key( $license_key ) ] );
            $result = $this->call_license_endpoint( $license_key, 'check', home_url() );
            $this->update_option_from_response( $license_key, $result, 'check' );

            if ( $flush_updater_cache ) {
                $this->flush_updater_cache();
            }

            return $result;
        }

        /**
         * Cron handler to run scheduled license checks.
         */
        public function cron_check_license() {
            $this->check_license_live( true );
        }

        /**
         * Vendor-aware server URL callback.
         *
         * @param string $url
         * @param mixed  $value
         * @return string
         */
        public static function filter_server_url_vendorized( $url, $vendor = '', $slug = '', $instance_key = '' ) {
		    $vendor = self::normalize_vendor_token( $vendor );
		    $slug   = sanitize_key( $slug );

		    $inst = ( $vendor && $slug ) ? self::get_instance( $vendor, $slug ) : null;

		    if ( ! $inst ) {
		        return $url;
		    }

		    $c    = $inst->config;
		    $base = ! empty( $c['metadata_base'] ) ? $c['metadata_base'] : ( $c['license_server'] ?? '' );

		    return $base ? trailingslashit( $base ) : $url;
		}

        /**
         * Render a compact inline license box.
         *
         * @param string $slug
         * @param string $vendor
         * @param array  $args
         */
        public static function render_box_for( $slug, $vendor, array $args = [] ) {
            $vendor = self::normalize_vendor_token( $vendor );
            $slug   = sanitize_key( $slug );
            $inst   = self::get_instance( $vendor, $slug );

            if ( ! $inst ) {
                return;
            }

            $c = $inst->config;

            if ( ! current_user_can( $c['capability'] ) ) {
                return;
            }

            $option         = get_option( $c['option_name'], [] );
            $status         = isset( $option['status'] ) ? $option['status'] : 'inactive';
            $license        = $inst->resolve_license_key();
            $license_masked = $license ? $inst->mask_license_key( $license ) : '';
            $is_const       = $inst->license_is_constant_managed();
            $is_active      = ( strtolower( $status ) === 'active' );
            $status_label   = $is_active ? __( 'Active', 'default' ) : __( 'Inactive', 'default' );
            $status_class   = $is_active ? 'uupd-license-status--active' : 'uupd-license-status--inactive';

            $last_response = isset( $option['last_response'] ) && is_array( $option['last_response'] ) ? $option['last_response'] : [];
            $last_checked = ! empty( $option['last_check'] )
                ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $option['last_check'] )
                : __( 'Never', 'default' );
            $last_error = isset( $option['last_error'] ) ? $option['last_error'] : '';
            $expires = isset( $last_response['date_expires'] ) ? $last_response['date_expires'] : ( $last_response['expiry'] ?? '' );
            $activations = isset( $last_response['activations'] ) ? (int) $last_response['activations'] : null;
            $max_activations = isset( $last_response['max_activations'] ) ? (int) $last_response['max_activations']
                : ( isset( $last_response['activation_limit'] ) ? (int) $last_response['activation_limit'] : null );
            $host = parse_url( home_url(), PHP_URL_HOST );

            $defaults = [
                'id'               => 'uupd-license-box-' . $c['instance_key'],
                'title'            => sprintf( __( '%s Licence', 'default' ), $c['plugin_name'] ),
                'description'      => $is_active
                    ? __( 'Your license is active.', 'default' )
                    : sprintf( __( 'Enter your license key to enable updates for %s.', 'default' ), $c['plugin_name'] ),
                'placeholder'      => __( 'Enter your license key', 'default' ),
                'activate_label'   => __( 'Activate License', 'default' ),
                'deactivate_label' => __( 'Deactivate License', 'default' ),
                'box_class'        => '',
            ];

            $args = wp_parse_args( $args, $defaults );

            if ( $is_active ) {
                $args['description'] = '';
            }
            if ( $is_const ) {
                $args['description'] = sprintf( __( 'License key is managed via wp-config.php (%s).', 'default' ), $c['license_constant'] );
            }

            if ( ! self::$inline_box_css_printed ) {
                self::$inline_box_css_printed = true;

                $raw_css = '
                .uupd-root .uupd-license-inline-box {
                    border: 1px solid #d0d7de;
                    background: #fff;
                    padding: 12px 16px;
                    margin: 16px 0;
                    border-radius: 6px;
                    max-width: 540px;
                    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
                    font-size: 13px;
                }
                .uupd-root .uupd-license-inline-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    cursor: pointer;
                    user-select: none;
                }
                .uupd-root .uupd-license-inline-title {
                    margin: 0;
                    font-size: 14px;
                    font-weight: 600;
                }
                .uupd-root .uupd-license-inline-right {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                .uupd-root .uupd-license-toggle-icon {
                    display: inline-block;
                    transition: transform 0.15s ease;
                    font-size: 12px;
                }
                .uupd-root .uupd-license-inline-box[data-collapsed="1"] .uupd-license-toggle-icon {
                    transform: rotate(-90deg);
                }
                .uupd-root .uupd-license-inline-description {
                    margin: 8px 0 10px;
                    max-width: 460px;
                    color: #4b5563;
                }
                .uupd-root .uupd-license-status {
                    padding: 2px 8px;
                    border-radius: 999px;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    white-space: nowrap;
                }
                .uupd-root .uupd-license-status--active {
                    background: #e3f7e5;
                    color: #15803d;
                    border: 1px solid #22c55e;
                }
                .uupd-root .uupd-license-status--inactive {
                    background: #fef2f2;
                    color: #b91c1c;
                    border: 1px solid #f97373;
                }
                .uupd-root .uupd-license-inline-body {
                    margin-top: 10px;
                }
                .uupd-root .uupd-license-inline-body .uupd-license-field {
                    margin-bottom: 8px;
                }
                .uupd-root .uupd-license-inline-body .uupd-license-field label {
                    display: block;
                    font-weight: 500;
                    margin-bottom: 4px;
                }
                .uupd-root .uupd-license-inline-body .uupd-license-field input[type="text"],
                .uupd-root .uupd-license-inline-body .uupd-license-field input[type="password"] {
                    width: 100%;
                    max-width: 320px;
                }
                .uupd-root .uupd-license-inline-body .uupd-license-actions {
                    margin-top: 6px;
                }
                .uupd-root .uupd-license-inline-body .uupd-license-actions .button {
                    margin-right: 6px;
                }
                .uupd-root .uupd-license-meta {
                    margin-top: 6px;
                    font-size: 11px;
                    color: #6b7280;
                }
                .uupd-root .uupd-license-meta small {
                    display: inline-block;
                    margin-right: 10px;
                }
                .uupd-root .uupd-license-error {
                    margin-top: 8px;
                    padding: 6px 8px;
                    border-radius: 4px;
                    background: #fef2f2;
                    border: 1px solid #f97373;
                    color: #b91c1c;
                }
                ';

                $filtered_css = apply_filters( 'uupd/license_ui/inline_box_css', $raw_css, $c['slug'], $inst );
                ?>
                <style><?php echo $filtered_css ? $filtered_css : ''; ?></style>
                <?php
            }

            if ( ! self::$inline_box_js_printed ) {
                self::$inline_box_js_printed = true;
                ?>
                <script>
                    (function() {
                        document.addEventListener('click', function(e) {
                            var header = e.target.closest('.uupd-license-inline-header');
                            if (!header) return;
                            var box = header.closest('.uupd-license-inline-box');
                            if (!box) return;
                            var body = box.querySelector('.uupd-license-inline-body');
                            if (!body) return;
                            var collapsed = box.getAttribute('data-collapsed') === '1';
                            box.setAttribute('data-collapsed', collapsed ? '0' : '1');
                            body.style.display = collapsed ? '' : 'none';
                        });
                    })();
                </script>
                <?php
            }

            $collapsed  = $is_active ? '1' : '0';
            $body_style = $is_active ? 'style="display:none;"' : '';

            $scheme      = is_ssl() ? 'https://' : 'http://';
            $host_header = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
            $current_url = ( $host_header && $request_uri ) ? $scheme . $host_header . $request_uri : '';
            ?>
            <div class="uupd-root" data-uupd-vendor="<?php echo esc_attr( $c['vendor'] ); ?>" data-uupd-slug="<?php echo esc_attr( $c['slug'] ); ?>">
                <div class="uupd-license-inline-box <?php echo esc_attr( $args['box_class'] ); ?>"
                    id="<?php echo esc_attr( $args['id'] ); ?>"
                    data-collapsed="<?php echo esc_attr( $collapsed ); ?>">

                    <div class="uupd-license-inline-header" tabindex="0">
                        <h2 class="uupd-license-inline-title"><?php echo esc_html( $args['title'] ); ?></h2>
                        <div class="uupd-license-inline-right">
                            <?php if ( $license_masked ) : ?>
                                <span style="font-size:11px;color:#6b7280;"><?php echo esc_html( $license_masked ); ?></span>
                            <?php endif; ?>
                            <span class="uupd-license-status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                            <span class="uupd-license-toggle-icon" aria-hidden="true">▾</span>
                        </div>
                    </div>

                    <div class="uupd-license-inline-body" <?php echo $body_style; ?>>
                        <?php if ( ! empty( $args['description'] ) ) : ?>
                            <p class="uupd-license-inline-description"><?php echo esc_html( $args['description'] ); ?></p>
                        <?php endif; ?>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <?php wp_nonce_field( 'uupd_license_action_' . $c['instance_key'], 'uupd_license_nonce' ); ?>
                            <input type="hidden" name="action" value="uupd_license_action" />
                            <input type="hidden" name="uupd_vendor" value="<?php echo esc_attr( $c['vendor'] ); ?>" />
                            <input type="hidden" name="uupd_slug" value="<?php echo esc_attr( $c['slug'] ); ?>" />
                            <?php if ( $current_url ) : ?>
                                <input type="hidden" name="uupd_redirect" value="<?php echo esc_attr( $current_url ); ?>" />
                            <?php endif; ?>

                            <div class="uupd-license-field">
                                <?php if ( $is_const ) : ?>
                                    <p><?php esc_html_e( 'This license is managed via wp-config.php.', 'default' ); ?></p>
                                    <?php if ( $license_masked ) : ?>
                                        <p class="description"><?php printf( esc_html__( 'Current key: %s', 'default' ), esc_html( $license_masked ) ); ?></p>
                                    <?php endif; ?>
                                <?php elseif ( ! $is_active ) : ?>
                                    <label for="uupd_license_key_<?php echo esc_attr( $c['instance_key'] ); ?>"><?php esc_html_e( 'License Key', 'default' ); ?></label>
                                    <input
                                        type="text"
                                        id="uupd_license_key_<?php echo esc_attr( $c['instance_key'] ); ?>"
                                        name="uupd_license_key"
                                        class="regular-text"
                                        value=""
                                        placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
                                    />
                                    <?php if ( $license_masked ) : ?>
                                        <p class="description"><?php printf( esc_html__( 'Current key: %s', 'default' ), esc_html( $license_masked ) ); ?></p>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <p><?php esc_html_e( 'Your license is active.', 'default' ); ?></p>
                                    <?php if ( $license_masked ) : ?>
                                        <p class="description"><?php printf( esc_html__( 'Current key: %s', 'default' ), esc_html( $license_masked ) ); ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <div class="uupd-license-actions">
                                <?php if ( ! $is_const && ! $is_active ) : ?>
                                    <button type="submit" name="uupd_action" value="activate" class="button button-primary"><?php echo esc_html( $args['activate_label'] ); ?></button>
                                <?php endif; ?>
                                <?php if ( ! $is_const && $is_active ) : ?>
                                    <button type="submit" name="uupd_action" value="deactivate" class="button"><?php echo esc_html( $args['deactivate_label'] ); ?></button>
                                <?php endif; ?>
                            </div>

                            <div class="uupd-license-meta">
                                <small><?php printf( esc_html__( 'Vendor: %s', 'default' ), esc_html( $c['vendor'] ) ); ?></small>
                                <small><?php printf( esc_html__( 'Site: %s', 'default' ), esc_html( $host ) ); ?></small>
                                <small><?php printf( esc_html__( 'Last checked: %s', 'default' ), esc_html( $last_checked ) ); ?></small>
                                <?php if ( $expires ) : ?>
                                    <small><?php printf( esc_html__( 'Expires: %s', 'default' ), esc_html( $expires ) ); ?></small>
                                <?php endif; ?>
                                <?php if ( $activations !== null && $max_activations !== null ) : ?>
                                    <small><?php printf( esc_html__( 'Activations: %1$d / %2$d', 'default' ), $activations, $max_activations ); ?></small>
                                <?php endif; ?>
                            </div>

                            <?php if ( $last_error ) : ?>
                                <div class="uupd-license-error"><?php echo esc_html( $last_error ); ?></div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * Basic logger.
         *
         * @param string $msg
         * @param array  $context
         */
        protected function log( $msg, array $context = [] ) {
           $slug = $this->config['slug'] ?? '';
            if ( ! apply_filters( 'updater_enable_debug', false, $slug ) ) {
                return;
            }
            $vendor = $this->config['vendor'] ?? '';
            $prefix = "[UUPD License][{$vendor}/{$slug}] ";

            if ( ! empty( $context ) && function_exists( 'wp_json_encode' ) ) {
                $msg .= ' | ' . wp_json_encode( $context );
            }

            error_log( $prefix . $msg );
            do_action( 'uupd/log', $msg, $slug, $context );
        }
    }
}
