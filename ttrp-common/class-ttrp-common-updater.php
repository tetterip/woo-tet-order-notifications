<?php
/**
 * ttrp-common: updates for one plugin from https://plugins.ttrp.gr/.
 *
 * Successor of the standalone update-checker.php (TTRP_Update_Checker). Differences:
 * it sends the installed version (&v=) so the update server's admin can show which
 * site runs which version, and it is loaded through ttrp-common/loader.php.
 */

defined( 'ABSPATH' ) || exit;

class TTRP_Common_Updater {

	const UPDATE_SERVER = 'https://plugins.ttrp.gr/';

	/** @var string */
	private $plugin_file;
	/** @var string */
	private $plugin_slug;
	/** @var string */
	private $plugin_basename;
	/** @var array */
	private $plugin_data = array();

	public function __construct( $plugin_file, $plugin_slug ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_slug     = $plugin_slug;
		$this->plugin_basename = plugin_basename( $plugin_file );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_directory_name' ), 10, 4 );
	}

	private function get_plugin_data() {
		if ( empty( $this->plugin_data ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$this->plugin_data = get_plugin_data( $this->plugin_file, false, false );
		}
		return $this->plugin_data;
	}

	/**
	 * WordPress's regular update check.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$data     = $this->get_plugin_data();
		$current  = isset( $data['Version'] ) ? $data['Version'] : '0.0.0';
		$response = $this->remote_get( 'info', $current );
		if ( is_wp_error( $response ) || empty( $response->version ) ) {
			return $transient;
		}

		$item = (object) array(
			'id'           => $this->plugin_slug,
			'slug'         => $this->plugin_slug,
			'plugin'       => $this->plugin_basename,
			'url'          => isset( $response->homepage ) ? $response->homepage : '',
			'requires'     => isset( $response->requires ) ? $response->requires : '',
			'tested'       => isset( $response->tested ) ? $response->tested : '',
			'requires_php' => isset( $response->requires_php ) ? $response->requires_php : '',
			'icons'        => isset( $response->icons ) ? (array) $response->icons : array(),
			'banners'      => isset( $response->banners ) ? (array) $response->banners : array(),
		);

		if ( version_compare( $response->version, $current, '>' ) ) {
			$item->new_version = $response->version;
			$item->package     = $response->download_url;
			$transient->response[ $this->plugin_basename ] = $item;
		} else {
			// Tells WP the plugin is up to date (avoids false "no update info" notices).
			$item->new_version = $current;
			$item->package     = '';
			$transient->no_update[ $this->plugin_basename ] = $item;
		}

		return $transient;
	}

	/**
	 * The "View details" modal.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$data     = $this->get_plugin_data();
		$response = $this->remote_get( 'info', isset( $data['Version'] ) ? $data['Version'] : '' );
		if ( is_wp_error( $response ) || empty( $response->version ) ) {
			return $result;
		}

		return (object) array(
			'name'          => $data['Name'],
			'slug'          => $this->plugin_slug,
			'version'       => $response->version,
			'author'        => $data['Author'],
			'homepage'      => isset( $response->homepage ) ? $response->homepage : $data['PluginURI'],
			'requires'      => isset( $response->requires ) ? $response->requires : '',
			'tested'        => isset( $response->tested ) ? $response->tested : '',
			'requires_php'  => isset( $response->requires_php ) ? $response->requires_php : '',
			'last_updated'  => isset( $response->last_updated ) ? $response->last_updated : '',
			'sections'      => array(
				'description' => $data['Description'],
				'changelog'   => isset( $response->changelog ) ? $response->changelog : '',
			),
			'download_link' => $response->download_url,
			'icons'         => isset( $response->icons ) ? (array) $response->icons : array(),
			'banners'       => isset( $response->banners ) ? (array) $response->banners : array(),
		);
	}

	/**
	 * GitHub ZIPs may extract as "repo-tag/"; rename to the plugin slug so WP
	 * doesn't deactivate the plugin after updating.
	 */
	public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

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
	 * Calls the update server; returns the decoded JSON object or WP_Error.
	 */
	private function remote_get( $action, $installed_version = '' ) {
		$args = array(
			'action' => $action,
			'plugin' => $this->plugin_slug,
		);
		if ( '' !== $installed_version ) {
			$args['v'] = $installed_version;
		}

		$response = wp_remote_get(
			add_query_arg( $args, self::UPDATE_SERVER ),
			array(
				'timeout'    => 10,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'ttrp_update_error', 'Update server returned HTTP ' . $code );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( JSON_ERROR_NONE !== json_last_error() || empty( $body ) ) {
			return new WP_Error( 'ttrp_update_parse', 'Could not parse update server response' );
		}

		return $body;
	}
}
