<?php
/**
 * Removes the plugin's options, events table, user preferences and scheduled jobs.
 * Orders keep their _tet_notify_sent meta (harmless, shows they were announced).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tet_notify_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tet\\_notify\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

delete_metadata( 'user', 0, 'tet_notify_muted', '', true );

wp_clear_scheduled_hook( 'tet_notify_cleanup' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'tet_notify_send' );
}
