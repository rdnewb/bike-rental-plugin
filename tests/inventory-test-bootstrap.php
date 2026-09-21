<?php
/** Disposable-only real WordPress bootstrap shared by concurrency processes. */
if ( PHP_SAPI !== 'cli' || getenv( 'BRP_ALLOW_DISPOSABLE_TESTS' ) !== '1' ) { throw new RuntimeException( 'Disposable CLI opt-in required.' ); }
$test_root = getenv( 'BRP_TEST_WP_ROOT' );
if ( ! $test_root || ! is_file( $test_root . '/wp-load.php' ) ) { throw new RuntimeException( 'Disposable WordPress root required.' ); }
require $test_root . '/wp-load.php';
if ( DB_NAME !== 'brp_m3_disposable' || DB_HOST !== '127.0.0.1:33316' || $wpdb->prefix !== 'm3_' ) { throw new RuntimeException( 'Refusing non-disposable database.' ); }
require_once dirname( __DIR__ ) . '/bike-rental-plugin/bike-rental-plugin.php';
wp_set_current_user( 1 );
\BikeRentalPlugin\Plugin::boot();
\BikeRentalPlugin\Plugin::load_packages();

/** Real pre-checkout fixture data required by 0.8.1; dedicated tests omit/mutate it explicitly. */
function brp_test_riders( $quantity ) {
 $riders = array(); for ( $i = 1; $i <= (int) $quantity; ++$i ) { $riders[$i] = array( 'legal_name' => 'Fixture Rider ' . $i, 'age' => '25', 'email' => 'rider' . $i . '@example.test' ); } return $riders;
}
function brp_test_hold( $input, $key, $session ) {
 if ( ! array_key_exists( 'riders', $input ) ) { $input['riders'] = brp_test_riders( $input['quantity'] ?? 0 ); }
 return \BikeRentalPlugin\Reservations::create_booking_hold( $input, $key, $session );
}
