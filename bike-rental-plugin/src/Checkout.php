<?php
/** WooCommerce Checkout Block bridge. Guest holds are the only rental cart entry point. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class Checkout {
	private static $adding = false;
	public static function register_hooks() {
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'allow_add' ), 100, 3 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', static function ( $product ) { if ( Packages::is_rental_package( $product ) ) { self::raise( CheckoutReservation::error() ); } }, 100 );
		add_action( 'woocommerce_store_api_validate_cart_item', static function ( $product, $item ) { $row = self::validate_item( $item ); if ( is_wp_error( $row ) ) { self::raise( $row ); } }, 100, 2 );
		add_action( 'woocommerce_check_cart_items', array( self::class, 'cart_notices' ) );
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'catalog_prices' ), 100 );
		add_filter( 'woocommerce_get_item_data', array( self::class, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', static fn( $html, $key, $item ) => self::is_rental_item( $item ) ? esc_html( $item['quantity'] ) : $html, 10, 3 );
		add_filter( 'woocommerce_update_cart_validation', static fn( $valid, $key, $item, $quantity ) => self::is_rental_item( $item ) && $quantity > 0 && (string) $quantity !== (string) $item['quantity'] ? false : $valid, 100, 4 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', static fn( $value, $product ) => Packages::is_rental_package( $product ) ? false : $value, 10, 2 );
		foreach ( array( 'minimum', 'maximum' ) as $limit ) {
			add_filter( 'woocommerce_store_api_product_quantity_' . $limit, static fn( $value, $product, $item ) => $item && self::is_rental_item( $item ) ? (int) $item['quantity'] : $value, 10, 3 );
		}
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( self::class, 'order_meta' ), 50 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'submit_order' ), 50 );
		add_action( 'woocommerce_before_pay_action', array( self::class, 'pay_order' ), 5 );
		add_action( 'woocommerce_after_checkout_validation', static function ( $data, $errors ) { if ( self::has_rental_cart() ) { $errors->add( 'brp_checkout', 'Rental checkout requires the WooCommerce Checkout Block. Please contact the shop.' ); } }, 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( self::class, 'gateways' ), 100 );
		add_filter( 'woocommerce_coupon_is_valid', static function ( $valid ) { if ( self::has_rental_cart() ) { throw new \Exception( 'Coupons are not available for rental checkout.' ); } return $valid; }, 100 );
		// Woo/Square retail stock is not the shared rental fleet. Never synchronize fleet quantities.
		add_filter( 'woocommerce_product_get_manage_stock', static fn( $value, $product ) => Packages::is_rental_package( $product ) ? false : $value, 100, 2 );
		add_filter( 'woocommerce_product_is_in_stock', static fn( $value, $product ) => Packages::is_rental_package( $product ) ? true : $value, 100, 2 );
		add_filter( 'woocommerce_is_virtual', static fn( $value, $product ) => Packages::is_rental_package( $product ) ? false : $value, 100, 2 );
	}
	public static function raise( $error ) { throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'brp_checkout', $error->get_error_message(), 400 ); }
	public static function is_rental_item( $item ) { return isset( $item['brp'] ) || Packages::is_rental_package( $item['product_id'] ?? 0 ); }
	public static function has_rental_cart() {
		if ( ! WC()->cart ) { return false; }
		foreach ( WC()->cart->get_cart() as $item ) { if ( self::is_rental_item( $item ) ) { return true; } }
		return false;
	}
	public static function allow_add( $allowed, $product_id, $quantity ) {
		if ( Packages::is_rental_package( $product_id ) && ! self::$adding ) { wc_add_notice( 'Please use the rental booking form to reserve this package before checkout.', 'error' ); return false; }
		return $allowed;
	}
	/** Protected REST action accepts only an idempotency key, never an ID/price/schedule. */
	public static function transfer( $key ) {
		global $wpdb;
		$identity = GuestSession::identity();
		if ( ! $identity || ! is_string( $key ) || ! preg_match( '/\A[a-zA-Z0-9_-]{1,64}\z/', $key ) || ! function_exists( 'wc_load_cart' ) ) { return CheckoutReservation::error(); }
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE request_key = %s AND session_hash = %s', Database::table( 'reservations' ), strtolower( $key ), $identity['hash'] ) );
		if ( $wpdb->last_error || ! $id ) { return CheckoutReservation::error(); }
		$row = CheckoutReservation::prepare( $id, $identity['hash'] ); if ( is_wp_error( $row ) ) { return $row; }
		wc_load_cart();
		$existing = false;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( (int) ( $item['brp']['id'] ?? 0 ) !== (int) $id ) { return Database::error( 'checkout', 'Please finish or empty your existing cart before continuing this rental checkout.' ); }
			$valid = self::validate_item( $item ); if ( is_wp_error( $valid ) ) { return $valid; } $existing = true;
		}
		if ( ! $existing ) {
			self::$adding = true;
			try { $added = WC()->cart->add_to_cart( $row['package_product_id'], (int) $row['quantity'], 0, array(), array( 'brp' => array( 'id' => (int) $id, 'fingerprint' => CheckoutReservation::fingerprint( $row ) ) ) ); }
			finally { self::$adding = false; }
			if ( ! $added ) { return Database::error( 'checkout', 'The rental could not be added to checkout. Please contact the shop.' ); }
		}
		if ( $row['order_id'] ) { WC()->session->set( 'store_api_draft_order', (int) $row['order_id'] ); }
		WC()->session->set_customer_session_cookie( true ); WC()->cart->calculate_totals(); WC()->session->save_data();
		return array( 'valid' => true, 'checkout_url' => wc_get_checkout_url() );
	}
	public static function validate_item( $item ) {
		if ( ! self::is_rental_item( $item ) ) { return null; }
		$id = $item['brp']['id'] ?? null; $fingerprint = $item['brp']['fingerprint'] ?? null;
		if ( ! Database::positive( $id ) || ! is_string( $fingerprint ) ) { return CheckoutReservation::error(); }
		$row = CheckoutReservation::owned( $id, GuestSession::identity()['hash'] ?? null, $item['product_id'] ?? null, $item['quantity'] ?? null, $fingerprint );
		if ( is_wp_error( $row ) ) { return $row; }
		$s = json_decode( $row['snapshot'], true ); $valid = PaymentMode::validate_provider( $s['payment_mode'] ?? '' );
		return is_wp_error( $valid ) ? $valid : $row;
	}
	public static function cart_row() {
		$row = null; $items = WC()->cart ? WC()->cart->get_cart() : array();
		foreach ( $items as $item ) {
			$valid = self::validate_item( $item ); if ( is_wp_error( $valid ) ) { return $valid; }
			if ( $valid ) { if ( $row || count( $items ) !== 1 || WC()->cart->get_applied_coupons() ) { return Database::error( 'checkout', 'Checkout one rental reservation at a time, without coupons or other cart items.' ); } $row = $valid; }
		}
		return $row;
	}
	public static function cart_notices() { $row = self::cart_row(); if ( is_wp_error( $row ) ) { wc_add_notice( $row->get_error_message(), 'error' ); } }
	public static function catalog_prices( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( ! self::is_rental_item( $item ) ) { continue; }
			$product = wc_get_product( $item['product_id'] ); if ( $product ) { $item['data']->set_price( $product->get_price() ); }
		}
	}
	public static function details( $row ) {
		$s = json_decode( $row['snapshot'], true ); $zone = new \DateTimeZone( $row['timezone'] );
		$start = ( new \DateTimeImmutable( $row['start_utc'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $zone );
		$end = ( new \DateTimeImmutable( $row['end_utc'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $zone );
		return array( 'Reservation Reference' => $row['reference'], 'Rental Package' => $s['name'], 'Rental Quantity' => (int) $row['quantity'], 'Rental Start' => $start->format( 'M j, Y g:i A' ) . ' (' . $row['timezone'] . ')', 'Rental End' => $end->format( 'M j, Y g:i A' ) . ' (' . $row['timezone'] . ')', 'Delivery Time' => $start->format( 'g:i A' ), 'Rental Duration' => $s['duration_amount'] . ( 'hours' === $s['duration_type'] ? ' hours' : ' calendar days' ), 'Payment Mode' => PaymentMode::label( $s['payment_mode'] ?? '' ) ) + ( empty( $s['promotional_label'] ) ? array() : array( 'Rental Offer' => $s['promotional_label'] ) );
	}
	public static function item_data( $data, $item ) {
		$row = self::validate_item( $item );
		if ( is_wp_error( $row ) ) { $data[] = array( 'key' => 'Rental', 'value' => esc_html( $row->get_error_message() ) ); }
		elseif ( $row ) { foreach ( self::details( $row ) as $key => $value ) { $data[] = array( 'key' => $key, 'value' => esc_html( $value ) ); } }
		return $data;
	}
	public static function rental_order( $order ) {
		if ( $order->get_meta( '_brp_reservation_id' ) ) { return true; }
		foreach ( $order->get_items() as $item ) { if ( Packages::is_rental_package( $item->get_product_id() ) ) { return true; } }
		return false;
	}
	public static function order_meta( $order ) {
		$row = self::cart_row(); if ( is_wp_error( $row ) ) { self::raise( $row ); }
		if ( ! $row ) { if ( self::rental_order( $order ) ) { self::raise( CheckoutReservation::error() ); } return; }
		if ( $row['order_id'] && (int) $row['order_id'] !== $order->get_id() ) { self::raise( CheckoutReservation::error() ); }
		$items = $order->get_items(); if ( count( $items ) !== 1 ) { self::raise( CheckoutReservation::error() ); }
		$item = reset( $items );
		if ( (int) $item->get_product_id() !== (int) $row['package_product_id'] || (string) $item->get_quantity() !== (string) $row['quantity'] ) { self::raise( CheckoutReservation::error() ); }
		foreach ( array( 'reservation_id' => (int) $row['id'], 'reservation_reference' => $row['reference'], 'package_product_id' => (int) $row['package_product_id'], 'quantity' => (int) $row['quantity'], 'start_utc' => $row['start_utc'], 'end_utc' => $row['end_utc'], 'snapshot' => json_decode( $row['snapshot'], true ), 'fingerprint' => CheckoutReservation::fingerprint( $row ), 'payment_mode' => json_decode( $row['snapshot'], true )['payment_mode'] ) as $key => $value ) { $order->update_meta_data( '_brp_' . $key, $value ); }
		foreach ( self::details( $row ) as $key => $value ) { $item->update_meta_data( $key, sanitize_text_field( (string) $value ) ); }
		$item->save(); $order->save();
	}
	public static function submit_order( $order ) {
		if ( ! self::rental_order( $order ) ) { return; }
		$row = self::cart_row(); if ( is_wp_error( $row ) || ! $row ) { self::raise( is_wp_error( $row ) ? $row : CheckoutReservation::error() ); }
		self::pay_order( $order );
	}
	/** WooCommerce validates its pay-for-order nonce/key before firing this action. */
	public static function pay_order( $order ) {
		if ( ! self::rental_order( $order ) ) { return; }
		$valid = PaymentMode::validate_provider( $order->get_meta( '_brp_payment_mode' ) ); if ( is_wp_error( $valid ) ) { self::raise( $valid ); }
		$items = $order->get_items(); if ( count( $items ) !== 1 || $order->get_coupon_codes() || (float) $order->get_total() <= 0 ) { self::raise( CheckoutReservation::error() ); }
		$item = reset( $items );
		$row = CheckoutReservation::owned( $order->get_meta( '_brp_reservation_id' ), GuestSession::identity()['hash'] ?? null, $item->get_product_id(), $item->get_quantity(), $order->get_meta( '_brp_fingerprint' ) );
		if ( is_wp_error( $row ) ) { self::raise( $row ); }
		if ( $order->get_currency() !== json_decode( $row['snapshot'], true )['currency'] ) { self::raise( CheckoutReservation::error() ); }
		$result = CheckoutReservation::begin_payment( $row['id'], GuestSession::identity()['hash'], $order->get_id(), $item->get_id(), $order->get_meta( '_brp_fingerprint' ) );
		if ( is_wp_error( $result ) ) { self::raise( $result ); }
	}
	public static function gateways( $gateways ) {
		$rental = self::has_rental_cart();
		if ( ! $rental && is_wc_endpoint_url( 'order-pay' ) ) { $order = wc_get_order( absint( get_query_var( 'order-pay' ) ) ); $rental = $order && self::rental_order( $order ); }
		return $rental ? array_intersect_key( $gateways, array( 'square_credit_card' => true ) ) : $gateways;
	}
}
