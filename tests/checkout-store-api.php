<?php
/** Complete real Store API requests, with a local-only gateway double. NOT a Square sandbox test. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
use BikeRentalPlugin\{Settings, Database, Packages, Reservations, GuestSession, Checkout, PaymentMode};
add_filter( 'pre_wp_mail', '__return_false' );
class BRP_Checkout_Test_Gateway extends WC_Payment_Gateway {
	public function __construct() { $this->id = 'square_credit_card'; $this->enabled = 'yes'; $this->title = 'Fixture gateway — no Square connection'; $this->supports = array( 'products' ); }
	public function process_payment( $id ) {
		$order = wc_get_order( $id );
		if ( $GLOBALS['fixture_decline'] ) { $order->update_status( 'failed' ); return array( 'result' => 'failure', 'message' => 'Fixture decline' ); }
		$order->update_meta_data( '_wc_square_credit_card_charge_captured', 'yes' ); $order->update_meta_data( '_wc_square_credit_card_authorization_amount', $order->get_total() ); $order->save();
		$order->payment_complete( 'fixture-store-api-' . $id );
		return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
	}
}
add_filter( 'woocommerce_payment_gateways', static fn( $gateways ) => array_merge( $gateways, array( BRP_Checkout_Test_Gateway::class ) ) );
WC()->payment_gateways()->init(); wc_load_cart();
$checks = 0;
function scheck( $value, $label ) { if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo 'PASS: ' . $label . PHP_EOL; }
function sapi( $method, $route, $body = array() ) {
	$request = new WP_REST_Request( $method, '/wc/store/v1/' . $route ); $request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	foreach ( $body as $key => $value ) { $request->set_param( $key, $value ); }
	$response = rest_get_server()->dispatch( $request );
	if ( $response->get_status() >= 400 ) { echo 'API RESULT: ' . wp_json_encode( $response->get_data() ) . PHP_EOL; }
	return $response;
}
$saved = array(); foreach ( array( Settings::OPTION, 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on', 'woocommerce_currency', 'woocommerce_enable_guest_checkout', 'woocommerce_tax_classes' ) as $key ) { $saved[$key] = get_option( $key ); }
$settings = Settings::defaults(); foreach ( $settings['weekly_hours'] as &$hours ) { $hours = array( 'open' => 1, 'start' => '08:00', 'end' => '18:00' ); } unset( $hours );
update_option( Settings::OPTION, $settings ); update_option( 'woocommerce_currency', 'USD' ); update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_prices_include_tax', 'no' ); update_option( 'woocommerce_tax_based_on', 'billing' );
$tax_class = WC_Tax::create_tax_class( 'BRP store fixture' );
$rate = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => '', 'tax_rate' => '7.0000', 'tax_rate_name' => 'Fixture tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => 'brp-store-fixture' ) );
$zone = new WC_Shipping_Zone(); $zone->set_zone_name( 'BRP disposable delivery' ); $zone->set_zone_order( 0 ); $zone->add_location( 'US', 'country' ); $zone->save();
$shipping_id = $zone->add_shipping_method( 'flat_rate' ); update_option( 'woocommerce_flat_rate_' . $shipping_id . '_settings', array( 'title' => 'Fixture delivery', 'cost' => '0', 'tax_status' => 'none' ) );
$product = new WC_Product_Simple(); $product->set_name( 'Store API rental fixture' ); $product->set_status( 'publish' ); $product->set_regular_price( '19.99' ); $product->set_tax_status( 'taxable' ); $product->set_tax_class( 'brp-store-fixture' );
foreach ( array( Packages::ENABLED => 'yes', Packages::ACTIVE => 'yes', Packages::TYPE => 'calendar_days', Packages::AMOUNT => 3 ) as $key => $value ) { $product->update_meta_data( $key, $value ); } $product->save();
$created_orders = array(); add_action( 'woocommerce_new_order', static function ( $id ) { $GLOBALS['created_orders'][] = $id; } );
$wpdb->query( 'DELETE FROM ' . Database::table( 'reservations' ) ); $wpdb->query( 'DELETE FROM ' . Database::table( 'availability' ) . ' WHERE id <> 1' ); $wpdb->update( Database::table( 'availability' ), array( 'quantity' => 3 ), array( 'id' => 1 ) );
try {
	wp_set_current_user( 0 ); WC()->cart->empty_cart(); WC()->session->set( 'store_api_draft_order', null ); unset( $_COOKIE[ GuestSession::cookie_name() ] ); $identity = GuestSession::start();
	$address = array( 'first_name' => 'Test', 'last_name' => 'Rider', 'address_1' => '123 Test Street', 'address_2' => '', 'city' => 'Bradenton', 'state' => 'FL', 'postcode' => '34205', 'country' => 'US' );
	$billing = $address + array( 'email' => 'fixture@example.invalid', 'phone' => '2025550123' );
	$customer = sapi( 'POST', 'cart/update-customer', array( 'billing_address' => $billing, 'shipping_address' => $address ) ); scheck( $customer->get_status() === 200, 'real guest Store API customer addresses accepted' );
	$payload = array( 'billing_address' => $billing, 'shipping_address' => $address, 'payment_method' => 'square_credit_card', 'payment_data' => array(), 'customer_note' => '', 'create_account' => false );
	if ( ! in_array( '--normal', $argv, true ) ) {
	$date = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+7 days' )->format( 'Y-m-d' );
	$hold = Database::public_booking( static fn() => Reservations::create_booking_hold( array( 'package_id' => $product->get_id(), 'quantity' => 3, 'date' => $date, 'time' => '09:00' ), 'store-api-fixture', $identity['hash'] ) ); scheck( ! is_wp_error( $hold ), 'three-bike calendar hold created' );
	$transfer = Checkout::transfer( $hold['request_key'] ); scheck( ! is_wp_error( $transfer ), 'hold transfers to real Woo cart' );
	$cart = json_decode( wp_json_encode( sapi( 'GET', 'cart' )->get_data() ), true );
	scheck( $cart['needs_shipping'] && count( $cart['items'] ) === 1, 'real Store API exposes rental delivery and single item' );
	scheck( $cart['items'][0]['quantity'] === 3 && ! $cart['items'][0]['quantity_limits']['editable'], 'Store API reports locked held quantity' );
	scheck( $cart['totals']['total_price'] === '6417' && $cart['totals']['total_tax'] === '420', 'real Woo cart computes full fractional price plus tax' );
	scheck( sapi( 'POST', 'cart/update-item', array( 'key' => $cart['items'][0]['key'], 'quantity' => 2 ) )->get_status() >= 400, 'actual Store API rejects independent rental quantity edit' );
	scheck( sapi( 'POST', 'cart/add-item', array( 'id' => $product->get_id(), 'quantity' => 1 ) )->get_status() >= 400, 'actual Store API rejects direct rental add-to-cart' );
	$rate_id = $cart['shipping_rates'][0]['shipping_rates'][0]['rate_id'];
	scheck( sapi( 'POST', 'cart/select-shipping-rate', array( 'package_id' => 0, 'rate_id' => $rate_id ) )->get_status() === 200, 'Woo delivery method selected' );
	$before = Database::public_booking( static fn() => Reservations::read( $hold['id'] ) );
	scheck( sapi( 'GET', 'checkout' )->get_status() === 200, 'Checkout Block load succeeds' );
	scheck( sapi( 'GET', 'checkout' )->get_status() === 200, 'Checkout Block refresh succeeds' );
	scheck( Database::public_booking( static fn() => Reservations::read( $hold['id'] ) ) === $before, 'real checkout loads do not extend or rewrite hold' );
	$payload = array( 'billing_address' => $billing, 'shipping_address' => $address, 'payment_method' => 'square_credit_card', 'payment_data' => array(), 'customer_note' => '', 'create_account' => false );
	$fixture_decline = true; $response = sapi( 'POST', 'checkout', $payload ); scheck( $response->get_status() >= 400, 'fixture decline returned by actual checkout route' );
	$failed = Database::public_booking( static fn() => Reservations::read( $hold['id'] ) ); scheck( $failed['status'] === 'hold' && $failed['order_id'], 'failed payment preserves one bounded linked hold' );
	$fixture_decline = false; $response = sapi( 'POST', 'checkout', $payload ); scheck( $response->get_status() === 200, 'actual checkout retry completes using gateway double' );
	$after = Database::public_booking( static fn() => Reservations::read( $hold['id'] ) );
	scheck( $after['status'] === 'confirmed' && $after['order_id'] === $failed['order_id'], 'real checkout retry reuses primary order and confirms same reservation' );
	scheck( count( array_unique( $created_orders ) ) === 1, 'retry creates exactly one Woo primary order' );
	$order = wc_get_order( $after['order_id'] ); scheck( $order->get_total() === '64.17' && $order->get_total_tax() === '4.2', 'full Woo order and tax totals preserved through payment' );
	scheck( $order->get_customer_id() === 0 && $order->get_shipping_address_1() === $address['address_1'], 'guest order retains Woo delivery address without account' );
	$items = $order->get_items(); $item = reset( $items ); scheck( $item->get_meta( 'Rental Quantity' ) === '3' && str_contains( $item->get_meta( 'Rental Start' ), '9:00 AM' ), 'rental metadata persists through actual order construction' );
	scheck( PaymentMode::paid( $order ), 'payment lifecycle adapter recognizes fixture capture evidence' );
	} else {
	$fixture_decline = false;
	WC()->cart->empty_cart(); WC()->session->set( 'store_api_draft_order', null );
	$normal = new WC_Product_Simple(); $normal->set_name( 'Unrelated normal product' ); $normal->set_status( 'publish' ); $normal->set_regular_price( '5' ); $normal->set_virtual( true ); $normal->set_tax_status( 'none' ); $normal->save();
	try {
		scheck( in_array( sapi( 'POST', 'cart/add-item', array( 'id' => $normal->get_id(), 'quantity' => 2 ) )->get_status(), array( 200, 201 ), true ), 'normal product still uses direct Store API add-to-cart' );
		$normal_response = sapi( 'POST', 'checkout', $payload ); scheck( $normal_response->get_status() === 200, 'normal product checkout remains functional' );
		$normal_order = wc_get_order( $normal_response->get_data()['order_id'] );
		scheck( $normal_order->get_total() === '10.00' && ! $normal_order->get_meta( '_brp_reservation_id' ), 'normal order has Woo total and no rental relation' );
	} finally { $normal->delete( true ); }
	}
	echo 'Order storage: ' . ( Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'CPT' ) . PHP_EOL;
} finally {
	wp_set_current_user( 1 ); WC()->cart->empty_cart();
	foreach ( array_unique( $created_orders ) as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	$product->delete( true ); $zone->delete(); delete_option( 'woocommerce_flat_rate_' . $shipping_id . '_settings' ); WC_Tax::_delete_tax_rate( $rate ); WC_Tax::delete_tax_class_by( 'slug', 'brp-store-fixture' );
	foreach ( $saved as $key => $value ) { if ( false === $value ) { delete_option( $key ); } else { update_option( $key, $value ); } }
}
echo "$checks Store API checks passed. Gateway double only; Square sandbox still required.\n"; ob_end_flush();
