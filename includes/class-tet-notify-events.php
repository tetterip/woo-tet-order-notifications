<?php
defined( 'ABSPATH' ) || exit;

/**
 * Small table of announced orders: {prefix}tet_notify_events.
 *
 * - The UNIQUE order_id makes "announce once" atomic: two requests changing the
 *   same order's status at the same moment (gateway callback + thank-you page)
 *   can't both claim it.
 * - The auto-increment id is the cursor the admin alerts poll with.
 *
 * Rows older than RETENTION_DAYS are deleted daily.
 */
class Tet_Notify_Events {

	const DB_VERSION     = '1';
	const RETENTION_DAYS = 30;
	const CLEANUP_HOOK   = 'tet_notify_cleanup';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tet_notify_events';
	}

	public static function init(): void {
		if ( get_option( 'tet_notify_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
		add_action( self::CLEANUP_HOOK, [ __CLASS__, 'cleanup' ] );
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY order_id (order_id),
				KEY created_at (created_at)
			) $charset;"
		);
		update_option( 'tet_notify_db_version', self::DB_VERSION, false );
	}

	public static function activate(): void {
		self::install();
		// Orders created before the plugin was first activated are never announced.
		add_option( 'tet_notify_since', time(), '', false );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	/** True only for the one request that records this order first. */
	public static function claim( int $order_id ): bool {
		global $wpdb;
		$done = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (order_id, created_at) VALUES (%d, %s)', $order_id, gmdate( 'Y-m-d H:i:s' ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return 1 === (int) $done;
	}

	public static function latest_id(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . self::table() ); // phpcs:ignore WordPress.DB
	}

	/** Events after $after_id, oldest first. @return array<int, array{id:int, order_id:int}> */
	public static function after( int $after_id, int $limit = 10 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT id, order_id FROM ' . self::table() . ' WHERE id > %d ORDER BY id ASC LIMIT %d', $after_id, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return array_map( static function ( $r ) {
			return [ 'id' => (int) $r['id'], 'order_id' => (int) $r['order_id'] ];
		}, (array) $rows );
	}

	public static function cleanup(): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
