<?php
/**
 * ttrp-common: shared code bundled in every ttrp.gr plugin (update checker +
 * "All Plugins" catalog page).
 *
 * Each plugin ships its own copy and calls, from its main file:
 *
 *     require_once plugin_dir_path( __FILE__ ) . 'ttrp-common/loader.php';
 *     ttrp_common_register( __FILE__, 'the-plugin-slug' );
 *
 * Every copy announces its version here; on plugins_loaded only the NEWEST
 * copy's classes are loaded, so an older plugin can't pin old behaviour.
 * The canonical source lives in plugins.ttrp.gr-autoupdater/client/ttrp-common/;
 * copy it into plugins with sync-ttrp-common.sh, never edit a plugin's copy.
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['ttrp_common_versions']            = isset( $GLOBALS['ttrp_common_versions'] ) ? $GLOBALS['ttrp_common_versions'] : array();
$GLOBALS['ttrp_common_versions']['1.0.0'] = __DIR__; // Bump together with TTRP_Common_Catalog::VERSION.

if ( ! function_exists( 'ttrp_common_register' ) ) {

	/**
	 * Registers a plugin for updates from plugins.ttrp.gr.
	 *
	 * @param string $plugin_file Main plugin file (__FILE__).
	 * @param string $slug        Slug as registered in the update server's config.php.
	 */
	function ttrp_common_register( $plugin_file, $slug ) {
		$GLOBALS['ttrp_common_plugins'][ $slug ] = $plugin_file;
	}

	/**
	 * Loads the newest ttrp-common copy and starts the updaters + catalog page.
	 */
	function ttrp_common_boot() {
		$versions = isset( $GLOBALS['ttrp_common_versions'] ) ? $GLOBALS['ttrp_common_versions'] : array();
		if ( ! $versions ) {
			return;
		}
		uksort( $versions, 'version_compare' );
		$dir = end( $versions );

		require_once $dir . '/class-ttrp-common-updater.php';
		require_once $dir . '/class-ttrp-common-catalog.php';

		$plugins = isset( $GLOBALS['ttrp_common_plugins'] ) ? $GLOBALS['ttrp_common_plugins'] : array();
		foreach ( $plugins as $slug => $file ) {
			new TTRP_Common_Updater( $file, $slug );
		}
		TTRP_Common_Catalog::init( $dir );
	}

	add_action( 'plugins_loaded', 'ttrp_common_boot', 1 );
}
