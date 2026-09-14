<?php
/** Real simultaneous PHP processes; coordinator observes two actual InnoDB lock waits. */
require __DIR__ . '/inventory-test-bootstrap.php';
use BikeRentalPlugin\Availability;
use BikeRentalPlugin\Database;
use BikeRentalPlugin\Reservations;
use BikeRentalPlugin\Settings;
use BikeRentalPlugin\Packages;
use BikeRentalPlugin\Fleet;
$checks = 0;
function race_check( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo 'PASS: ' . $label . PHP_EOL; }
function reset_race( $capacity ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'reservations' ) ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <> 1', Database::table( 'availability' ) ) );
	$wpdb->update( Database::table( 'availability' ), array( 'quantity' => $capacity ), array( 'id' => 1 ) );
}
$settings = Settings::defaults(); update_option( Settings::OPTION, $settings ); update_option( 'timezone_string', 'America/New_York' );
$product = new WC_Product_Simple(); $product->set_name( 'Concurrency fixture' ); $product->set_status( 'publish' ); $product->set_regular_price( '55.00' );
foreach ( array( Packages::ENABLED => 'yes', Packages::ACTIVE => 'yes', Packages::TYPE => 'hours', Packages::AMOUNT => 2, Packages::PROMO => '' ) as $key => $value ) { $product->update_meta_data( $key, $value ); }
$pid = $product->save();
$input = array( 'package_product_id' => $pid, 'quantity' => 1, 'start' => '2036-06-15T09:00', 'end' => '2036-06-15T11:00', 'status' => 'confirmed' );

function run_race( $jobs, $label ) {
	global $wpdb;
	$directory = sys_get_temp_dir() . '/brp-race-' . bin2hex( random_bytes( 8 ) ); mkdir( $directory );
	$processes = array();
	$wpdb->query( 'START TRANSACTION' );
	$wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = 1 FOR UPDATE', Database::table( 'availability' ) ) );
	try {
		foreach ( $jobs as $index => $job ) {
			$job['ready'] = $directory . '/' . $index . '.ready'; $job['result'] = $directory . '/' . $index . '.result';
			$path = $directory . '/' . $index . '.json'; file_put_contents( $path, wp_json_encode( $job ) );
			$process = proc_open( array( PHP_BINARY, '-c', php_ini_loaded_file(), __DIR__ . '/inventory-worker.php', $path ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $directory . '/' . $index . '.stdout', 'w' ), 2 => array( 'file', $directory . '/' . $index . '.stderr', 'w' ) ), $pipes, null, null, array( 'bypass_shell' => true, 'create_new_console' => false ) );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start independent worker.' ); }
			fclose( $pipes[0] ); $processes[] = array( $process, $job );
			// Start the second while the first is definitely waiting, then prove both wait.
			$deadline = microtime( true ) + 25;
			do {
				$waiters = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS' );
				if ( $waiters >= $index + 1 ) { break; }
				usleep( 100000 );
			} while ( microtime( true ) < $deadline );
			if ( $waiters < $index + 1 ) { throw new RuntimeException( 'Workers did not reach real InnoDB lock contention: ' . $label . ' (logs: ' . $directory . ')' ); }
		}
		race_check( $waiters >= 2, $label . ': two independent operations wait simultaneously on InnoDB' );
	} finally { $wpdb->query( 'COMMIT' ); }
	$results = array();
	foreach ( $processes as $index => $entry ) {
		$exit = proc_close( $entry[0] );
		if ( $exit !== 0 || ! is_file( $entry[1]['result'] ) ) { throw new RuntimeException( 'Worker failed: ' . file_get_contents( $directory . '/' . $index . '.stderr' ) ); }
		$results[] = json_decode( file_get_contents( $entry[1]['result'] ), true, 512, JSON_THROW_ON_ERROR );
	}
	race_check( $results[0]['connection'] !== $results[1]['connection'], $label . ': distinct database connection IDs' );
	return $results;
}

for ( $round = 1; $round <= 3; ++$round ) {
	reset_race( 1 );
	$results = run_race( array( array( 'operation' => 'create', 'input' => $input ), array( 'operation' => 'create', 'input' => $input ) ), 'Last bike round ' . $round );
	race_check( count( array_filter( array_column( $results, 'success' ) ) ) === 1, 'exactly one last-bike allocation succeeds' );
	race_check( in_array( 'brp_conflict', array_column( $results, 'code' ), true ), 'other final-bike operation receives capacity conflict' );
	race_check( Database::listing( 'reservations' )['total'] === 1, 'only one last-bike row exists' );
}
reset_race( 3 );
$a = Reservations::create( array_replace( $input, array( 'start' => '2036-06-14T09:00', 'end' => '2036-06-14T11:00' ) ) );
$b = Reservations::create( array_replace( $input, array( 'start' => '2036-06-16T09:00', 'end' => '2036-06-16T11:00' ) ) );
$results = run_race( array( array( 'operation' => 'edit', 'id' => $a['id'], 'revision' => 1, 'input' => array_replace( $input, array( 'quantity' => 2 ) ) ), array( 'operation' => 'edit', 'id' => $b['id'], 'revision' => 1, 'input' => array_replace( $input, array( 'quantity' => 2 ) ) ) ), 'Two edits competing for one interval' );
race_check( count( array_filter( array_column( $results, 'success' ) ) ) === 1 && in_array( 'brp_conflict', array_column( $results, 'code' ), true ), 'simultaneous edits cannot oversell' );
race_check( Availability::check( '2036-06-15 13:00:00', '2036-06-15 15:00:00' )['peak_existing_usage'] === 2, 'losing edit preserves prior interval' );
reset_race( 3 );
$a = Reservations::create( $input );
$jobs = array( array( 'operation' => 'edit', 'id' => $a['id'], 'revision' => 1, 'input' => array_replace( $input, array( 'quantity' => 2 ) ) ), array( 'operation' => 'edit', 'id' => $a['id'], 'revision' => 1, 'input' => array_replace( $input, array( 'quantity' => 3 ) ) ) );
$results = run_race( $jobs, 'Same reservation revision race' );
race_check( count( array_filter( array_column( $results, 'success' ) ) ) === 1 && in_array( 'brp_revision', array_column( $results, 'code' ), true ), 'concurrent same-row edit rejects stale revision' );
foreach ( array( false, true ) as $reverse ) {
	reset_race( 2 );
	$jobs = array( array( 'operation' => 'capacity', 'quantity' => 1 ), array( 'operation' => 'create', 'input' => array_replace( $input, array( 'quantity' => 2 ) ) ) );
	$results = run_race( $reverse ? array_reverse( $jobs ) : $jobs, 'Capacity/create race ' . (int) $reverse );
	race_check( count( array_filter( array_column( $results, 'success' ) ) ) === 1, 'capacity reduction and incompatible creation cannot both succeed' );
	$usage = Availability::check( '2036-06-15 13:00:00', '2036-06-15 15:00:00' );
	race_check( $usage['peak_existing_usage'] <= $usage['total_capacity'], 'capacity/create race leaves consistent fleet' );
}
foreach ( array( false, true ) as $reverse ) {
	reset_race( 1 );
	$jobs = array( array( 'operation' => 'block', 'input' => array( 'quantity' => 1, 'start' => $input['start'], 'end' => $input['end'], 'reason' => 'Concurrent maintenance', 'active' => 1 ) ), array( 'operation' => 'create', 'input' => $input ) );
	$results = run_race( $reverse ? array_reverse( $jobs ) : $jobs, 'Block/create race ' . (int) $reverse );
	race_check( count( array_filter( array_column( $results, 'success' ) ) ) === 1 && in_array( 'brp_conflict', array_column( $results, 'code' ), true ), 'block and incompatible booking cannot both succeed' );
}
reset_race( 1 );
$job = array( 'operation' => 'hold', 'input' => $input, 'key' => 'same-concurrent-request' );
$results = run_race( array( $job, $job ), 'Duplicate hold race' );
race_check( $results[0]['success'] && $results[1]['success'] && $results[0]['value']['id'] === $results[1]['value']['id'], 'concurrent duplicate requests reuse one hold' );
race_check( Database::listing( 'reservations' )['total'] === 1, 'duplicate request race consumes exactly one bike' );
reset_race( 1 );
foreach ( $settings['weekly_hours'] as &$hours ) { $hours = array( 'open' => 1, 'start' => '08:00', 'end' => '18:00' ); } unset( $hours );
update_option( Settings::OPTION, $settings );
$public_input = array( 'package_id' => $pid, 'date' => ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+7 days' )->format( 'Y-m-d' ), 'time' => '09:00', 'quantity' => 1 );
$client_ip = 'fd00:' . implode( ':', str_split( bin2hex( random_bytes( 14 ) ), 4 ) );
$results = run_race( array( array( 'operation' => 'public_hold', 'input' => $public_input, 'key' => 'public-race-a', 'client_ip' => $client_ip ), array( 'operation' => 'public_hold', 'input' => $public_input, 'key' => 'public-race-b', 'client_ip' => $client_ip ) ), 'Two anonymous REST holds for last bike' );
race_check( count( array_filter( array_column( $results, 'success' ) ) ) === 1, 'exactly one public hold succeeds' );
race_check( in_array( 'brp_public_409', array_column( $results, 'code' ), true ), 'losing public request gets safe capacity conflict' );
race_check( Database::listing( 'reservations' )['total'] === 1, 'public REST race stores exactly one allocation' );
// Milestone 6A: the same row lock serializes late money, new bookings and duplicate payment callbacks.
foreach ( array( false, true ) as $reverse ) {
	reset_race( 1 );
	$late = Reservations::create_hold( $input, 'late-payment-race', hash( 'sha256', 'concurrent-session' ) );
	$wpdb->update( Database::table( 'reservations' ), array( 'status' => 'expired', 'hold_expires_at' => '2000-01-01 00:00:00', 'order_id' => 900001, 'order_item_id' => 900002 ), array( 'id' => $late['id'] ) );
	$late = Reservations::read( $late['id'] );
	$jobs = array( array( 'operation' => 'payment', 'id' => $late['id'], 'order_id' => 900001, 'fingerprint' => \BikeRentalPlugin\CheckoutReservation::fingerprint( $late ) ), array( 'operation' => 'create', 'input' => $input ) );
	$results = run_race( $reverse ? array_reverse( $jobs ) : $jobs, 'Late payment versus new reservation ' . (int) $reverse );
	$usage = Availability::check( $late['occupied_start_utc'], $late['occupied_end_utc'] );
	race_check( $usage['peak_existing_usage'] === 1, 'late payment and new booking never overbook the last bike' );
	$after = Reservations::read( $late['id'] );
	race_check( $after['status'] === 'confirmed' || ( $after['status'] === 'expired' && $after['issue_code'] === 'payment_inventory_conflict' ), 'late payment either confirms or records staff exception' );
}
reset_race( 1 );
$hold = Reservations::create_hold( $input, 'checkout-claim-race', hash( 'sha256', 'concurrent-session' ) );
$fingerprint = \BikeRentalPlugin\CheckoutReservation::fingerprint( $hold );
$jobs = array( array( 'operation' => 'checkout_begin', 'id' => $hold['id'], 'order_id' => 900001, 'item_id' => 900002, 'fingerprint' => $fingerprint ), array( 'operation' => 'checkout_begin', 'id' => $hold['id'], 'order_id' => 900003, 'item_id' => 900004, 'fingerprint' => $fingerprint ) );
$results = run_race( $jobs, 'Two checkout orders claim the same hold' );
race_check( count( array_filter( array_column( $results, 'success' ) ) ) === 1, 'only one primary order wins checkout claim' );
$linked = Reservations::read( $hold['id'] );
$job = array( 'operation' => 'payment', 'id' => $linked['id'], 'order_id' => (int) $linked['order_id'], 'fingerprint' => \BikeRentalPlugin\CheckoutReservation::fingerprint( $linked ) );
$results = run_race( array( $job, $job ), 'Simultaneous duplicate payment callbacks' );
$after = Reservations::read( $linked['id'] );
race_check( $results[0]['success'] && $results[1]['success'] && $after['status'] === 'confirmed' && (int) $after['revision'] === (int) $linked['revision'] + 1, 'duplicate payment callbacks confirm and increment revision only once' );
echo PHP_EOL . $checks . ' real multiprocess concurrency checks passed.' . PHP_EOL;
