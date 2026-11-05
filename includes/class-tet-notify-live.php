<?php
defined( 'ABSPATH' ) || exit;

/**
 * Real-time alerts in wp-admin: a script on every admin page polls a small REST
 * endpoint for orders announced since the page loaded, then shows a pop-up and
 * plays a sound. Per-user mute lives in user meta.
 */
class Tet_Notify_Live {

	const REST_NS   = 'tet-notify/v1';
	const META_MUTE = 'tet_notify_muted';

	public static function init(): void {
		if ( ! Tet_Notify_Settings::yes( 'tet_notify_live_enabled', 'yes' ) ) {
			return;
		}
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar' ], 100 );
		add_action( 'wp_ajax_tet_notify_mute', [ __CLASS__, 'ajax_mute' ] );
		add_action( 'wp_ajax_tet_notify_nonce', [ __CLASS__, 'ajax_nonce' ] );
	}

	/** Gets alerts: one of the selected roles and allowed to edit orders. */
	public static function user_allowed( $user = null ): bool {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() || ! user_can( $user, 'edit_shop_orders' ) ) {
			return false;
		}
		return (bool) array_intersect( (array) $user->roles, Tet_Notify_Settings::live_roles() );
	}

	private static function is_muted(): bool {
		return 'yes' === get_user_meta( get_current_user_id(), self::META_MUTE, true );
	}

	/* ── Script ─────────────────────────────────────────────── */

	public static function enqueue(): void {
		if ( ! self::user_allowed() ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'tet-notify-live', TET_NOTIFY_URL . 'assets/css/live.css', [], TET_NOTIFY_VERSION );
		wp_enqueue_script( 'tet-notify-live', TET_NOTIFY_URL . 'assets/js/live.js', [], TET_NOTIFY_VERSION, true );

		$sound = (string) get_option( 'tet_notify_live_sound', 'voice' );
		$file  = esc_url_raw( (string) get_option( 'tet_notify_live_sound_url', '' ) );
		if ( 'custom' === $sound && '' === $file ) {
			$sound = 'chime';
		}

		wp_localize_script( 'tet-notify-live', 'tetNotifyLive', [
			'restUrl'   => esc_url_raw( rest_url( self::REST_NS . '/events' ) ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'muteNonce' => wp_create_nonce( 'tet_notify_mute' ),
			'last'      => Tet_Notify_Events::latest_id(),
			'interval'  => Tet_Notify_Settings::live_interval(),
			'sound'     => $sound,
			'soundUrl'  => $file,
			'voiceText' => Tet_Notify_Settings::voice_text(),
			'voiceFile' => self::voice_file(),
			'voiceLang' => str_replace( '_', '-', get_user_locale() ),
			'repeat'    => Tet_Notify_Settings::yes( 'tet_notify_live_repeat' ),
			'desktop'   => Tet_Notify_Settings::yes( 'tet_notify_live_desktop', 'yes' ),
			'muted'     => self::is_muted(),
			'icon'      => esc_url_raw( TET_NOTIFY_URL . 'assets/icon-256.png' ),
			'i18n'      => [
				/* translators: %s: order number. */
				'title'         => __( 'New order #%s', 'tet-order-notifications' ),
				'open'          => __( 'Open order', 'tet-order-notifications' ),
				'close'         => __( 'Close', 'tet-order-notifications' ),
				'closeAll'      => __( 'Close all', 'tet-order-notifications' ),
				'soundOn'       => __( 'Order alerts: sound on', 'tet-order-notifications' ),
				'soundOff'      => __( 'Order alerts: muted', 'tet-order-notifications' ),
				'enableSound'   => __( 'Click to enable sound', 'tet-order-notifications' ),
				'enableDesktop' => __( 'Enable desktop notifications', 'tet-order-notifications' ),
				'expired'       => __( 'Order alerts paused: log in again', 'tet-order-notifications' ),
				'mute'          => __( 'Mute', 'tet-order-notifications' ),
				'unmute'        => __( 'Unmute', 'tet-order-notifications' ),
			],
		] );
	}

	/**
	 * Recorded spoken message shipped with the plugin (assets/sounds/new-order-{lang}.mp3),
	 * used while the spoken message is the default text. A custom text is read by the
	 * browser's own voice instead. '' when there is no recording for the admin language.
	 */
	private static function voice_file(): string {
		if ( '' !== trim( (string) get_option( 'tet_notify_live_voice_text', '' ) ) ) {
			return '';
		}
		$lang = strtolower( substr( get_user_locale(), 0, 2 ) );
		$file = 'assets/sounds/new-order-' . sanitize_file_name( $lang ) . '.mp3';
		return file_exists( TET_NOTIFY_PATH . $file ) ? esc_url_raw( TET_NOTIFY_URL . $file ) : '';
	}

	/** Bell in the admin bar: state, mute toggle, "enable sound" / desktop prompts (filled by JS). */
	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! is_admin() || ! self::user_allowed() ) {
			return;
		}
		$bar->add_node( [
			'id'    => 'tet-notify',
			'title' => '<span class="ab-icon dashicons dashicons-bell" aria-hidden="true"></span><span class="ab-label tet-notify-ab-label"></span>',
			'href'  => '#',
			'meta'  => [ 'class' => 'tet-notify-ab' ],
		] );
		$bar->add_node( [
			'parent' => 'tet-notify',
			'id'     => 'tet-notify-mute',
			'title'  => esc_html( self::is_muted() ? __( 'Unmute', 'tet-order-notifications' ) : __( 'Mute', 'tet-order-notifications' ) ),
			'href'   => '#',
		] );
		$bar->add_node( [
			'parent' => 'tet-notify',
			'id'     => 'tet-notify-play',
			'title'  => esc_html__( 'Play the sound', 'tet-order-notifications' ),
			'href'   => '#',
		] );
		$bar->add_node( [
			'parent' => 'tet-notify',
			'id'     => 'tet-notify-desktop',
			'title'  => esc_html__( 'Enable desktop notifications', 'tet-order-notifications' ),
			'href'   => '#',
		] );
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$bar->add_node( [
				'parent' => 'tet-notify',
				'id'     => 'tet-notify-settings',
				'title'  => esc_html__( 'Settings', 'tet-order-notifications' ),
				'href'   => Tet_Notify_Settings::url( 'alerts' ),
			] );
		}
	}

	/* ── REST ───────────────────────────────────────────────── */

	public static function register_routes(): void {
		register_rest_route( self::REST_NS, '/events', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'rest_events' ],
			'permission_callback' => static function () {
				return self::user_allowed();
			},
			'args'                => [
				'after' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
			],
		] );
	}

	public static function rest_events( WP_REST_Request $request ): WP_REST_Response {
		$after  = (int) $request->get_param( 'after' );
		$events = Tet_Notify_Events::after( $after, 10 );
		$out    = [];
		$last   = $after;
		foreach ( $events as $event ) {
			$last  = max( $last, $event['id'] );
			$order = wc_get_order( $event['order_id'] );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$d     = Tet_Notify_Order_Data::from_order( $order );
			$out[] = [
				'id'       => $event['id'],
				'number'   => $d['order_number'],
				'total'    => $d['total'],
				'customer' => $d['customer_name'],
				'city'     => $d['city'],
				'payment'  => $d['payment_method'],
				'items'    => $d['items'],
				'url'      => $d['order_url'],
			];
		}
		$response = new WP_REST_Response( [ 'last' => $last, 'events' => $out ] );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/* ── AJAX ───────────────────────────────────────────────── */

	public static function ajax_mute(): void {
		check_ajax_referer( 'tet_notify_mute', 'nonce' );
		if ( ! self::user_allowed() ) {
			wp_send_json_error( null, 403 );
		}
		$muted = ! empty( $_POST['muted'] ) && '1' === $_POST['muted'];
		update_user_meta( get_current_user_id(), self::META_MUTE, $muted ? 'yes' : 'no' );
		wp_send_json_success( [ 'muted' => $muted ] );
	}

	/**
	 * A fresh REST nonce for a tab left open longer than the nonce lifetime.
	 * Cookie-authenticated; a cross-site page can't read the answer.
	 */
	public static function ajax_nonce(): void {
		if ( ! self::user_allowed() ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
	}
}
