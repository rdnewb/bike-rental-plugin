<?php
/** Real Woo cart lifecycle and rental transactions; disposable DB only. No payment requests. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
use BikeRentalPlugin\{Database, Settings, Packages, Reservations, Checkout, CheckoutReservation, CartHolds, GuestSession, Availability, PublicBooking, Payments};
add_filter( 'pre_wp_mail', '__return_false' );
wc_load_cart();
$checks = 0; $orders = array();
function hcheck( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo 'PASS: ' . $label . PHP_EOL; }
function hrow( $id ) { return Database::public_booking( static fn() => Reservations::read( $id ) ); }
function hreset() {
	global $wpdb; WC()->cart->empty_cart(); WC()->session->__unset( CartHolds::SESSION ); wc_clear_notices();
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'reservations' ) ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <> 1', Database::table( 'availability' ) ) );
	$wpdb->update( Database::table( 'availability' ), array( 'quantity' => 1 ), array( 'id' => 1 ) );
	unset( $_COOKIE[ GuestSession::cookie_name() ] ); GuestSession::start();
}
function hhold( $transfer = true ) {
	$row = Database::public_booking( static fn() => Reservations::create_booking_hold( array( 'package_id' => $GLOBALS['product']->get_id(), 'quantity' => 1, 'date' => $GLOBALS['date'], 'time' => '09:00' ), bin2hex( random_bytes( 16 ) ), GuestSession::identity()['hash'] ) );
	if ( is_wp_error( $row ) ) { throw new RuntimeException( $row->get_error_message() ); }
	if ( $transfer ) { $result = Checkout::transfer( $row['request_key'] ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); } }
	return hrow( $row['id'] );
}
function hkey() { foreach ( WC()->cart->get_cart_contents() as $key => $item ) { if ( isset( $item['brp'] ) ) { return $key; } } return null; }
function hreceipt( $row ) {
	$r = new WP_REST_Request( 'POST', '/' . PublicBooking::API . '/hold-status' );
	$r->set_header( 'origin', home_url() ); $r->set_header( 'x-brp-request', '1' ); $r->set_header( 'x-brp-token', GuestSession::identity()['token'] ); $r->set_param( 'request_key', $row['request_key'] );
	return rest_get_server()->dispatch( $r )->get_data();
}
function horder( $row, $status = 'pending', $link = true ) {
	$o = wc_create_order(); $GLOBALS['orders'][] = $o->get_id(); $item_id = $o->add_product( $GLOBALS['product'], 1 ); $o->set_payment_method( 'square_credit_card' ); $o->calculate_totals();
	$o->update_meta_data( '_brp_reservation_id', $row['id'] ); $o->update_meta_data( '_brp_reservation_reference', $row['reference'] ); $o->update_meta_data( '_brp_payment_mode', 'full' ); $o->update_meta_data( '_brp_fingerprint', CheckoutReservation::fingerprint( $row ) ); $o->set_status( $status ); $o->save();
	if ( $link ) { $result = CheckoutReservation::begin_payment( $row['id'], GuestSession::identity()['hash'], $o->get_id(), $item_id, CheckoutReservation::fingerprint( $row ) ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); } }
	return $o;
}
$settings = Settings::defaults(); foreach ( $settings['weekly_hours'] as &$hours ) { $hours = array( 'open' => 1, 'start' => '08:00', 'end' => '18:00' ); } unset( $hours );
update_option( Settings::OPTION, $settings ); update_option( 'timezone_string', 'America/New_York' );
$date = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+7 days' )->format( 'Y-m-d' );
$product = new WC_Product_Simple(); $product->set_name( 'Cart release fixture' ); $product->set_status( 'publish' ); $product->set_regular_price( '29.99' ); $product->set_tax_status( 'none' );
foreach ( array( Packages::ENABLED => 'yes', Packages::ACTIVE => 'yes', Packages::TYPE => 'hours', Packages::AMOUNT => 4 ) as $key => $value ) { $product->update_meta_data( $key, $value ); } $product->save();
$normal = new WC_Product_Simple(); $normal->set_name( 'Ordinary cart fixture' ); $normal->set_status( 'publish' ); $normal->set_regular_price( '5' ); $normal->save();
try {
	wp_set_current_user( 0 ); hreset(); $row = hhold(); $key = hkey();
	hcheck( $key && $row['status'] === 'hold', 'rental hold and matching cart item exist' );
	hcheck( ! empty( WC()->session->get( CartHolds::SESSION )[$row['id']] ), 'successful transfer records exact binding' );
	$identity = GuestSession::identity(); WC()->session->set( 'fixture_customer_preference', 'retain' );
	WC()->cart->remove_cart_item( $key ); $released = hrow( $row['id'] );
	hcheck( $released['status'] === 'cancelled' && $released['issue_code'] === 'cart_removed', 'remove action cancels temporary hold with reason' );
	hcheck( $released['hold_expires_at'] === null && (int) $released['revision'] === (int) $row['revision'] + 1, 'expiry cleared and revision incremented once' );
	hcheck( $released['reference'] === $row['reference'] && $released['created_at'] === $row['created_at'] && $released['session_hash'] === $row['session_hash'], 'audit identifiers and original ownership retained' );
	$usage = Database::public_booking( static fn() => Availability::check( $row['occupied_start_utc'], $row['occupied_end_utc'] ) );
	hcheck( $usage['available_quantity'] === 1, 'removed hold releases inventory immediately' );
	$receipt = hreceipt( $row ); hcheck( ! $receipt['reserved'] && $receipt['reservation_status'] === 'cancelled', 'owned public receipt reports cancellation, never active hold' );
	hcheck( ! WC()->session->get( CartHolds::SESSION ) && GuestSession::identity() === $identity && WC()->session->get( 'fixture_customer_preference' ) === 'retain', 'only binding cleared; guest cookie/token and unrelated session preserved' );
	hcheck( ! WC()->cart->restore_cart_item( $key ), 'Undo cannot restore cancelled rental' );
	do_action( 'woocommerce_cart_item_removed', $key, WC()->cart ); do_action( 'woocommerce_cart_emptied' );
	hcheck( hrow( $row['id'] ) === $released, 'duplicate remove/empty callbacks are idempotent' );
	$new = hhold(); hcheck( $new['id'] !== $row['id'] && hkey(), 'same interval can immediately create and transfer a fresh hold' );
	do_action( 'woocommerce_cart_item_removed', $key, WC()->cart ); hcheck( hrow( $new['id'] ) === $new, 'old removal callback cannot release the new reservation' );
	hcheck( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 ), 'direct rental add-to-cart still blocked' ); wc_clear_notices();
	foreach ( array( true, false ) as $persistent ) { hreset(); $row = hhold(); WC()->cart->empty_cart( $persistent ); hcheck( hrow( $row['id'] )['status'] === 'cancelled', 'programmatic cart empty releases hold; persistent=' . (int) $persistent ); }
	hreset(); $row = hhold(); $rental_key = hkey(); $normal_key = WC()->cart->add_to_cart( $normal->get_id() );
	hcheck( $normal_key && count( WC()->cart->get_cart_contents() ) === 2, 'ordinary product add remains normal' );
	WC()->cart->remove_cart_item( $normal_key ); hcheck( hrow( $row['id'] ) === $row && isset( WC()->cart->get_cart_contents()[$rental_key] ), 'removing ordinary item preserves rental hold and item' );
	WC()->cart->restore_cart_item( $normal_key ); WC()->cart->remove_cart_item( $rental_key );
	hcheck( isset( WC()->cart->get_cart_contents()[$normal_key] ) && hrow( $row['id'] )['status'] === 'cancelled', 'rental removal preserves other cart items' );
	foreach ( array( 'confirmed', 'active', 'completed', 'expired', 'cancelled' ) as $status ) {
		hreset(); $row = hhold(); // Direct fixture setup isolates finalized-state protection, not status transition tests.
		$wpdb->update( Database::table( 'reservations' ), array( 'status' => $status ), array( 'id' => $row['id'] ) ); $before = hrow( $row['id'] );
		WC()->cart->remove_cart_item( hkey() ); hcheck( hrow( $row['id'] ) === $before, $status . ' reservation never changed by removal' );
	}
	hreset(); $row = hhold(); $item = WC()->cart->get_cart_contents()[hkey()]; $owner = GuestSession::identity()['hash'];
	foreach ( array( array( hash( 'sha256', 'other guest' ), $product->get_id(), $item['brp']['fingerprint'] ), array( $owner, $normal->get_id(), $item['brp']['fingerprint'] ), array( $owner, $product->get_id(), str_repeat( 'a', 64 ) ) ) as $bad ) {
		hcheck( is_wp_error( CheckoutReservation::release_cart_hold( $row['id'], ...$bad ) ) && hrow( $row['id'] ) === $row, 'forged owner/product/fingerprint cannot release another hold' );
	}
	CartHolds::reconcile(); hcheck( hrow( $row['id'] ) === $row, 'ordinary cart load/navigation does not release present rental' );
	WC()->cart->set_cart_contents( array() ); do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );
	hcheck( hrow( $row['id'] )['status'] === 'cancelled', 'tracked rental disappearing during cart reset releases hold' );
	hreset(); $row = hhold(); WC()->session->__unset( CartHolds::SESSION ); // Existing 0.6.1 cart migration.
	$stored = WC()->cart->get_cart_contents(); foreach ( $stored as &$item ) { unset( $item['data'] ); } unset( $item ); WC()->session->set( 'cart', $stored );
	do_action( 'woocommerce_load_cart_from_session' ); WC()->cart->set_cart_contents( array() ); do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );
	hcheck( hrow( $row['id'] )['status'] === 'cancelled', 'legacy cart discarded during restoration is released' );
	hreset(); $row = hhold(); WC()->session->__unset( CartHolds::SESSION ); WC()->cart->set_cart_contents( array() ); WC()->cart->empty_cart();
	hcheck( hrow( $row['id'] )['status'] === 'cancelled', 'emptying a not-yet-loaded legacy cart reads its serialized binding' );
	foreach ( array( 'receipt', 'transfer' ) as $route ) {
		hreset(); $row = hhold(); WC()->session->set( 'cart', array() ); WC()->cart->set_cart_contents( array() );
		// Model a fresh REST request before Woo has lazily loaded its serialized cart.
		$loaded_count = $GLOBALS['wp_actions']['woocommerce_load_cart_from_session'] ?? 0;
		unset( $GLOBALS['wp_actions']['woocommerce_load_cart_from_session'] );
		try { $response = 'receipt' === $route ? hreceipt( $row ) : Checkout::transfer( $row['request_key'] ); }
		finally { $GLOBALS['wp_actions']['woocommerce_load_cart_from_session'] = $loaded_count; }
		hcheck( hrow( $row['id'] )['status'] === 'cancelled' && WC()->cart->is_empty() && ( 'receipt' === $route ? ! $response['reserved'] : is_wp_error( $response ) ), $route . ' reconciles lazy cart before restoring or preparing hold' );
	}
	hreset(); $row = hhold(); $product->set_status( 'draft' ); $product->save();
	WC()->cart->set_cart_contents( array() ); $restoration = new WC_Cart_Session( WC()->cart ); $restoration->get_cart_from_session();
	hcheck( hrow( $row['id'] )['status'] === 'cancelled' && WC()->cart->is_empty(), 'actual Woo session restoration drops invalid product and releases binding' );
	$product->set_status( 'publish' ); $product->save();
	hreset(); $row = hhold(); $key = hkey();
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/remove-item' ); $request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) ); $request->set_param( 'key', $key );
	$response = rest_get_server()->dispatch( $request );
	hcheck( $response->get_status() === 200 && hrow( $row['id'] )['status'] === 'cancelled', 'Checkout Block Store API Remove invokes immediate cleanup' );
	hreset(); $row = hhold(); $key = hkey(); $original_db = $wpdb;
	$wpdb = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
		public function query( $query ) { if ( str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, 'brp_reservations' ) ) { $this->last_error = 'fixture write failure'; return false; } return parent::query( $query ); }
	}; $wpdb->set_prefix( 'm3_' );
	try { WC()->cart->remove_cart_item( $key ); } finally { $wpdb->close(); $wpdb = $original_db; }
	hcheck( hrow( $row['id'] )['status'] === 'hold' && WC()->session->get( CartHolds::SESSION ), 'failed database cleanup retains retry binding without a partial transition' );
	CartHolds::reconcile(); hcheck( hrow( $row['id'] )['status'] === 'cancelled' && ! WC()->session->get( CartHolds::SESSION ), 'next cart reconciliation retries failed removal safely' );
	hreset(); $row = hhold( false ); WC()->cart->empty_cart(); CartHolds::reconcile();
	hcheck( hrow( $row['id'] ) === $row, 'untransferred guest hold is not guessed from latest reservation' );
	hreset(); $row = hhold(); $order = horder( $row ); WC()->session->set( 'store_api_draft_order', $order->get_id() ); WC()->session->set( 'order_awaiting_payment', $order->get_id() );
	WC()->cart->remove_cart_item( hkey() );
	hcheck( hrow( $row['id'] )['status'] === 'cancelled' && ! WC()->session->get( 'store_api_draft_order' ) && ! WC()->session->get( 'order_awaiting_payment' ), 'unpaid linked order releases hold and matching checkout pointers' );
	hcheck( wc_get_order( $order->get_id() )->get_status() === 'pending' && (int) hrow( $row['id'] )['order_id'] === $order->get_id(), 'order and audit linkage are preserved' );
	$order->update_meta_data( '_wc_square_credit_card_charge_captured', 'yes' ); $order->update_meta_data( '_wc_square_credit_card_authorization_amount', $order->get_total() ); $order->save(); $order->payment_complete( 'late-fixture' );
	hcheck( hrow( $row['id'] )['status'] === 'cancelled' && hrow( $row['id'] )['issue_code'] === 'payment_staff_review', 'late payment after removal follows existing staff-review policy' );
	hreset(); $row = hhold(); $draft = horder( $row, 'checkout-draft', false ); WC()->session->set( 'store_api_draft_order', $draft->get_id() );
	WC()->cart->remove_cart_item( hkey() ); hcheck( ! WC()->session->get( 'store_api_draft_order' ) && wc_get_order( $draft->get_id() ), 'unclaimed matching Checkout Block draft pointer cleared without deleting order' );
	hreset(); $row = hhold(); $unrelated = wc_create_order(); $orders[] = $unrelated->get_id(); WC()->session->set( 'order_awaiting_payment', $unrelated->get_id() );
	$normal_key = WC()->cart->add_to_cart( $normal->get_id() ); WC()->cart->remove_cart_item( hkey() );
	hcheck( (int) WC()->session->get( 'order_awaiting_payment' ) === $unrelated->get_id(), 'unrelated order pointer retained' );
	foreach ( array( 'on-hold', 'transaction', 'paid' ) as $evidence ) {
		hreset(); $row = hhold(); $order = horder( $row );
		if ( 'on-hold' === $evidence ) { $order->set_status( 'on-hold' ); }
		elseif ( 'transaction' === $evidence ) { $order->set_transaction_id( 'authorization-fixture' ); }
		else { $order->set_date_paid( time() ); }
		$order->save(); $before = hrow( $row['id'] ); WC()->cart->empty_cart();
		hcheck( hrow( $row['id'] ) === $before, 'payment/authorization cart empty protected: ' . $evidence );
	}
	hreset(); $row = hhold(); $order = horder( $row ); $order->update_meta_data( '_wc_square_credit_card_charge_captured', 'yes' ); $order->update_meta_data( '_wc_square_credit_card_authorization_amount', $order->get_total() ); $order->save(); $order->payment_complete( 'captured-fixture' );
	$before = hrow( $row['id'] ); WC()->cart->empty_cart();
	hcheck( $before['status'] === 'confirmed' && hrow( $row['id'] ) === $before, 'Full Payment confirmation survives normal Square-style cart empty' );
	hcheck( Database::VERSION === '2', 'schema is 2' );
} finally {
	wp_set_current_user( 1 ); WC()->cart->empty_cart(); WC()->session->__unset( CartHolds::SESSION );
	foreach ( $orders as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	$product->delete( true ); $normal->delete( true );
}
echo "Cart holds: $checks checks passed. No real Square payments.\n";
ob_end_flush();
