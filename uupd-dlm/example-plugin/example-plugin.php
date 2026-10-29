<?php

/**
 * Plugin Name:       Example Plugin (Base)
 * Description:       A test plugin demonstrating UUPD_Updater integration.
 * Tested up to:      6.8.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Version:           0.10
 * Author:            Nathan Foley
 * Author URI:        https://reallyusefulplugins.com
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       example-plugin
 * Website:           https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'UUPD_RUP_TEST_VENDOR', 'rup' );
define( 'UUPD_RUP_TEST_VERSION', '0.9' );
define( 'UUPD_RUP_TEST_MAIN_FILE', __FILE__ );
define( 'UUPD_RUP_TEST_ITEM_NUMBER', 1 );
define( 'UUPD_RUP_TEST_FRIENDLY_NAME', 'Test' );
define( 'UUPD_RUP_TEST_SLUG', 'test' );
define( 'UUPD_RUP_TEST_SERVER_BASE', 'https://dlm.tdlab.uk' );
define(
    'UUPD_RUP_TEST_MENU_SLUG',
    UUPD_RUP_TEST_VENDOR . '-' . UUPD_RUP_TEST_SLUG
);

add_action( 'plugins_loaded', function() {

    require_once __DIR__ . '/inc/updater.php';
    require_once __DIR__ . '/inc/class-uupd-license-ui.php';

    /*
     * Read the customer's selected update channel.
     */
    $release_channel = get_option( 'esp_release_channel', 'stable' );

    $allowed_channels = [
        'stable',
        'alpha',
        'beta',
        'rc',
        'prerelease',
    ];

    if ( ! in_array( $release_channel, $allowed_channels, true ) ) {
        $release_channel = 'stable';
    }

    /*
     * Any non-stable channel enables prerelease handling.
     */
    $allow_prerelease = ( 'stable' !== $release_channel );

    \UUPD\UI\License\V1\UUPD_License_UI::register([
        'vendor'         => UUPD_RUP_TEST_VENDOR,
        'slug'           => UUPD_RUP_TEST_SLUG,
        'item_id'        => UUPD_RUP_TEST_ITEM_NUMBER,
        'license_server' => UUPD_RUP_TEST_SERVER_BASE,
        'metadata_base'  => UUPD_RUP_TEST_SERVER_BASE,
        'plugin_name'    => UUPD_RUP_TEST_FRIENDLY_NAME,
        'menu_parent'    => 'top_level',
        'menu_slug'      => UUPD_RUP_TEST_MENU_SLUG,
        'page_title'     => 'Test License',
        'menu_title'     => 'Test License',
        'capability'     => 'manage_options',
    ]);

    \UUPD\V2\UUPD_Updater_V2::register([
        'vendor'           => UUPD_RUP_TEST_VENDOR,
        'plugin_file'      => plugin_basename( UUPD_RUP_TEST_MAIN_FILE ),
        'slug'             => UUPD_RUP_TEST_SLUG,
        'name'             => UUPD_RUP_TEST_FRIENDLY_NAME,
        'version'          => UUPD_RUP_TEST_VERSION,
        'server'           => UUPD_RUP_TEST_SERVER_BASE,
        'license_key_transport' => 'header',
        'allow_prerelease' => $allow_prerelease,
        'release_channel'  => $release_channel,
    ]);

}, 1 );


/*
|--------------------------------------------------------------------------
| Settings page
|--------------------------------------------------------------------------
*/

add_action( 'admin_menu', 'esp_add_settings_page' );

function esp_add_settings_page() {
    add_options_page(
        'Example Setting Page',
        'Example Setting Page',
        'manage_options',
        'esp-example-setting-page',
        'esp_render_settings_page'
    );
}


add_action( 'admin_init', 'esp_register_settings' );

function esp_register_settings() {

    register_setting(
        'esp_settings_group',
        'esp_text_option'
    );

    register_setting(
        'esp_settings_group',
        'esp_release_channel',
        [
            'type'              => 'string',
            'default'           => 'stable',
            'sanitize_callback' => 'esp_sanitize_release_channel',
        ]
    );
}


function esp_sanitize_release_channel( $value ) {

    $allowed = [
        'stable',
        'alpha',
        'beta',
        'rc',
        'prerelease',
    ];

    $value = sanitize_key( $value );

    if ( ! in_array( $value, $allowed, true ) ) {
        return 'stable';
    }

    return $value;
}


function esp_render_settings_page() {

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $text_value      = get_option( 'esp_text_option', '' );
    $release_channel = get_option( 'esp_release_channel', 'stable' );

    ?>

    <div class="wrap">

        <h1>Example Setting Page</h1>

        <?php
        /*
         * Your attached License UI uses vendor + slug.
         */
        \UUPD\UI\License\V1\UUPD_License_UI::render_box_for(
            UUPD_RUP_TEST_SLUG,
            UUPD_RUP_TEST_VENDOR
        );
        ?>

        <form method="post" action="options.php">

            <?php settings_fields( 'esp_settings_group' ); ?>

            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row">
                        <label for="esp_text_option">
                            Example Text Field
                        </label>
                    </th>

                    <td>
                        <input
                            type="text"
                            id="esp_text_option"
                            name="esp_text_option"
                            value="<?php echo esc_attr( $text_value ); ?>"
                            class="regular-text"
                        />

                        <p class="description">
                            Enter any text you like.
                        </p>
                    </td>
                </tr>


                <tr>
                    <th scope="row">
                        <label for="esp_release_channel">
                            Update Channel
                        </label>
                    </th>

                    <td>

                        <select
                            id="esp_release_channel"
                            name="esp_release_channel"
                        >

                            <option
                                value="stable"
                                <?php selected( $release_channel, 'stable' ); ?>
                            >
                                Stable
                            </option>

                            <option
                                value="rc"
                                <?php selected( $release_channel, 'rc' ); ?>
                            >
                                Release Candidate
                            </option>

                            <option
                                value="beta"
                                <?php selected( $release_channel, 'beta' ); ?>
                            >
                                Beta
                            </option>

                            <option
                                value="alpha"
                                <?php selected( $release_channel, 'alpha' ); ?>
                            >
                                Alpha
                            </option>

                            <option
                                value="prerelease"
                                <?php selected( $release_channel, 'prerelease' ); ?>
                            >
                                All Pre-release Builds
                            </option>

                        </select>

                        <p class="description">
                            Choose which update channel this site should receive.
                        </p>

                    </td>
                </tr>

            </table>

            <?php submit_button(); ?>

        </form>

    </div>

    <?php
}
