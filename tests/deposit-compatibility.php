<?php
/** Runs real Acowebs calculations/order hooks in the guarded disposable WordPress installation.
 * No Square API calls or payment success claims. No emails leave this fixture.
 */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
add_filter( 'pre_wp_mail', '__return_false' );
if ( ! defined( 'AWCDP_VERSION' ) || AWCDP_VERSION !== '1.2.12' || WC_VERSION !== '11.1.0' ) { throw new RuntimeException( 'Requires Acowebs 1.2.12 and WooCommerce 11.1.0.' ); }
if ( version_compare( $wp_version, '7.0', '<' ) ) { throw new RuntimeException( 'WooCommerce 11.1.0 requires WordPress 7.0 or later.' ); }
echo 'ENVIRONMENT: ' . wp_json_encode( array( 'wordpress' => $wp_version, 'woocommerce' => WC_VERSION, 'acowebs' => AWCDP_VERSION, 'php' => PHP_VERSION ) ) . PHP_EOL;
wc_load_cart();
$checks = 0; $failures = 0;
function deposit_check( $value, $label ) { ++$GLOBALS['checks']; if ( ! $value ) { ++$GLOBALS['failures']; } echo ( $value ? 'PASS: ' : 'FAIL: ' ) . $label . PHP_EOL; }
$saved_options = array();
foreach ( array( 'awcdp_general_settings', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on', 'woocommerce_currency' ) as $key ) { $saved_options[ $key ] = get_option( $key ); }
update_option( 'awcdp_general_settings', array( 'enable_deposits' => 1, 'deposit_type' => 'percent', 'deposit_amount' => 50, 'default_selected' => 'deposit', 'checkout_mode' => 1 ) );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_based_on', 'billing' );
update_option( 'woocommerce_currency', 'USD' );
$rate = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => '', 'tax_rate' => '7.0000', 'tax_rate_name' => 'Fixture tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => 'brp-m6-fixture' ) );
WC()->customer->set_billing_country( 'US' ); WC()->customer->set_billing_state( 'FL' ); WC()->customer->set_billing_postcode( '34205' ); WC()->customer->set_is_vat_exempt( false );
$product = new WC_Product_Simple(); $product->set_name( 'Deposit compatibility fixture' ); $product->set_status( 'publish' ); $product->set_virtual( true ); $product->set_tax_status( 'taxable' ); $product->set_tax_class( 'brp-m6-fixture' ); $product->set_regular_price( '100.00' ); $product->save();
$orders = array();
try {
	foreach ( array( array( '100.00', 1, 50, '107.00', '50.00', '57.00' ), array( '100.00', 3, 50, '321.00', '150.00', '171.00' ), array( '19.99', 3, 50, '64.17', '29.99', '34.18' ), array( '100.00', 1, 25, '107.00', '25.00', '82.00' ) ) as $case ) {
		WC()->cart->empty_cart();
		$product->set_regular_price( $case[0] ); $product->update_meta_data( '_awcdp_deposits_deposit_amount', $case[2] ); $product->save();
		$_REQUEST['awcdp_deposit_option'] = 'yes';
		WC()->session->set( 'awcdp_deposit_option', 'deposit' );
		$key = WC()->cart->add_to_cart( $product->get_id(), $case[1] );
		WC()->cart->calculate_totals();
		$info = WC()->cart->deposit_info;
		deposit_check( (bool) $key && ! empty( $info['deposit_enabled'] ), 'real Acowebs enables deposit' );
		deposit_check( wc_format_decimal( WC()->cart->get_total( 'edit' ), 2 ) === $case[3], 'WooCommerce full total and tax reconcile' );
		deposit_check( wc_format_decimal( $info['deposit_amount'], 2 ) === $case[4], 'configured percentage and rounding honored by Acowebs' );
		deposit_check( wc_format_decimal( array_sum( array_column( $info['payment_schedule'], 'total' ) ), 2 ) === $case[5], 'Acowebs remaining schedule includes deferred tax' );
		$order = wc_create_order( array( 'created_via' => 'store-api' ) ); $orders[] = $order->get_id();
		$order->set_currency( 'USD' ); $order->set_address( array( 'country' => 'US', 'state' => 'FL', 'postcode' => '34205' ), 'billing' );
		$order->add_product( wc_get_product( $product->get_id() ), $case[1] ); $order->calculate_totals();
		do_action( 'woocommerce_store_api_checkout_update_order_meta', $order );
		do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, new WP_REST_Request( 'POST' ) ); $order->save();
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$order = wc_get_order( $order->get_id() );
		$schedule = $order->get_meta( '_awcdp_deposits_payment_schedule' );
		foreach ( $schedule as $part ) { if ( ! empty( $part['id'] ) ) { $orders[] = $part['id']; } }
		deposit_check( wc_format_decimal( $order->get_total(), 2 ) === $case[4], 'Checkout Block primary order total becomes deposit' );
		deposit_check( wc_format_decimal( $order->get_meta( '_awcdp_deposits_second_payment' ), 2 ) === $case[5], 'order remaining balance matches cart schedule' );
		deposit_check( count( $schedule ) === 2, 'one deposit and one balance child schedule' );
		foreach ( $schedule as $part ) {
			$child = wc_get_order( $part['id'] );
			deposit_check( $child && $child->get_type() === 'awcdp_payment' && $child->get_parent_id() === $order->get_id(), 'real Acowebs child references primary order' );
			deposit_check( wc_format_decimal( $child->get_total(), 2 ) === wc_format_decimal( $part['total'], 2 ), 'child total matches actual schedule' );
		}
		$child_totals = array_map( static fn( $p ) => (float) wc_get_order( $p['id'] )->get_total(), $schedule );
		deposit_check( wc_format_decimal( array_sum( $child_totals ), 2 ) === $case[3], 'deposit and balance child totals equal full rental total' );
		$balance_child = wc_get_order( $schedule['unlimited']['id'] );
		deposit_check( wc_format_decimal( $balance_child->get_total(), 2 ) === wc_format_decimal( $order->get_meta( '_awcdp_deposits_second_payment' ), 2 ), 'balance child equals remaining balance recorded on primary' );
		$child_ids = array_column( $schedule, 'id' );
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
		$repeated_ids = array_column( wc_get_order( $order->get_id() )->get_meta( '_awcdp_deposits_payment_schedule' ), 'id' );
		$orders = array_merge( $orders, $repeated_ids );
		deposit_check( $child_ids === $repeated_ids, 'repeated processed hook preserves child associations' );
		$persisted_children = wc_get_orders( array( 'type' => 'awcdp_payment', 'parent' => $order->get_id(), 'limit' => -1, 'return' => 'ids' ) );
		deposit_check( count( $persisted_children ) === 2, 'repeated processed hook leaves exactly two child payment orders' );
		echo 'RETRY: ' . wp_json_encode( array( 'primary' => $order->get_id(), 'initial_children' => $child_ids, 'retry_children' => $repeated_ids, 'persisted_count' => count( $persisted_children ) ) ) . PHP_EOL;
		echo 'OBSERVED: ' . wp_json_encode( array( 'unit' => $case[0], 'quantity' => $case[1], 'percent' => $case[2], 'full_total' => $case[3], 'deposit' => $info['deposit_amount'], 'deposit_tax' => $info['deposit_breakdown']['taxes'], 'balance' => $order->get_meta( '_awcdp_deposits_second_payment' ), 'primary_tax' => $order->get_total_tax(), 'children' => array_map( static fn( $p ) => array( 'type' => $p['type'], 'total' => $p['total'], 'tax' => wc_get_order( $p['id'] )->get_total_tax() ), array_values( $schedule ) ) ) ) . PHP_EOL;
		$order->update_status( 'processing' );
		$order = wc_get_order( $order->get_id() );
		deposit_check( $order->get_meta( '_awcdp_deposits_deposit_paid' ) === 'yes' && $order->get_transaction_id() === '', 'characterization: status-only processing marks deposit paid without a transaction' );
	}
} finally {
	WC()->cart->empty_cart();
	foreach ( array_unique( $orders ) as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	$product->delete( true ); WC_Tax::_delete_tax_rate( $rate );
	foreach ( $saved_options as $key => $value ) { if ( false === $value ) { delete_option( $key ); } else { update_option( $key, $value ); } }
}
echo 'Deposit compatibility: ' . ( $checks - $failures ) . " passed, $failures failed, $checks total. Square sandbox NOT exercised.\n";
ob_end_flush();
exit( $failures ? 1 : 0 );
