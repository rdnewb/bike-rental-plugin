<?php
/** Independent PHP process; all allocation work uses production services. */
require __DIR__ . '/inventory-test-bootstrap.php';
$job = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 40' );
$connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
file_put_contents( $job['ready'], (string) $connection );
$result = match ( $job['operation'] ) {
	'create' => \BikeRentalPlugin\Reservations::create( $job['input'] ),
	'hold' => \BikeRentalPlugin\Reservations::create_hold( $job['input'], $job['key'], hash( 'sha256', 'concurrent-session' ) ),
	'edit' => \BikeRentalPlugin\Reservations::update( $job['id'], $job['input'], $job['revision'] ),
	'capacity' => \BikeRentalPlugin\Fleet::set_capacity( $job['quantity'] ),
	'block' => \BikeRentalPlugin\Fleet::save_block( $job['input'] ),
	default => throw new RuntimeException( 'Unknown test job.' ),
};
file_put_contents( $job['result'], wp_json_encode( array( 'connection' => $connection, 'success' => ! is_wp_error( $result ), 'code' => is_wp_error( $result ) ? $result->get_error_code() : '', 'value' => is_wp_error( $result ) ? $result->get_error_message() : $result ) ) );
