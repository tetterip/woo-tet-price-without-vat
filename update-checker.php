<?php
/**
 * TTRP Plugin Update Checker
 * Include this file in your plugin and initialize with 3 lines.
 *
 * Usage (at the bottom of your main plugin file):
 *
 *   require_once plugin_dir_path( __FILE__ ) . 'update-checker.php';
 *   new TTRP_Update_Checker( __FILE__, 'your-plugin-slug' );
 *
 * The plugin slug must match the key in config.php on the update server.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'TTRP_Update_Checker' ) ) :

class TTRP_Update_Checker {

    const UPDATE_SERVER = 'https://plugins.ttrp.gr/';

    private string $plugin_file;
    private string $plugin_slug;
    private string $plugin_basename;
    private array  $plugin_data;

    public function __construct( string $plugin_file, string $plugin_slug ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = $plugin_slug;
        $this->plugin_basename = plugin_basename( $plugin_file );

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
        add_filter( 'upgrader_source_selection', [ $this, 'fix_directory_name' ], 10, 4 );
    }

    private function get_plugin_data(): array {
        if ( empty( $this->plugin_data ) ) {
            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $this->plugin_data = get_plugin_data( $this->plugin_file );
        }
        return $this->plugin_data;
    }

    /**
     * Called during WP's regular update check cycle.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $data    = $this->get_plugin_data();
        $current = $data['Version'] ?? '0.0.0';

        $response = $this->remote_get( 'info' );
        if ( is_wp_error( $response ) || empty( $response->version ) ) {
            return $transient;
        }

        if ( version_compare( $response->version, $current, '>' ) ) {
            $transient->response[ $this->plugin_basename ] = (object) [
                'id'            => $this->plugin_slug,
                'slug'          => $this->plugin_slug,
                'plugin'        => $this->plugin_basename,
                'new_version'   => $response->version,
                'url'           => $response->homepage ?? '',
                'package'       => $response->download_url,
                'requires'      => $response->requires ?? '',
                'tested'        => $response->tested ?? '',
                'requires_php'  => $response->requires_php ?? '',
                'icons'         => [],
                'banners'       => [],
            ];
        } else {
            // Let WP know the plugin is up to date (prevents false "no update info" notices)
            $transient->no_update[ $this->plugin_basename ] = (object) [
                'id'          => $this->plugin_slug,
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $current,
                'url'         => $response->homepage ?? '',
                'package'     => '',
            ];
        }

        return $transient;
    }

    /**
     * Populates the plugin details modal in WP admin.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) return $result;

        $response = $this->remote_get( 'info' );
        if ( is_wp_error( $response ) || empty( $response->version ) ) {
            return $result;
        }

        $data = $this->get_plugin_data();

        return (object) [
            'name'          => $data['Name'],
            'slug'          => $this->plugin_slug,
            'version'       => $response->version,
            'author'        => $data['Author'],
            'homepage'      => $response->homepage ?? $data['PluginURI'],
            'requires'      => $response->requires ?? '',
            'tested'        => $response->tested ?? '',
            'requires_php'  => $response->requires_php ?? '',
            'last_updated'  => $response->last_updated ?? '',
            'sections'      => [
                'description' => $data['Description'],
                'changelog'   => $response->changelog ?? 'See GitHub releases for changelog.',
            ],
            'download_link' => $response->download_url,
        ];
    }

    /**
     * GitHub zips extract as "repo-tag/" — rename to match the plugin slug
     * so WP doesn't deactivate the plugin after updating.
     */
    public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( ! isset( $hook_extra['plugin'] ) ) return $source;
        if ( $hook_extra['plugin'] !== $this->plugin_basename ) return $source;

        $expected = trailingslashit( dirname( $source ) ) . $this->plugin_slug . '/';

        if ( $source !== $expected ) {
            global $wp_filesystem;
            if ( ! function_exists( 'WP_Filesystem' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            WP_Filesystem();
            if ( $wp_filesystem && $wp_filesystem->move( $source, $expected ) ) {
                return $expected;
            }
        }

        return $source;
    }

    /**
     * Hits the update server and returns decoded JSON or WP_Error.
     */
    private function remote_get( string $action ) {
        $url = add_query_arg( [
            'action' => $action,
            'plugin' => $this->plugin_slug,
        ], self::UPDATE_SERVER );

        $response = wp_remote_get( $url, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'ttrp_update_error', "Update server returned HTTP $code" );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $body ) ) {
            return new WP_Error( 'ttrp_update_parse', 'Could not parse update server response' );
        }

        return $body;
    }
}

endif; // class_exists