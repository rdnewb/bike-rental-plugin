<?php
/** Real InnoDB engine tests; inherits the strict disposable database opt-in and regressions. */
require __DIR__ . '/reservation-editing.php';
use BikeRentalPlugin\Availability;
use BikeRentalPlugin\Database;
use BikeRentalPlugin\Fleet;
use BikeRentalPlugin\HoldCleanup;
use BikeRentalPlugin\Reservations;
use BikeRentalPlugin\Settings;
use BikeRentalPlugin\DataAdmin;
$previous_checks = $checks;
function clear_inventory( $capacity = 10 ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'reservations' ) ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <> 1', Database::table( 'availability' ) ) );
	$wpdb->update( Database::table( 'availability' ), array( 'quantity' => $capacity ), array( 'id' => 1 ) );
	$settings = Settings::get(); $settings['preparation_buffer'] = 0; $settings['turnaround_buffer'] = 0; update_option( Settings::OPTION, $settings );
}
function booking( $quantity = 1, $start = '2035-06-15T09:00', $end = '2035-06-15T11:00', $status = 'confirmed' ) {
	return array( 'package_product_id' => $GLOBALS['product_id'], 'quantity' => $quantity, 'start' => $start, 'end' => $end, 'status' => $status );
}
function capacity_at( $start = '2035-06-15 13:00:00', $end = '2035-06-15 17:00:00', $quantity = 1 ) { return Availability::check( $start, $end, $quantity ); }
function maintenance( $quantity = 1, $start = '2035-06-15T09:00', $end = '2035-06-15T13:00' ) { return array( 'quantity' => $quantity, 'start' => $start, 'end' => $end, 'reason' => 'Engine test', 'active' => 1 ); }
clear_inventory();
verify( 10 === capacity_at()['available_quantity'], 'empty schedule has full fleet' );
$first = good( Reservations::create( booking( 6 ) ), 'first six-bike reservation fits' );
verify( 4 === capacity_at()['available_quantity'], 'single confirmed allocation reduces capacity' );
$second = good( Reservations::create( booking( 6, '2035-06-15T11:00', '2035-06-15T13:00' ) ), 'back-to-back six-bike reservation fits' );
$available = capacity_at();
verify( 6 === $available['peak_existing_usage'] && 4 === $available['available_quantity'], 'disjoint consumers inside longer query use peak, not sum' );
verify( capacity_at( '2035-06-15 17:00:00', '2035-06-15 18:00:00' )['available_quantity'] === 10, 'half-open end boundary has no overlap' );
verify( capacity_at( '2035-06-15 13:00:00', '2035-06-15 17:00:00', 4 )['fits'], 'exact remaining quantity fits' );
verify( ! capacity_at( '2035-06-15 13:00:00', '2035-06-15 17:00:00', 5 )['fits'], 'quantity above remaining does not fit' );
$nested = good( Reservations::create( booking( 4, '2035-06-15T10:00', '2035-06-15T12:00' ) ), 'nested reservation can fill remaining pool' );
verify( 10 === capacity_at()['peak_existing_usage'], 'nested and partially overlapping intervals sweep correctly' );
bad( Reservations::create( booking( 1 ) ), 'overlapping manual create cannot exceed fleet' );
good( Reservations::cancel( $nested['id'], 1 ), 'cancellation releases allocation' );
verify( 4 === capacity_at()['available_quantity'], 'cancelled row is ignored without deleting it' );
$complete = good( Reservations::create( booking( 10, '2035-06-15T09:00', '2035-06-15T13:00', 'completed' ) ), 'completed fixture does not allocate inventory' );
verify( 4 === capacity_at()['available_quantity'], 'completed status ignored' );
$block = good( Fleet::save_block( maintenance( 2 ) ), 'maintenance shares same pool' );
verify( 2 === capacity_at()['available_quantity'], 'active block contributes quantity' );
bad( Fleet::save_block( maintenance( 3 ) ), 'new block beyond remaining fleet rejected' );
bad( Fleet::save_block( maintenance( 5 ), $block['id'] ), 'block replacement conflict rejected' );
verify( $block === Fleet::block( $block['id'] ), 'failed block replacement preserves original' );
$block = good( Fleet::save_block( maintenance( 4 ), $block['id'] ), 'block edit excludes its current allocation' );
verify( 0 === capacity_at()['available_quantity'], 'replacement block uses only new quantity' );
good( Fleet::disable_block( $block['id'] ), 'disable block releases inventory' );
verify( 4 === capacity_at()['available_quantity'], 'disabled block ignored' );
good( Fleet::set_capacity( 12 ), 'capacity increase succeeds' );
good( Fleet::set_capacity( 6 ), 'capacity reduction exactly to peak succeeds' );
bad( Fleet::set_capacity( 5 ), 'capacity reduction below aggregate commitments rejected' );
verify( 6 === Fleet::capacity(), 'failed reduction preserves capacity' );
good( Fleet::set_capacity( 10 ), 'restore fleet for replacement edit checks' );
$before = Reservations::read( $second['id'] );
bad( Reservations::update( $second['id'], booking( 6, '2035-06-15T10:00', '2035-06-15T13:00' ), 1 ), 'reservation edit into another allocation rejected' );
verify( $before === Reservations::read( $second['id'] ), 'failed edit preserves revision, snapshot, interval, and quantity' );
$edited = good( Reservations::update( $second['id'], booking( 4, '2035-06-15T10:00', '2035-06-15T13:00' ), 1 ), 'replacement edit within capacity succeeds' );
verify( 2 === (int) $edited['revision'], 'valid inventory edit retains revision increment' );
bad( Reservations::update( $second['id'], booking(), 1 ), 'stale edit still rejected' );
clear_inventory();
$indefinite = good( Fleet::save_block( maintenance( 3, '2035-06-15T09:00', '' ) ), 'indefinite block created' );
verify( 7 === capacity_at( '2090-01-01 00:00:00', '2090-01-02 00:00:00' )['available_quantity'], 'indefinite block persists arbitrarily far forward' );
$active = good( Reservations::create( booking( 4, '2020-01-01T09:00', '2020-01-01T11:00', 'active' ) ), 'overdue active rental allocated until return' );
verify( 3 === capacity_at()['available_quantity'], 'active rental counts even long after scheduled end' );
bad( Fleet::set_capacity( 6 ), 'reduction accounts for indefinite block plus overdue active rental' );
good( Reservations::mark_completed( $active['id'], 1 ), 'completion releases active rental' );
verify( 7 === capacity_at()['available_quantity'], 'returned rental without buffer no longer counts' );
clear_inventory();
$settings = Settings::get(); $settings['preparation_buffer'] = 15; $settings['turnaround_buffer'] = 30; update_option( Settings::OPTION, $settings );
$buffered = good( Reservations::create( booking( 10 ) ), 'buffered reservation created' );
verify( 0 === capacity_at( '2035-06-15 12:50:00', '2035-06-15 13:00:00' )['available_quantity'], 'preparation buffer consumes capacity before rental start' );
verify( 0 === capacity_at( '2035-06-15 15:00:00', '2035-06-15 15:29:00' )['available_quantity'], 'turnaround buffer consumes capacity after rental end' );
verify( 10 === capacity_at( '2035-06-15 15:30:00', '2035-06-15 16:00:00' )['available_quantity'], 'buffer end is half-open' );
good( Reservations::cancel( $buffered['id'], 1 ), 'buffered cancellation releases occupied interval' );
$returning = good( Reservations::create( booking( 2, '2020-01-01T09:00', '2020-01-01T11:00', 'active' ) ), 'active fixture for return turnaround' );
good( Reservations::mark_completed( $returning['id'], 1 ), 'actual completion creates return turnaround block atomically' );
$now = gmdate( 'Y-m-d H:i:s' );
verify( 8 === Availability::check( $now, \BikeRentalPlugin\RentalTime::shift( $now, 10 ) )['available_quantity'], 'completed row ignored while actual-return block protects turnaround' );
verify( 10 === Availability::check( \BikeRentalPlugin\RentalTime::shift( $now, 31 ), \BikeRentalPlugin\RentalTime::shift( $now, 40 ) )['available_quantity'], 'return turnaround releases after buffer without cron' );
$block_count = Database::listing( 'availability' )['total'];
good( Reservations::mark_completed( $returning['id'], 2 ), 'duplicate completion is a no-op' );
verify( $block_count === Database::listing( 'availability' )['total'], 'completion retry creates no duplicate turnaround block' );
clear_inventory( 1 );
$session = hash( 'sha256', 'disposable-session' );
$hold = good( Reservations::create_hold( booking(), 'hold-request-one', $session ), 'hold consumes final bike' );
verify( 0 === capacity_at()['available_quantity'], 'unexpired hold counted' );
verify( strlen( $hold['request_hash'] ) === 64 && $hold['session_hash'] === $session, 'hold has intent hash and hashed session' );
$repeat = good( Reservations::create_hold( booking(), 'hold-request-one', $session ), 'same request key and hash reuses existing result' );
verify( $repeat === $hold && Database::listing( 'reservations' )['total'] === 1, 'duplicate request neither renews expiry nor doubles allocation' );
bad( Reservations::create_hold( booking( 2 ), 'hold-request-one', $session ), 'same key with changed hash rejected' );
bad( Reservations::create_hold( booking(), 'hold-request-one', hash( 'sha256', 'other' ) ), 'same key from another session rejected' );
$confirmed = good( Reservations::confirm_hold( $hold['id'], 1 ), 'hold confirmation rechecks under shared lock' );
verify( 'confirmed' === $confirmed['status'] && 0 === capacity_at()['available_quantity'], 'confirmation retains exactly one allocation' );
good( Reservations::cancel( $hold['id'], 2 ), 'confirmed hold can be cancelled' );
$expired = good( Reservations::create_hold( booking(), 'hold-request-expire', $session ), 'expiry fixture created' );
$wpdb->update( Database::table( 'reservations' ), array( 'hold_expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $expired['id'] ) );
verify( 'hold' === Reservations::read( $expired['id'] )['status'] && 1 === capacity_at()['available_quantity'], 'expired timestamp stops allocation immediately before cleanup' );
verify( 1 === Reservations::expire_holds() && 'expired' === Reservations::read( $expired['id'] )['status'], 'cleanup marks timestamp-expired holds and increments revision' );
verify( 0 === Reservations::expire_holds(), 'cleanup is idempotent' );
$expired = good( Reservations::confirm_hold( $expired['id'], 2 ), 'cleaned-up expired hold confirms only after fresh capacity recheck' );
good( Reservations::cancel( $expired['id'], 3 ), 'release expired-then-confirmed fixture' );
$expired = good( Reservations::create_hold( booking(), 'hold-request-lost', $session ), 'expired conflict fixture' );
$wpdb->update( Database::table( 'reservations' ), array( 'hold_expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $expired['id'] ) );
$winner = good( Reservations::create( booking() ), 'another reservation uses released expired capacity' );
bad( Reservations::confirm_hold( $expired['id'], 1 ), 'expired hold cannot force overbooking' );
verify( 'hold' === Reservations::read( $expired['id'] )['status'], 'failed expired confirmation preserves row' );
HoldCleanup::deactivate();
$scheduler_db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$scheduler_lock = 'brp_cron_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix ), 0, 40 );
verify( '1' === (string) $scheduler_db->get_var( $scheduler_db->prepare( 'SELECT GET_LOCK(%s, 0)', $scheduler_lock ) ), 'independent scheduler connection acquires registration lock' );
HoldCleanup::schedule();
verify( false === wp_next_scheduled( HoldCleanup::HOOK ), 'competing scheduler cannot register while another connection owns the lock' );
$scheduler_db->get_var( $scheduler_db->prepare( 'SELECT RELEASE_LOCK(%s)', $scheduler_lock ) ); $scheduler_db->close();
HoldCleanup::schedule(); HoldCleanup::schedule();
$events = 0;
foreach ( _get_cron_array() as $hooks_at_time ) { $events += count( $hooks_at_time[ HoldCleanup::HOOK ] ?? array() ); }
verify( 1 === $events && 300 === wp_get_schedules()['brp_five_minutes']['interval'], 'one recurring five-minute cleanup schedule' );
wp_set_current_user( 0 );
bad( Reservations::expire_holds(), 'anonymous direct cleanup is denied' );
do_action( HoldCleanup::HOOK );
wp_set_current_user( 1 );
verify( 'expired' === Reservations::read( $expired['id'] )['status'], 'scheduled housekeeping works without a logged-in administrator' );
HoldCleanup::deactivate();
verify( false === wp_next_scheduled( HoldCleanup::HOOK ), 'deactivation removes schedule without deleting data' );
foreach ( array( array( '', '2035-01-01 00:00:00', 1 ), array( '2035-01-01 00:00:00', '2035-01-01 00:00:00', 1 ), array( '2035-02-30 00:00:00', '2035-03-01 00:00:00', 1 ), array( '2035-01-01 00:00:00', '2035-01-02 00:00:00', 0 ) ) as $invalid ) { bad( Availability::check( ...$invalid ), 'invalid availability input rejected' ); }
bad( Availability::check( '2035-01-01 00:00:00', '2035-01-02 00:00:00', 1, '1 OR 1=1' ), 'invalid exclusion ID rejected' );
wp_set_current_user( 0 ); bad( capacity_at(), 'unauthorized availability check denied' ); wp_set_current_user( 1 );
$admin = new DataAdmin();
bad( $admin->dispatch( array( 'operation' => 'availability_test', 'id' => 0 ) ), 'availability tester requires nonce' );
$tool = good( $admin->dispatch( form_data( 'availability_test', 0, array( 'start' => '2035-06-15T09:00', 'end' => '2035-06-15T13:00', 'quantity' => 1 ) ) ), 'admin availability tool invokes shared engine' );
verify( false === $tool['fits'] && 0 === $tool['available_quantity'], 'admin tool reports fits and remaining quantity' );
clear_inventory();
$real_db = $wpdb;
$wpdb = new BRP_Failing_Wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $wpdb->set_prefix( 'm3_' ); $wpdb->failure = 'INSERT INTO';
bad( Reservations::create_hold( booking(), 'retry-after-sql-failure', $session ), 'SQL write failure rolls back hold' );
$wpdb->failure = '';
verify( 0 === Database::listing( 'reservations' )['total'], 'failed SQL leaves no partial allocation' );
$retry = good( Reservations::create_hold( booking(), 'retry-after-sql-failure', $session ), 'caller retry after failure succeeds' );
$repeat = good( Reservations::create_hold( booking(), 'retry-after-sql-failure', $session ), 'retry of completed request reuses allocation' );
verify( $retry['id'] === $repeat['id'] && 1 === Database::listing( 'reservations' )['total'], 'no duplicate hold after retry' );
$wpdb->close(); $wpdb = $real_db;
clear_inventory( 1 );
class BRP_Disconnecting_Wpdb extends wpdb {
	public $disconnect_once = true;
	public function query( $query ) {
		if ( $this->disconnect_once && str_starts_with( $query, 'INSERT INTO `m3_brp_reservations`' ) ) {
			$this->disconnect_once = false;
			$connection = $this->get_var( 'SELECT CONNECTION_ID()' );
			$killer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$killer->query( $killer->prepare( 'KILL CONNECTION %d', $connection ) ); $killer->close();
		}
		return parent::query( $query );
	}
}
$wpdb = new BRP_Disconnecting_Wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $wpdb->set_prefix( 'm3_' );
$lost = Reservations::create_hold( booking(), 'lost-connection-request', $session );
bad( $lost, 'lost connection cannot auto-retry an allocation without its inventory lock' );
verify( 'brp_retry' === $lost->get_error_code(), 'connection failure returns bounded retryable result' );
verify( 0 === Database::listing( 'reservations' )['total'], 'SQL session guard prevents autocommit write after wpdb reconnect' );
$reconnected = good( Reservations::create_hold( booking(), 'lost-connection-request', $session ), 'explicit retry obtains a new lock and creates hold' );
verify( 1 === Database::listing( 'reservations' )['total'], 'reconnected retry allocates only once' );
$wpdb->close(); $wpdb = $real_db;
verify( Database::VERSION === '1', 'engine does not require schema change' );
echo PHP_EOL . ( $checks - $previous_checks ) . ' availability checks + ' . $previous_checks . ' preceding real regression checks = ' . $checks . ' passed.' . PHP_EOL;
