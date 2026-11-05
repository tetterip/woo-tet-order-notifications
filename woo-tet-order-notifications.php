<?php
/**
 * Plugin Name:       Order Notifications for WooCommerce
 * Plugin URI:        https://ttrp.gr
 * Description:       Instant new-order notifications to Telegram, Slack, Discord, email or a webhook, plus real-time pop-ups with sound in the WordPress admin.
 * Version:           1.0.0
 * Author:            Michalis Tetteris
 * Author URI:        https://ttrp.gr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://plugins.ttrp.gr/
 * Text Domain:       tet-order-notifications
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * WC requires at least: 7.1
 * WC tested up to:   10.7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'TET_NOTIFY_VERSION', get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version'] );
define( 'TET_NOTIFY_FILE', __FILE__ );
define( 'TET_NOTIFY_PATH', plugin_dir_path( __FILE__ ) );
define( 'TET_NOTIFY_URL', plugin_dir_url( __FILE__ ) );

/**
 * Version string for an asset: plugin version + file time, so browsers fetch a
 * changed file even when the plugin version stays the same (e.g. a reinstalled zip).
 */
function tet_notify_asset_ver( string $rel ): string {
	$time = @filemtime( TET_NOTIFY_PATH . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	return TET_NOTIFY_VERSION . ( $time ? '.' . $time : '' );
}

// Declare HPOS and block checkout compatibility (the plugin only reads orders and writes one meta key).
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

require_once TET_NOTIFY_PATH . 'includes/class-tet-notify-events.php';

register_activation_hook( __FILE__, function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( esc_html__( 'Order Notifications for WooCommerce requires WooCommerce to be installed and active.', 'tet-order-notifications' ) );
	}
	Tet_Notify_Events::activate();
} );

register_deactivation_hook( __FILE__, [ 'Tet_Notify_Events', 'deactivate' ] );

// Load translations on init (avoids the WP 6.7+ just-in-time loading notice).
add_action( 'init', function () {
	load_plugin_textdomain( 'tet-order-notifications', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once TET_NOTIFY_PATH . 'includes/class-tet-notify-message.php';
	require_once TET_NOTIFY_PATH . 'includes/class-tet-notify-order-data.php';
	require_once TET_NOTIFY_PATH . 'includes/class-tet-notify-channels.php';
	require_once TET_NOTIFY_PATH . 'includes/class-tet-notify-dispatcher.php';
	require_once TET_NOTIFY_PATH . 'includes/class-tet-notify-live.php';
	require_once TET_NOTIFY_PATH . 'includes/class-tet-notify-settings.php';

	Tet_Notify_Events::init();
	Tet_Notify_Settings::init();
	Tet_Notify_Dispatcher::init();
	Tet_Notify_Live::init();
} );

// Updates + "All Plugins" page (shared ttrp-common library).
require_once plugin_dir_path( __FILE__ ) . 'ttrp-common/loader.php';
ttrp_common_register( __FILE__, 'woo-tet-order-notifications' );
