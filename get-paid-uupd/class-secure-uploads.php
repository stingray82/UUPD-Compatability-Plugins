<?php
/**
 * Secure ZIP Uploads & Friendly Download Names
 *
 * - Randomises filenames of ZIPs uploaded via Media Library by appending
 *   a 32-character suffix.
 * - Cleans download "name" fields in _getpaid_item_downloads for wpi_item
 *   so they don't show the random hash.
 * - Provides a helper to stream a ZIP download with a friendly filename
 *   like: slug-version.zip
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GetPaid_Secure_Zip_Uploads {

    /**
     * Bootstrap hooks.
     */
    public static function init() {

        // Randomise ZIP filenames on upload.
        add_filter(
            'wp_handle_upload_prefilter',
            array( __CLASS__, 'randomise_zip_filename_on_upload' )
        );

        // Clean up _getpaid_item_downloads when saving any post,
        // and then we filter by post type inside.
        add_action(
            'save_post',
            array( __CLASS__, 'cleanup_item_download_meta' ),
            999,
            3
        );
    }

    /**
     * Randomise ZIP filenames uploaded via the media uploader.
     *
     * @param array $file The file array (name, type, tmp_name, size, error).
     *
     * @return array
     */
    public static function randomise_zip_filename_on_upload( $file ) {

        // Only touch ZIP files.
        $is_zip = false;

        // Check MIME type when available.
        if ( ! empty( $file['type'] ) && 'application/zip' === $file['type'] ) {
            $is_zip = true;
        } else {
            // Fallback: check extension.
            $info = pathinfo( $file['name'] );
            if ( ! empty( $info['extension'] ) && 'zip' === strtolower( $info['extension'] ) ) {
                $is_zip = true;
            }
        }

        if ( ! $is_zip ) {
            return $file;
        }

        $info     = pathinfo( $file['name'] );
        $basename = isset( $info['filename'] ) ? $info['filename'] : $info['name'];
        $ext      = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : 'zip';

        // Sanitize base name.
        $basename = sanitize_file_name( $basename );

        // Generate a random 32-character suffix (hex).
        $suffix = self::generate_random_suffix(); // 32 chars.

        // New filename: original-suffix.zip
        $new_name     = sprintf( '%s-%s.%s', $basename, $suffix, $ext );
        $file['name'] = $new_name;

        return $file;
    }

    /**
     * Clean up _getpaid_item_downloads meta on save.
     *
     * - Only runs for post_type = wpi_item.
     * - Does NOT change the "file" URL (must keep the hash for security).
     * - Always sets the "name" field to a clean version of the filename
     *   (basename without hash and without .zip).
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     * @param bool    $update  Whether this is an existing post being updated.
     */
    public static function cleanup_item_download_meta( $post_id, $post, $update ) {

        // Safety checks.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! $post || 'wpi_item' !== $post->post_type ) {
            return;
        }

        // Debug line – check wp-content/debug.log to see if this fires.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'GetPaid_Secure_Zip_Uploads: cleaning downloads for wpi_item ' . $post_id );
        }

        $downloads = get_post_meta( $post_id, '_getpaid_item_downloads', true );

        if ( empty( $downloads ) || ! is_array( $downloads ) ) {
            return;
        }

        $changed = false;

        foreach ( $downloads as $index => $download ) {

            if ( empty( $download['file'] ) ) {
                continue;
            }

            // Extract just the filename from the URL path.
            $path     = wp_parse_url( $download['file'], PHP_URL_PATH );
            $basename = $path ? basename( $path ) : '';

            if ( ! $basename ) {
                continue;
            }

            // Remove extension.
            $stem = preg_replace( '/\.zip$/i', '', $basename );

            // Clean out "-<32hex>" suffix.
            // stem: new-example-plugin-98c3b64cbe59bae435a0288a6eed90e7
            // becomes: new-example-plugin
            $clean_stem = preg_replace( '/^(.*)-[a-f0-9]{32}$/i', '$1', $stem );

            if ( empty( $clean_stem ) ) {
                // Fallback: just use stem.
                $clean_stem = $stem;
            }

            // Force the name to our clean value.
            $downloads[ $index ]['name'] = $clean_stem;
            $changed = true;
        }

        if ( $changed ) {
            update_post_meta( $post_id, '_getpaid_item_downloads', $downloads );
        }
    }

    /**
     * Generate a random 32-character string (hex).
     *
     * @return string
     */
    protected static function generate_random_suffix() {

        // Try cryptographically secure random_bytes() first.
        if ( function_exists( 'random_bytes' ) ) {
            try {
                return bin2hex( random_bytes( 16 ) ); // 16 bytes = 32 hex chars.
            } catch ( Exception $e ) {
                // fall through to wp_generate_password
            }
        }

        // Fallback to wp_generate_password() if needed.
        return substr(
            preg_replace(
                '/[^a-f0-9]/i',
                '',
                wp_generate_password( 40, false, false )
            ),
            0,
            32
        );
    }

    /**
     * Stream a ZIP file to the browser with a friendly download name.
     *
     * This should be called *instead of* wp_safe_redirect( $file_url ) in
     * getpaid_uupd_maybe_handle_download().
     *
     * @param int    $item_id   WPI item ID.
     * @param string $file_url  The actual (randomised) ZIP URL in uploads.
     */
    public static function stream_zip_download( $item_id, $file_url ) {

        // Resolve local path from URL (only for local uploads).
        $uploads = wp_get_upload_dir();

        if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
            // Cannot safely resolve, fall back to redirect.
            wp_safe_redirect( $file_url );
            exit;
        }

        // Ensure this is within uploads; if not, just redirect.
        if ( 0 !== strpos( $file_url, $uploads['baseurl'] ) ) {
            wp_safe_redirect( $file_url );
            exit;
        }

        $file_path = str_replace( $uploads['baseurl'], $uploads['basedir'], $file_url );
        $file_path = wp_normalize_path( $file_path );

        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            // File missing; better to show a friendly error.
            wp_die( 'Download file not found.', 'Download Error', array( 'response' => 404 ) );
        }

        // Build a friendly filename: slug-version.zip
        $slug    = get_post_meta( $item_id, '_wpi_plugin_slug', true );
        $version = get_post_meta( $item_id, 'getpaid_license_manager_item_version', true );

        if ( empty( $slug ) ) {
            $slug = 'plugin';
        }

        $download_name = $slug;
        if ( ! empty( $version ) ) {
            $download_name .= '-' . $version;
        }

        // Ensure .zip extension and safe filename.
        $download_name = sanitize_file_name( $download_name . '.zip' );

        // Clean output buffers.
        if ( ob_get_length() ) {
            @ob_end_clean();
        }

        // Send headers.
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // Stream the file.
        $handle = fopen( $file_path, 'rb' );
        if ( $handle ) {
            while ( ! feof( $handle ) ) {
                echo fread( $handle, 8192 );
                flush();
                if ( connection_status() != CONNECTION_NORMAL ) {
                    break;
                }
            }
            fclose( $handle );
        }

        exit;
    }
}

// Initialise the hooks.
GetPaid_Secure_Zip_Uploads::init();
