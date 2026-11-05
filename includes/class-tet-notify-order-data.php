<?php
defined( 'ABSPATH' ) || exit;

/**
 * Turns a WC_Order into the flat, plain-text array the message templates,
 * channels and the admin alerts use.
 */
class Tet_Notify_Order_Data {

	public static function from_order( WC_Order $order ): array {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$items[] = [
				'name'  => wp_strip_all_tags( $item->get_name() ),
				'qty'   => (int) $item->get_quantity(),
				'total' => wc_format_decimal( (float) $item->get_total() + (float) $item->get_total_tax(), wc_get_price_decimals() ),
				'sku'   => ( is_callable( [ $item, 'get_product' ] ) && $item->get_product() ) ? (string) $item->get_product()->get_sku() : '',
			];
		}

		$name = trim( $order->get_formatted_billing_full_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_formatted_shipping_full_name() );
		}
		if ( $order->get_billing_company() ) {
			$name = $name ? $name . ' (' . $order->get_billing_company() . ')' : $order->get_billing_company();
		}

		$city    = $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();
		$country = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();
		$created = $order->get_date_created();

		$data = [
			'order_id'          => $order->get_id(),
			'order_number'      => (string) $order->get_order_number(),
			'status'            => wc_get_order_status_name( $order->get_status() ),
			'status_slug'       => $order->get_status(),
			'total'             => self::plain_price( (float) $order->get_total(), $order->get_currency() ),
			'total_raw'         => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
			'currency'          => $order->get_currency(),
			'payment_method'    => wp_strip_all_tags( $order->get_payment_method_title() ),
			'payment_method_id' => $order->get_payment_method(),
			'shipping_method'   => wp_strip_all_tags( $order->get_shipping_method() ),
			'customer_name'     => $name,
			'email'             => $order->get_billing_email(),
			'phone'             => $order->get_billing_phone(),
			'city'              => $city,
			'country'           => $country && isset( WC()->countries->countries[ $country ] ) ? html_entity_decode( WC()->countries->countries[ $country ], ENT_QUOTES, 'UTF-8' ) : (string) $country,
			'country_code'      => (string) $country,
			'items'             => Tet_Notify_Message::items_text( $items ),
			'items_list'        => $items,
			'item_count'        => (int) $order->get_item_count(),
			'customer_note'     => wp_strip_all_tags( $order->get_customer_note() ),
			'date'              => $created ? wc_format_datetime( $created, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
			'date_iso'          => $created ? $created->date( 'c' ) : '',
			'order_url'         => $order->get_edit_order_url(),
			'site_name'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'          => home_url( '/' ),
		];

		/**
		 * Filters the order data used by notifications (templates, channels, admin alerts).
		 * Add a key here and list it with the `tet_notify_template_tags` filter to use it as a tag.
		 */
		return apply_filters( 'tet_notify_order_data', $data, $order );
	}

	/** wc_price() as plain text: "12,50 €" without HTML or entities. */
	public static function plain_price( float $amount, string $currency ): string {
		$html = wc_price( $amount, [ 'currency' => $currency ] );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		return trim( str_replace( "\xC2\xA0", ' ', $text ) );
	}

	/** Sample data for test messages when the shop has no orders yet. */
	public static function sample(): array {
		$items = [
			[ 'name' => __( 'Sample product', 'tet-order-notifications' ), 'qty' => 2, 'total' => '30.00', 'sku' => 'SAMPLE-1' ],
			[ 'name' => __( 'Another product', 'tet-order-notifications' ), 'qty' => 1, 'total' => '12.50', 'sku' => '' ],
		];
		return [
			'order_id'          => 0,
			'order_number'      => '1234',
			'status'            => wc_get_order_status_name( 'processing' ),
			'status_slug'       => 'processing',
			'total'             => self::plain_price( 42.5, get_woocommerce_currency() ),
			'total_raw'         => '42.50',
			'currency'          => get_woocommerce_currency(),
			'payment_method'    => __( 'Cash on delivery', 'tet-order-notifications' ),
			'payment_method_id' => 'cod',
			'shipping_method'   => __( 'Courier', 'tet-order-notifications' ),
			'customer_name'     => __( 'Maria Papadopoulou', 'tet-order-notifications' ),
			'email'             => 'customer@example.com',
			'phone'             => '6900000000',
			'city'              => __( 'Athens', 'tet-order-notifications' ),
			'country'           => __( 'Greece', 'tet-order-notifications' ),
			'country_code'      => 'GR',
			'items'             => Tet_Notify_Message::items_text( $items ),
			'items_list'        => $items,
			'item_count'        => 3,
			'customer_note'     => '',
			'date'              => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'date_iso'          => gmdate( 'c' ),
			'order_url'         => admin_url( 'admin.php?page=wc-orders' ),
			'site_name'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'          => home_url( '/' ),
		];
	}
}
