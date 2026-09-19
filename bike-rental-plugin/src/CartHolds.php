<?php
/** Cart-bound hold cleanup. Never destroys customer sessions or financial records. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class CartHolds {
	const SESSION = 'brp_cart_holds';
	public static function register_hooks() {
		add_action( 'woocommerce_cart_item_removed', array( self::class, 'removed' ), 5, 2 );
		add_action( 'woocommerce_before_cart_emptied', array( self::class, 'before_empty' ), 5, 0 );
		add_action( 'woocommerce_cart_emptied', array( self::class, 'reconcile' ), 5, 0 );
		add_action( 'woocommerce_load_cart_from_session', array( self::class, 'remember_session' ), 5 );
		add_action( 'woocommerce_cart_loaded_from_session', array( self::class, 'reconcile' ), 1000 );
	}
	private static function binding( $item ) {
		$id = $item['brp']['id'] ?? null; $fingerprint = $item['brp']['fingerprint'] ?? null;
		if ( ! Database::positive( $id ) || ! Database::positive( $item['product_id'] ?? null ) || ! is_string( $fingerprint ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $fingerprint ) ) { return null; }
		return array( 'brp' => array( 'id' => (int) $id, 'fingerprint' => $fingerprint ), 'product_id' => (int) $item['product_id'] );
	}
	private static function tracked() { $value = WC()->session ? WC()->session->get( self::SESSION, array() ) : array(); return is_array( $value ) ? $value : array(); }
	private static function save( $items ) { if ( WC()->session ) { WC()->session->set( self::SESSION, $items ?: null ); } }
	private static function remember( $items ) {
		$tracked = self::tracked();
		foreach ( (array) $items as $item ) { $binding = self::binding( $item ); if ( $binding ) { $tracked[ $binding['brp']['id'] ] = $binding; } }
		self::save( $tracked );
	}
	public static function remember_cart() { if ( WC()->cart ) { self::remember( WC()->cart->get_cart_contents() ); } }
	public static function remember_session() { if ( WC()->session ) { self::remember( WC()->session->get( 'cart', array() ) ); } }
	public static function before_empty() { self::remember_session(); self::remember_cart(); }
	public static function removed( $key, $cart ) {
		$item = $cart->removed_cart_contents[ $key ] ?? null;
		if ( $item && self::binding( $item ) ) { self::remember( array( $item ) ); self::release( self::binding( $item ), $cart ); }
	}
	/** Compare exact bindings, never the guest's latest reservation. Also upgrades existing carts. */
	public static function reconcile( $cart = null ) {
		$cart = $cart ?: WC()->cart; if ( ! $cart || ! WC()->session ) { return; }
		$present = array();
		foreach ( $cart->get_cart_contents() as $item ) { $binding = self::binding( $item ); if ( $binding ) { $present[ $binding['brp']['id'] ] = $binding; } }
		foreach ( self::tracked() as $item ) {
			$binding = self::binding( $item );
			if ( $binding && ( $present[ $binding['brp']['id'] ] ?? null ) !== $binding ) { self::release( $binding, $cart ); }
		}
		self::remember( $present );
	}
	private static function release( $binding, $cart ) {
		$id = $binding['brp']['id'];
		$result = CheckoutReservation::release_cart_hold( $id, GuestSession::identity()['hash'] ?? null, $binding['product_id'], $binding['brp']['fingerprint'] );
		// Keep failed cleanup discoverable for the next cart load. Normal timeout remains a backstop.
		if ( is_wp_error( $result ) && 'brp_retry' === $result->get_error_code() ) { return; }
		$tracked = self::tracked();
		if ( ( $tracked[ $id ] ?? null ) === $binding ) { unset( $tracked[ $id ] ); self::save( $tracked ); }
		if ( is_wp_error( $result ) ) { return; }
		foreach ( array( 'store_api_draft_order', 'order_awaiting_payment' ) as $key ) {
			$order_id = WC()->session->get( $key ); $order = $order_id ? wc_get_order( $order_id ) : false;
			if ( $order && (int) $order->get_meta( '_brp_reservation_id' ) === (int) $id ) { WC()->session->__unset( $key ); }
		}
		// A removed rental must re-enter through booking validation; ordinary Undo data is retained.
		foreach ( $cart->removed_cart_contents as $key => $item ) { if ( self::binding( $item ) === $binding ) { unset( $cart->removed_cart_contents[ $key ] ); } }
	}
}
