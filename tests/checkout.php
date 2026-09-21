<?php
/** Real WooCommerce/WordPress/InnoDB integration. Square capture evidence is a fixture, NOT a sandbox charge. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
use BikeRentalPlugin\{Settings, Database, Packages, Reservations, Availability, GuestSession, PublicBooking, Checkout, CheckoutReservation, PaymentMode, Payments};
add_filter( 'pre_wp_mail', '__return_false' );
wc_load_cart();
$checks = 0;
function ccheck( $value, $label ) { if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo 'PASS: ' . $label . PHP_EOL; }
function cok( $value, $label ) { ccheck( ! is_wp_error( $value ), $label . ( is_wp_error( $value ) ? ': ' . $value->get_error_message() : '' ) ); return $value; }
function cthrows( $fn, $label ) { try { $fn(); } catch ( Exception $e ) { ccheck( true, $label ); return; } ccheck( false, $label ); }
function creset( $capacity = 3 ) {
	global $wpdb;
	WC()->cart->empty_cart(); wc_clear_notices(); WC()->session->set( 'store_api_draft_order', null );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'reservations' ) ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <> 1', Database::table( 'availability' ) ) );
	$wpdb->update( Database::table( 'availability' ), array( 'quantity' => $capacity ), array( 'id' => 1 ) );
	update_option( Settings::OPTION, $GLOBALS['settings'] );
}
function csession() { unset( $_COOKIE[ GuestSession::cookie_name() ] ); return GuestSession::start(); }
function chold( $quantity = 1 ) {
	$key = bin2hex( random_bytes( 16 ) );
	return cok( Database::public_booking( static fn() => brp_test_hold( array( 'package_id' => $GLOBALS['product']->get_id(), 'quantity' => $quantity, 'date' => $GLOBALS['date'], 'time' => '09:00' ), $key, GuestSession::identity()['hash'] ) ), 'create guest booking hold' );
}
function cread( $id ) { return Database::public_booking( static fn() => Reservations::read( $id ) ); }
function cexpire( $row ) { global $wpdb; $wpdb->update( Database::table( 'reservations' ), array( 'hold_expires_at' => '2000-01-01 00:00:00', 'status' => 'expired' ), array( 'id' => $row['id'] ) ); }
function corder() {
	$order = wc_create_order( array( 'created_via' => 'store-api' ) ); $GLOBALS['orders'][] = $order->get_id();
	foreach ( WC()->cart->get_cart() as $item ) { $order->add_product( wc_get_product( $item['product_id'] ), $item['quantity'] ); }
	$order->set_currency( 'USD' ); $order->set_payment_method( 'square_credit_card' );
	$order->set_address( array( 'country' => 'US', 'state' => 'FL', 'postcode' => '34205' ), 'billing' );
	$order->calculate_totals();
	do_action( 'woocommerce_store_api_checkout_update_order_meta', $order );
	do_action( 'woocommerce_store_api_checkout_order_processed', $order );
	return $order;
}
function cpaid( $order ) {
	$order->update_meta_data( '_wc_square_credit_card_charge_captured', 'yes' );
	$order->update_meta_data( '_wc_square_credit_card_authorization_amount', $order->get_total() ); $order->save();
	$order->payment_complete( 'fixture-square-' . $order->get_id() );
	return wc_get_order( $order->get_id() );
}
function crequest( $input, $token = '' ) {
	$request = new WP_REST_Request( 'POST', '/' . PublicBooking::API . '/checkout' );
	$request->set_header( 'origin', home_url() ); $request->set_header( 'x-brp-request', '1' ); $request->set_header( 'x-brp-token', $token );
	foreach ( $input as $key => $value ) { $request->set_param( $key, $value ); }
	return rest_get_server()->dispatch( $request );
}
$orders = array(); $settings = Settings::defaults();
foreach ( $settings['weekly_hours'] as &$hours ) { $hours = array( 'open' => 1, 'start' => '08:00', 'end' => '18:00' ); } unset( $hours );
update_option( 'timezone_string', 'America/New_York' ); update_option( 'woocommerce_currency', 'USD' );
$date = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+7 days' )->format( 'Y-m-d' );
$product = new WC_Product_Simple(); $product->set_name( 'Checkout <script>fixture</script>' ); $product->set_status( 'publish' ); $product->set_regular_price( '19.99' ); $product->set_tax_status( 'none' );
foreach ( array( Packages::ENABLED => 'yes', Packages::ACTIVE => 'yes', Packages::TYPE => 'hours', Packages::AMOUNT => 4 ) as $key => $value ) { $product->update_meta_data( $key, $value ); } $product->save();
$normal = new WC_Product_Simple(); $normal->set_name( 'Normal checkout fixture' ); $normal->set_status( 'publish' ); $normal->set_regular_price( '5' ); $normal->save();
try {
	creset();
	ccheck( 'full' === Settings::defaults()['payment_mode'], 'default Full Payment' );
	$legacy = $settings; unset( $legacy['payment_mode'] ); update_option( Settings::OPTION, $legacy );
	ccheck( 'full' === PaymentMode::configured() && Settings::get() === $legacy, 'legacy options default full without rewriting settings' );
	ccheck( ! Settings::validate( $legacy )['errors'], 'legacy scheduling settings remain valid' );
	cok( PaymentMode::validate_provider( 'full' ), 'Full Payment available without deposit plugin' );
	$deposit = $settings; $deposit['payment_mode'] = 'deposit'; update_option( Settings::OPTION, $deposit );
	ccheck( PaymentMode::configured() === 'deposit' && ! PaymentMode::is_deposit_mode_available(), 'Deposit selected but provider unavailable' );
	ob_start(); PaymentMode::admin_notice(); $notice = ob_get_clean(); ccheck( str_contains( $notice, 'no compatible deposit provider' ), 'admin Deposit warning visible' );
	foreach ( array( 'garbage', array(), 1 ) as $mode ) { $invalid = $settings; $invalid['payment_mode'] = $mode; ccheck( (bool) Settings::validate( $invalid )['errors'], 'invalid mode rejected' ); }
	wp_set_current_user( 0 ); csession(); $hold = chold();
	ccheck( is_wp_error( Checkout::transfer( $hold['request_key'] ) ) && WC()->cart->is_empty(), 'Deposit blocks transfer with no full-charge fallback' );
	ob_start(); PaymentMode::admin_notice(); ccheck( ob_get_clean() === '', 'configuration notice hidden from unauthorized guests' );
	creset(); $identity = csession(); $hold = chold( 3 );
	ccheck( crequest( array( 'request_key' => $hold['request_key'] ), 'bad-token' )->get_status() === 403, 'CSRF failure blocks transfer' );
	foreach ( array( 'reservation_id' => $hold['id'], 'price' => 1, 'quantity' => 1, 'start' => '2000-01-01' ) as $key => $value ) { ccheck( crequest( array( 'request_key' => $hold['request_key'], $key => $value ), $identity['token'] )->get_status() === 400, 'browser cannot supply ' . $key ); }
	$result = crequest( array( 'request_key' => $hold['request_key'] ), $identity['token'] );
	ccheck( $result->get_status() === 200 && $result->get_data()['checkout_url'] === wc_get_checkout_url(), 'owned hold transfers through protected REST route' );
	$items = WC()->cart->get_cart(); $item = reset( $items ); $key = key( $items );
	ccheck( $item['product_id'] === $product->get_id() && $item['quantity'] === 3, 'server selects product and held quantity' );
	$held = cread( $hold['id'] );
	ccheck( $held['reference'] === $hold['reference'] && $held['created_at'] === $hold['created_at'] && $held['hold_expires_at'] === $hold['hold_expires_at'], 'transfer preserves reference/created/deadline' );
	cok( Checkout::transfer( $hold['request_key'] ), 'transfer retry succeeds' );
	ccheck( count( WC()->cart->get_cart() ) === 1 && cread( $hold['id'] ) === $held, 'transfer retry changes neither cart nor reservation revision' );
	cok( Checkout::validate_item( $item ), 'guest cart item validated' );
	$other = $item; $other['product_id'] = $normal->get_id(); ccheck( is_wp_error( Checkout::validate_item( $other ) ), 'product mismatch rejected' );
	$other = $item; $other['quantity'] = 2; ccheck( is_wp_error( Checkout::validate_item( $other ) ), 'quantity mismatch rejected' );
	$other = $item; $other['brp']['id'] += 10000; ccheck( is_wp_error( Checkout::validate_item( $other ) ), 'forged reservation ID rejected' );
	$cookie = $_COOKIE[ GuestSession::cookie_name() ]; csession(); ccheck( is_wp_error( Checkout::validate_item( $item ) ), 'wrong session cannot hijack cart' ); ccheck( is_wp_error( Checkout::transfer( $hold['request_key'] ) ), 'wrong session cannot transfer hold' ); $_COOKIE[ GuestSession::cookie_name() ] = $cookie;
	$qty = new Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits(); $limits = $qty->get_cart_item_quantity_limits( $item );
	ccheck( ! $limits['editable'] && $limits['minimum'] === 3 && $limits['maximum'] === 3, 'Checkout Block quantity locked' );
	ccheck( ! apply_filters( 'woocommerce_update_cart_validation', true, $key, $item, 4 ), 'classic cart quantity changes blocked' );
	WC()->cart->cart_contents[$key]['data']->set_price( 0.01 ); WC()->cart->calculate_totals();
	ccheck( WC()->cart->get_total( 'edit' ) === '59.97', 'cart price restored from Woo catalog, not client or session amount' );
	$display = Checkout::item_data( array(), $item ); $rendered = wp_json_encode( $display );
	ccheck( str_contains( $rendered, '9:00 AM' ) && str_contains( $rendered, '1:00 PM' ), 'rental start/end displayed in 12-hour local time' );
	ccheck( ! str_contains( $rendered, $identity['hash'] ) && ! str_contains( $rendered, $hold['request_key'] ) && ! str_contains( $rendered, '<script>' ), 'display excludes secrets and escapes package text' );
	update_option( Settings::OPTION, $deposit ); ccheck( json_decode( cread( $hold['id'] )['snapshot'], true )['payment_mode'] === 'full', 'global payment-mode change does not change snapshot' );
	cok( Checkout::validate_item( $item ), 'existing Full Payment checkout retains agreed mode' ); update_option( Settings::OPTION, $settings );
	$order = corder(); $row = cread( $hold['id'] );
	ccheck( (int) $row['order_id'] === $order->get_id() && (int) $order->get_meta( '_brp_reservation_id' ) === (int) $row['id'], 'bidirectional order link reuses hold' );
	ccheck( $order->get_meta( '_brp_reservation_reference' ) === $hold['reference'] && $order->get_meta( '_brp_start_utc' ) === $hold['start_utc'] && $order->get_meta( '_brp_quantity' ) === 3, 'order metadata preserves rental reference and schedule' );
	ccheck( $row['hold_expires_at'] === BikeRentalPlugin\RentalTime::shift( $row['created_at'], 30 ), 'payment submission extends only to original creation plus 30 minutes' );
	do_action( 'woocommerce_store_api_checkout_order_processed', $order ); ccheck( cread( $hold['id'] ) === $row, 'duplicate submission does not extend again or increment revision' );
	ccheck( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'reservations' ) ) === 1, 'no second reservation created' );
	$another = wc_create_order(); $orders[] = $another->get_id();
	ccheck( is_wp_error( CheckoutReservation::begin_payment( $row['id'], $identity['hash'], $another->get_id(), $row['order_item_id'], CheckoutReservation::fingerprint( $row ) ) ), 'second primary order cannot claim reservation' );
	$order->update_status( 'pending' ); ccheck( cread( $row['id'] )['status'] === 'hold', 'pending order cannot confirm' );
	$order->update_meta_data( '_wc_square_credit_card_charge_captured', 'no' ); $order->set_transaction_id( 'fixture-authorization-only' ); $order->set_date_paid( time() ); $order->update_meta_data( '_wc_square_credit_card_authorization_amount', $order->get_total() ); $order->save();
	Payments::observe( $order->get_id() ); ccheck( cread( $row['id'] )['status'] === 'hold', 'authorization without capture does not confirm' );
	$order->update_meta_data( '_wc_square_credit_card_charge_captured', 'yes' ); $order->update_meta_data( '_wc_square_credit_card_authorization_amount', '0.01' ); $order->save();
	Payments::observe( $order->get_id() ); ccheck( cread( $row['id'] )['status'] === 'hold', 'captured amount must match full Woo total' );
	$order->update_status( 'failed' ); ccheck( cread( $row['id'] )['status'] === 'hold', 'failed order stays unconfirmed with bounded expiry' );
	$order->update_status( 'pending' ); $order = cpaid( $order );
	$confirmed = cread( $row['id'] ); ccheck( $confirmed['status'] === 'confirmed', 'verified full-payment lifecycle confirms existing reservation' );
	$receipt_request = new WP_REST_Request( 'POST', '/' . PublicBooking::API . '/hold-status' ); $receipt_request->set_header( 'origin', home_url() ); $receipt_request->set_header( 'x-brp-request', '1' ); $receipt_request->set_header( 'x-brp-token', $identity['token'] ); $receipt_request->set_param( 'request_key', $hold['request_key'] );
	$receipt = rest_get_server()->dispatch( $receipt_request )->get_data(); ccheck( $receipt['reservation_status'] === 'confirmed' && ! str_contains( $receipt['message'], 'expired' ), 'returning to booking page reports confirmed reservation instead of expired hold' );
	ccheck( (int) $confirmed['revision'] === (int) $row['revision'] + 1, 'real payment confirmation increments revision once' );
	do_action( 'woocommerce_payment_complete', $order->get_id() ); ccheck( cread( $row['id'] ) === $confirmed, 'duplicate payment callback is idempotent' );
	ccheck( PaymentMode::get_amount_due_now( $order ) === '59.97' && PaymentMode::get_remaining_balance( $order ) === '0', 'payment summary reads full Woo total without deposit ledger' );
	$order->update_status( 'completed' ); ccheck( cread( $row['id'] )['status'] === 'confirmed', 'Woo Completed does not mean rental returned' );
	$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 10, 'refund_payment' => false, 'restock_items' => false ) ); cok( $refund, 'Woo partial financial refund fixture created without gateway call' );
	Payments::observe( $order->get_id() ); ccheck( cread( $row['id'] ) === $confirmed, 'financial refund does not release rental inventory or change status' );
	cok( wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 49.97, 'refund_payment' => false, 'restock_items' => false ) ), 'remaining financial refund fixture created' );
	Payments::observe( $order->get_id() ); ccheck( cread( $row['id'] ) === $confirmed, 'full Woo refund also preserves rental status and allocation' );
	wp_set_current_user( 1 ); ob_start(); Payments::order_admin( $order ); $html = ob_get_clean(); ccheck( str_contains( $html, 'brp-reservations' ) && str_contains( $html, $row['reference'] ), 'order admin links to rental' );
	ob_start(); Payments::reservation_admin( $confirmed ); $html = ob_get_clean(); ccheck( str_contains( $html, 'Full Payment' ) && str_contains( $html, 'Order #' ), 'reservation admin shows snapshot mode and order link' );
	ccheck( PaymentMode::get_payment_summary( false ) === 'Order data unavailable.', 'missing order data handled safely' );
	wp_set_current_user( 0 ); ob_start(); Payments::reservation_admin( $confirmed ); ccheck( ob_get_clean() === '', 'unauthorized user cannot render admin payment details' );
	foreach ( array( false, true ) as $conflict ) {
		creset( 3 ); csession(); $late = chold( 3 ); cok( Checkout::transfer( $late['request_key'] ), 'late-payment fixture transfers' ); $late_order = corder(); cexpire( $late );
		ccheck( is_wp_error( Checkout::transfer( $late['request_key'] ) ), 'expired hold cannot return to checkout' );
		cthrows( static fn() => Checkout::pay_order( $late_order ), 'expired hold blocks pay-for-order' );
		if ( $conflict ) { csession(); $competing = chold( 3 ); }
		$late_order = cpaid( $late_order ); $after = cread( $late['id'] );
		ccheck( $after['status'] === ( $conflict ? 'expired' : 'confirmed' ), 'late payment ' . ( $conflict ? 'cannot overbook' : 'reclaims available capacity' ) );
		if ( $conflict ) {
			ccheck( $after['issue_code'] === 'payment_inventory_conflict', 'paid inventory conflict persisted' );
			ccheck( $late_order->get_transaction_id() !== '' && $late_order->get_meta( '_brp_payment_issue' ) === 'payment_inventory_conflict', 'financial evidence and order exception retained' );
			wp_set_current_user( 1 ); ob_start(); Payments::reservation_admin( $after ); $html = ob_get_clean(); ccheck( str_contains( $html, 'Payment received' ) && str_contains( $html, 'staff resolution' ), 'payment conflict prominently visible to staff' ); wp_set_current_user( 0 );
		}
	}
	creset(); csession();
	$max_hold = chold(); cok( Checkout::transfer( $max_hold['request_key'] ), 'maximum deadline fixture transfers' ); $max_order = corder();
	$wpdb->update( Database::table( 'reservations' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'id' => $max_hold['id'] ) );
	cthrows( static fn() => Checkout::pay_order( $max_order ), 'administratively renewed hold cannot exceed original 30-minute payment deadline' );
	creset(); csession();
	ccheck( ! Checkout::allow_add( true, $product->get_id(), 1 ), 'direct rental add-to-cart denied' );
	ccheck( Checkout::allow_add( true, $normal->get_id(), 1 ), 'normal product add-to-cart unchanged' );
	cthrows( static fn() => do_action( 'woocommerce_store_api_validate_add_to_cart', $product, array() ), 'Store API add-to-cart bypass denied' );
	$product->set_manage_stock( true ); $product->set_stock_quantity( 0 ); $product->set_stock_status( 'outofstock' ); $product->set_virtual( true ); $product->save(); $product = wc_get_product( $product->get_id() );
	ccheck( ! $product->managing_stock() && $product->is_in_stock(), 'retail stock cannot override rental inventory' ); ccheck( $product->needs_shipping(), 'rental delivery address uses physical Woo checkout fields' );
	$hold = chold(); cok( Checkout::transfer( $hold['request_key'] ), 'rental transfers despite zero retail stock' );
	$order = corder(); $order->update_status( 'processing' ); ccheck( cread( $hold['id'] )['status'] === 'hold', 'manual status-only processing cannot confirm payment' );
	wp_set_current_user( 1 ); $held = cread( $hold['id'] ); Reservations::confirm_hold( $held['id'], $held['revision'] );
	Payments::observe( $order->get_id() ); ccheck( cread( $hold['id'] )['issue_code'] === 'payment_unverified', 'confirmed reservation with unpaid order is flagged' );
	$order->delete_meta_data( '_brp_reservation_id' ); $order->save(); update_option( 'brp_payment_reservation_cursor', 0 ); Payments::reconcile(); ccheck( cread( $hold['id'] )['issue_code'] === 'payment_order_link', 'reconciliation detects missing order-side link' );
	ccheck( '2' === Database::VERSION && '2' === get_option( Database::OPTION ), 'schema is 2' );
	creset(); csession(); $failed_hold = chold( 3 ); cok( Checkout::transfer( $failed_hold['request_key'] ), 'failed-expiry fixture transfers' ); $failed_order = corder(); $failed_order->update_status( 'failed' );
	$wpdb->update( Database::table( 'reservations' ), array( 'hold_expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $failed_hold['id'] ) );
	Reservations::expire_holds(); ccheck( cread( $failed_hold['id'] )['status'] === 'expired', 'failed initial payment hold expires normally' );
	ccheck( Availability::check( $failed_hold['occupied_start_utc'], $failed_hold['occupied_end_utc'] )['available_quantity'] === 3, 'failed expired payment returns capacity without deleting financial order' );
	creset(); csession(); $recovery = chold(); cok( Checkout::transfer( $recovery['request_key'] ), 'reconciliation fixture transfers' ); $recovery_order = corder();
	remove_action( 'woocommerce_payment_complete', array( Payments::class, 'observe' ), 50 ); remove_action( 'woocommerce_order_status_changed', array( Payments::class, 'observe' ), 50 );
	try { $recovery_order = cpaid( $recovery_order ); } finally { add_action( 'woocommerce_payment_complete', array( Payments::class, 'observe' ), 50 ); add_action( 'woocommerce_order_status_changed', array( Payments::class, 'observe' ), 50 ); }
	ccheck( cread( $recovery['id'] )['status'] === 'hold', 'missed callback leaves paid order and original hold for recovery' );
	update_option( 'brp_payment_reservation_cursor', 0 ); Payments::reconcile(); ccheck( cread( $recovery['id'] )['status'] === 'confirmed', 'reconciliation recovers paid-order/unconfirmed reservation under inventory lock' );
} finally {
	wp_set_current_user( 1 ); WC()->cart->empty_cart();
	foreach ( $orders as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	$product->delete( true ); $normal->delete( true );
}
echo "$checks Milestone 6A checks passed. Square evidence simulated; no real sandbox payments.\n";
ob_end_flush();
