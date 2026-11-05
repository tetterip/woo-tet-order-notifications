<?php
defined( 'ABSPATH' ) || exit;

/**
 * Decides when an order is announced and sends it to the enabled channels in
 * the background (Action Scheduler), so the checkout never waits for Telegram & co.
 */
class Tet_Notify_Dispatcher {

	const SEND_HOOK = 'tet_notify_send';
	const META_SENT = '_tet_notify_sent';
	const GROUP     = 'tet-order-notifications';
	/** An order older than this is never announced, even if it changes status. */
	const MAX_AGE = 7 * DAY_IN_SECONDS;

	public static function init(): void {
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_status_changed' ], 20, 4 );
		add_action( 'woocommerce_new_order', [ __CLASS__, 'on_new_order' ], 20, 2 );
		add_action( self::SEND_HOOK, [ __CLASS__, 'send' ] );
	}

	public static function on_status_changed( $order_id, $from, $to, $order = null ): void {
		self::maybe_trigger( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
	}

	/** Orders created directly in a paid status (REST API, imports) never "change" status. */
	public static function on_new_order( $order_id, $order = null ): void {
		self::maybe_trigger( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
	}

	public static function maybe_trigger( $order ): void {
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			return;
		}
		if ( ! Tet_Notify_Channels::enabled() && ! Tet_Notify_Settings::yes( 'tet_notify_live_enabled', 'yes' ) ) {
			return;
		}
		$created = $order->get_date_created();
		$notify  = Tet_Notify_Message::should_notify(
			$order->get_status(),
			Tet_Notify_Settings::statuses(),
			'' !== (string) $order->get_meta( self::META_SENT ),
			$created ? $created->getTimestamp() : time(),
			(int) get_option( 'tet_notify_since', 0 ),
			time(),
			self::MAX_AGE,
			'admin' === $order->get_created_via(),
			Tet_Notify_Settings::yes( 'tet_notify_include_admin' )
		);
		/** Filters whether this order is announced now. */
		if ( ! apply_filters( 'tet_notify_should_notify', $notify, $order ) ) {
			return;
		}
		if ( ! Tet_Notify_Events::claim( $order->get_id() ) ) {
			return;
		}
		$order->update_meta_data( self::META_SENT, (string) time() );
		$order->save_meta_data();

		/** Fires once per order, when it is announced (admin alerts pick it up from here too). */
		do_action( 'tet_notify_new_order', $order );

		if ( ! Tet_Notify_Channels::enabled() ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::SEND_HOOK, [ 'order_id' => $order->get_id() ], self::GROUP );
		} else {
			$id = $order->get_id();
			add_action( 'shutdown', static function () use ( $id ) {
				self::send( $id );
			} );
		}
	}

	/** Action Scheduler callback: sends the order to every enabled channel. */
	public static function send( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$data   = Tet_Notify_Order_Data::from_order( $order );
		$failed = [];
		foreach ( Tet_Notify_Channels::enabled() as $channel ) {
			$result = Tet_Notify_Channels::send( $channel, Tet_Notify_Channels::config( $channel ), $data );
			$label  = Tet_Notify_Channels::labels()[ $channel ];
			if ( is_wp_error( $result ) ) {
				$failed[] = $label . ': ' . $result->get_error_message();
				self::log( 'error', sprintf( 'Order #%s, %s: %s', $order->get_order_number(), $label, $result->get_error_message() ) );
			} elseif ( Tet_Notify_Settings::yes( 'tet_notify_debug' ) ) {
				self::log( 'info', sprintf( 'Order #%s sent to %s.', $order->get_order_number(), $label ) );
			}
		}
		if ( $failed ) {
			/* translators: %s: list of channels with the error of each. */
			$order->add_order_note( sprintf( __( 'Order notification failed. %s', 'tet-order-notifications' ), implode( ' | ', $failed ) ) );
		}
	}

	private static function log( string $level, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, [ 'source' => 'tet-order-notifications' ] );
		}
	}
}
