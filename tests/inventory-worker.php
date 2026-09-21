<?php
/** Independent PHP process; all allocation work uses production services. */
require __DIR__ . '/inventory-test-bootstrap.php';
$job = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 40' );
$connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
file_put_contents( $job['ready'], (string) $connection );
function public_worker_hold( $job ) {
	wp_set_current_user( 0 );
	$_SERVER['REMOTE_ADDR'] = $job['client_ip'];
	$identity = \BikeRentalPlugin\GuestSession::start();
	$request = new WP_REST_Request( 'POST', '/bike-rental/v1/holds' );
	$request->set_header( 'origin', home_url() ); $request->set_header( 'x-brp-request', '1' ); $request->set_header( 'x-brp-token', $identity['token'] );
	foreach ( $job['input'] + array( 'request_key' => $job['key'], 'riders' => brp_test_riders( $job['input']['quantity'] ) ) as $key => $value ) { $request->set_param( $key, $value ); }
	$response = rest_get_server()->dispatch( $request );
	return $response->get_status() >= 400 ? new WP_Error( 'brp_public_' . $response->get_status(), $response->get_data()['message'] ) : $response->get_data();
}
$result = match ( $job['operation'] ) {
	'create' => \BikeRentalPlugin\Reservations::create( $job['input'] ),
	'hold' => \BikeRentalPlugin\Reservations::create_hold( $job['input'], $job['key'], hash( 'sha256', 'concurrent-session' ) ),
	'edit' => \BikeRentalPlugin\Reservations::update( $job['id'], $job['input'], $job['revision'] ),
	'capacity' => \BikeRentalPlugin\Fleet::set_capacity( $job['quantity'] ),
	'block' => \BikeRentalPlugin\Fleet::save_block( $job['input'] ),
	'public_hold' => public_worker_hold( $job ),
	'payment' => \BikeRentalPlugin\CheckoutReservation::outcome( $job['id'], $job['order_id'], $job['fingerprint'], true ),
	'checkout_begin' => \BikeRentalPlugin\CheckoutReservation::begin_payment( $job['id'], hash( 'sha256', 'concurrent-session' ), $job['order_id'], $job['item_id'], $job['fingerprint'] ),
	'cart_release' => \BikeRentalPlugin\CheckoutReservation::release_cart_hold( $job['id'], hash( 'sha256', 'concurrent-session' ), $job['product_id'], $job['fingerprint'] ),
	default => throw new RuntimeException( 'Unknown test job.' ),
};
file_put_contents( $job['result'], wp_json_encode( array( 'connection' => $connection, 'success' => ! is_wp_error( $result ), 'code' => is_wp_error( $result ) ? $result->get_error_code() : '', 'value' => is_wp_error( $result ) ? $result->get_error_message() : $result ) ) );
