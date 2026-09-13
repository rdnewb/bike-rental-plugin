<?php
/** Active timing policy against real InnoDB, with a connection-local database clock. */
require __DIR__ . '/availability.php';
use BikeRentalPlugin\{Availability, Database, DataAdmin, Fleet, PublicBooking, Reservations, Settings};
$prior_checks = $checks;
function policy_clock( $utc ) {
	global $wpdb;
	$stamp = ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
	if ( false === $wpdb->query( $wpdb->prepare( 'SET timestamp = %d', $stamp ) ) || $utc !== $wpdb->get_var( 'SELECT UTC_TIMESTAMP()' ) ) { throw new RuntimeException( 'Could not set disposable connection clock.' ); }
}
function policy_times() {
	$request = new WP_REST_Request( 'GET', '/' . PublicBooking::API . '/times' );
	$request->set_param( 'package_id', $GLOBALS['product_id'] ); $request->set_param( 'date', '2035-06-20' );
	wp_set_current_user( 0 );
	try { return rest_get_server()->dispatch( $request )->get_data(); } finally { wp_set_current_user( 1 ); }
}
try {
	clear_inventory();
	policy_clock( '2035-06-15 12:45:00' ); // 08:45 New York; preparation has begun, rental has not.
	$settings = Settings::get(); $settings['preparation_buffer'] = 30; $settings['turnaround_buffer'] = 30;
	foreach ( $settings['weekly_hours'] as &$hours ) { $hours = array( 'open' => 1, 'start' => '08:00', 'end' => '18:00' ); } unset( $hours );
	update_option( Settings::OPTION, $settings );
	$input = booking( 10 );
	$row = good( Reservations::create( $input ), 'future confirmed reservation created' );
	$early = Reservations::mark_active( $row['id'], 1 );
	bad( $early, 'activation rejected during preparation before scheduled start' );
	verify( 'This reservation cannot be marked Active before its scheduled start time.' === $early->get_error_message(), 'clear activation validation message' );
	bad( Reservations::create( array_replace( $input, array( 'status' => 'active' ) ) ), 'direct creation cannot bypass activation timing' );
	bad( Reservations::create( array_replace( $input, array( 'status' => 'active', 'request_key' => 'early-active' ) ) ), 'idempotent creation cannot bypass activation timing' );
	$admin = new DataAdmin();
	$post = form_data( 'reservation_update', $row['id'], array_replace( $input, array( 'status' => 'active', 'revision' => 1 ) ) );
	bad( $admin->dispatch( $post ), 'nonce-authorized admin edit cannot activate early' );
	verify( $row === Reservations::read( $row['id'] ), 'rejected activation preserves entire row including revision and snapshot' );
	bad( Reservations::create( array_replace( $input, array( 'status' => 'completed' ) ) ), 'future rental cannot be created completed' );
	bad( Reservations::update( $row['id'], array_replace( $input, array( 'status' => 'completed' ) ), 1 ), 'future rental cannot be edited completed' );
	verify( 10 === Availability::check( '2035-06-20 12:00:00', '2035-06-20 22:00:00' )['available_quantity'], 'future confirmed rental has a bounded claim' );
	policy_clock( '2035-06-15 13:00:00' );
	$row = good( Reservations::mark_active( $row['id'], 1 ), 'activation allowed exactly at scheduled start in WordPress timezone' );
	verify( 2 === (int) $row['revision'] && 'active' === json_decode( $row['snapshot'], true )['status'], 'activation updates revision and snapshot' );
	verify( 0 === Availability::check( '2035-06-15 13:00:00', '2035-06-15 15:00:00' )['available_quantity'], 'in-progress active rental occupies its own interval' );
	verify( 10 === Availability::check( '2035-06-15 15:30:00', '2035-06-15 16:30:00' )['available_quantity'], 'in-progress active rental allows bookings after occupied end' );
	verify( ! empty( policy_times()['times'] ), 'public start-time endpoint offers later dates during active rental' );
	$later = good( Reservations::create( booking( 10, '2035-06-20T09:00', '2035-06-20T11:00' ) ), 'future booking can allocate whole fleet after active rental' );
	$row = good( Reservations::update( $row['id'], array_replace( $input, array( 'status' => 'active', 'issue_code' => 'Checked pickup' ) ), 2 ), 'active edit validates bounded allocation despite full future commitment' );
	$future_edit = array_replace( $input, array( 'status' => 'active', 'start' => '2035-06-16T09:00', 'end' => '2035-06-16T11:00' ) );
	bad( Reservations::update( $row['id'], $future_edit, 3 ), 'active reservation cannot be rescheduled into the future' );
	verify( $row === Reservations::read( $row['id'] ), 'rejected active reschedule preserves stored data' );
	bad( Reservations::mark_active( $row['id'], 2 ), 'stale activation revision remains rejected' );
	good( Reservations::cancel( $later['id'], 1 ), 'release future test commitment' );
	policy_clock( '2035-06-15 15:00:01' );
	verify( 10 === Availability::check( '2035-06-20 12:00:00', '2035-06-20 22:00:00' )['available_quantity'], 'scheduled rental end alone does not trigger overdue while turnaround remains' );
	policy_clock( '2035-06-15 15:30:00' );
	verify( 10 === Availability::check( '2035-06-20 12:00:00', '2035-06-20 22:00:00' )['available_quantity'], 'exact occupied end is bounded until that timestamp has passed' );
	policy_clock( '2035-06-15 15:30:01' );
	verify( 0 === Availability::check( '2035-06-20 12:00:00', '2035-06-20 22:00:00' )['available_quantity'], 'overdue active rental becomes open-ended without cleanup or row writes' );
	verify( array() === policy_times()['times'], 'public start times disappear when whole fleet becomes overdue' );
	bad( Reservations::create( booking( 1, '2035-06-20T09:00', '2035-06-20T11:00' ) ), 'overdue rental prevents new future allocations' );
	good( Fleet::set_capacity( 12 ), 'increase fleet for partial overdue test' );
	verify( 2 === Availability::check( '2035-06-20 12:00:00', '2035-06-20 22:00:00' )['available_quantity'], 'overdue claim consumes only its quantity' );
	bad( Fleet::set_capacity( 9 ), 'capacity reduction includes open-ended overdue claim' );
	$row = good( Reservations::mark_completed( $row['id'], 3 ), 'staff return completes overdue rental' );
	verify( 2 === Availability::check( '2035-06-15 15:31:00', '2035-06-15 15:40:00' )['available_quantity'], 'actual-return turnaround remains allocated' );
	verify( 12 === Availability::check( '2035-06-15 16:00:01', '2035-06-15 17:00:00' )['available_quantity'], 'return turnaround releases at its half-open endpoint' );
	verify( ! empty( policy_times()['times'] ), 'public start times return after completion for a later date' );

	clear_inventory(); policy_clock( '2035-06-15 13:00:00' );
	$row = good( Reservations::create( booking( 3, '2035-06-15T09:00', '2035-06-15T11:00', 'active' ) ), 'direct active creation allowed at start' );
	$before = $row;
	wp_set_current_user( 0 ); bad( Reservations::mark_completed( $row['id'], 1 ), 'anonymous status change rejected' ); wp_set_current_user( 1 );
	bad( $admin->dispatch( array( 'operation' => 'reservation_update', 'id' => $row['id'], 'revision' => 1, 'status' => 'completed' ) ), 'missing nonce rejects status edit' );
	verify( $before === Reservations::read( $row['id'] ), 'security failures preserve row' );
	$row = good( Reservations::mark_completed( $row['id'], 1 ), 'actual early return can complete an active rental' );
	verify( $row === Reservations::mark_completed( $row['id'], 2 ), 'early-return completion retry is a no-op' );
	verify( 10 === Availability::check( '2035-06-15 13:00:00', '2035-06-15 15:00:00' )['available_quantity'], 'early return releases quantity with zero turnaround' );
	bad( Reservations::update( $row['id'], booking( 3, '2035-06-16T09:00', '2035-06-16T11:00', 'completed' ), 2 ), 'completed correction cannot move the rental into the future' );
	$confirmed = good( Reservations::create( booking() ), 'started confirmed fixture' );
	bad( Reservations::update( $confirmed['id'], booking( 1, '2035-06-15T09:00', '2035-06-15T11:00', 'completed' ), 1 ), 'unfinished confirmed rental cannot skip to completed' );
	policy_clock( '2035-06-15 15:00:00' );
	good( Reservations::update( $confirmed['id'], booking( 1, '2035-06-15T09:00', '2035-06-15T11:00', 'completed' ), 1 ), 'admin may record a rental completed once it has ended' );

	clear_inventory(); policy_clock( '2035-06-15 12:00:00' );
	$row = good( Reservations::create( booking( 6 ) ), 'legacy active fixture starts as valid confirmed record' );
	$wpdb->update( Database::table( 'reservations' ), array( 'status' => 'active' ), array( 'id' => $row['id'] ) ); // Existing pre-upgrade invalid state only.
	verify( 10 === Availability::check( '2035-06-20 12:00:00', '2035-06-20 22:00:00' )['available_quantity'], 'legacy future active record is bounded without rewriting data' );
	verify( 4 === Availability::check( '2035-06-15 13:00:00', '2035-06-15 15:00:00' )['available_quantity'], 'legacy future active record still protects its scheduled bikes' );
	bad( Reservations::mark_active( $row['id'], 1 ), 'legacy future active no-op cannot bypass timing validation' );
	good( Reservations::update( $row['id'], booking( 6 ), 1 ), 'staff can correct legacy future active to confirmed' );
	$later = good( Reservations::create( booking( 6, '2035-06-16T09:00', '2035-06-16T11:00' ) ), 'disjoint future commitment fixture' );
	policy_clock( '2035-06-15 13:00:01' );
	good( Reservations::mark_active( $row['id'], 2 ), 'activation after start succeeds with disjoint future commitments' );
	good( Fleet::set_capacity( 6 ), 'capacity reduction uses disjoint peak for in-progress active and confirmed rentals' );
	policy_clock( '2035-06-15 15:00:01' );
	verify( -6 === Availability::check( '2035-06-16 13:00:00', '2035-06-16 15:00:00' )['available_quantity'], 'overdue return exposes conflict with retained future commitment' );
	verify( $later === Reservations::read( $later['id'] ), 'becoming overdue never cancels or rewrites existing future bookings' );
} finally {
	$wpdb->query( 'SET timestamp = DEFAULT' );
}
echo PHP_EOL . ( $checks - $prior_checks ) . ' active-policy checks + ' . $prior_checks . ' preceding checks = ' . $checks . ' passed.' . PHP_EOL;
