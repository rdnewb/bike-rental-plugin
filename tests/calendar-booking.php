<?php
/** Calendar-day packages through real WooCommerce metadata, REST, and InnoDB. Disposable only. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
use BikeRentalPlugin\{BookingSchedule, Database, Fleet, Packages, PublicBooking, RentalTime, Reservations, Settings};
$checks = 0;
function calendar_check( $value, $label ) { if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo 'PASS: ' . $label . PHP_EOL; }
function calendar_request( $route, $input ) {
	$request = new WP_REST_Request( 'GET', '/' . PublicBooking::API . '/' . $route );
	foreach ( $input as $key => $value ) { $request->set_param( $key, $value ); }
	wp_set_current_user( 0 );
	try { return rest_get_server()->dispatch( $request ); } finally { wp_set_current_user( 1 ); }
}
function calendar_reset() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'reservations' ) ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <> 1', Database::table( 'availability' ) ) );
	$wpdb->update( Database::table( 'availability' ), array( 'quantity' => 10 ), array( 'id' => 1 ) );
}
function calendar_reason( $result, $reason, $label ) { calendar_check( is_wp_error( $result ) && $reason === ( $result->get_error_data()['reason'] ?? '' ), $label ); }
function calendar_times( $input, $settings ) { update_option( Settings::OPTION, $settings ); return PublicBooking::diagnose_times( $input ); }
$_SERVER['REMOTE_ADDR'] = 'fd00:' . implode( ':', str_split( bin2hex( random_bytes( 14 ) ), 4 ) );
calendar_reset();
update_option( 'timezone_string', 'America/New_York' );
$settings = Settings::defaults();
foreach ( $settings['weekly_hours'] as &$hours ) { $hours = array( 'open' => 1, 'start' => '08:00', 'end' => '18:00' ); } unset( $hours );
update_option( Settings::OPTION, $settings );
$product = new WC_Product_Simple(); $product->set_name( 'Calendar integration fixture' ); $product->set_status( 'publish' ); $product->set_regular_price( '100.00' );
foreach ( array( Packages::ENABLED => 'yes', Packages::ACTIVE => 'yes', Packages::TYPE => 'calendar_days', Packages::AMOUNT => '3' ) as $key => $value ) { $product->update_meta_data( $key, $value ); }
$package_id = $product->save();
$package = BookingSchedule::package( $package_id );
calendar_check( ! is_wp_error( $package ) && 'calendar_days' === $package['duration_type'] && 3 === $package['duration_amount'], 'real WooCommerce metadata loads three calendar days as an integer' );
$clock = '2030-06-17 10:00:00';
$three = BookingSchedule::calculate( $package, '2030-06-17', '09:00', $settings, $clock );
calendar_check( ! is_wp_error( $three ) && '2030-06-19T17:00' === $three['local_end'], 'three-day Monday 09:00 ends Wednesday 17:00, not Thursday' );
calendar_check( '2030-06-17 13:00:00' === $three['start_utc'] && '2030-06-19 21:00:00' === $three['end_utc'], 'calendar endpoints convert through WordPress timezone into UTC' );
$five = $package; $five['duration_amount'] = 5;
$result = BookingSchedule::calculate( $five, '2030-06-17', '09:00', $settings, $clock );
calendar_check( ! is_wp_error( $result ) && '2030-06-21T17:00' === $result['local_end'], 'five-day Monday start ends Friday at configured pickup' );
calendar_check( 56 * 3600 === strtotime( $three['end_utc'] . ' UTC' ) - strtotime( $three['start_utc'] . ' UTC' ), 'three calendar days do not become 72 elapsed hours' );
$one = $package; $one['duration_amount'] = 1;
calendar_check( '2030-06-17T17:00' === BookingSchedule::calculate( $one, '2030-06-17', '09:00', $settings, $clock )['local_end'], 'one calendar day ends on start date' );
calendar_reason( BookingSchedule::calculate( $one, '2030-06-17', '17:00', $settings, $clock ), 'invalid_pickup_time', 'one-day zero interval rejected with reason' );
$date = ( new DateTimeImmutable( 'next monday', wp_timezone() ) )->modify( '+7 days' )->format( 'Y-m-d' );
$end_date = ( new DateTimeImmutable( $date, wp_timezone() ) )->modify( '+2 days' )->format( 'Y-m-d' );
$input = array( 'package_id' => $package_id, 'date' => $date );
$public = calendar_request( 'times', $input );
$times = $public->get_data();
$expected = array(); for ( $minute = 480; $minute < 1080; $minute += 30 ) { $expected[] = sprintf( '%02d:%02d', intdiv( $minute, 60 ), $minute % 60 ); }
calendar_check( 200 === $public->get_status() && $expected === array_column( $times['times'], 'time' ), 'calendar REST start candidates cover start-day hours 08:00 through 17:30' );
calendar_check( ! in_array( '18:00', array_column( $times['times'], 'time' ), true ), 'existing exclusive closing boundary for starts remains unchanged' );
$diag = PublicBooking::diagnose_times( $input );
calendar_check( 20 === count( $diag['diagnostics']['candidates'] ) && array( 'available' ) === array_values( array_unique( array_column( $diag['diagnostics']['candidates'], 'reason' ) ) ), 'protected diagnostics identify each successful candidate' );
$display = calendar_request( 'availability', $input + array( 'time' => '17:30' ) )->get_data();
calendar_check( $end_date . 'T17:00' === $display['rental_end'], 'late start may run overnight to Wednesday pickup instead of fitting into Monday' );
calendar_check( ! array_intersect( array( 'diagnostics', 'candidates', 'reason', 'database_utc', 'occupied_start_utc' ), array_keys( $times ) ), 'public times omit internal diagnostic fields' );
calendar_check( 400 === calendar_request( 'times', $input + array( 'diagnose' => true ) )->get_status(), 'public query cannot enable diagnostics' );
wp_set_current_user( 0 ); $denied = PublicBooking::diagnose_times( $input ); wp_set_current_user( 1 );
calendar_check( is_wp_error( $denied ) && 'brp_permission' === $denied->get_error_code(), 'anonymous PHP caller cannot obtain diagnostics' );
$closed = $settings; $closed['weekly_hours']['tuesday']['open'] = 0;
calendar_check( 20 === count( calendar_times( $input, $closed )['times'] ), 'calendar rental may cross closed intermediate Tuesday' );
$closed['weekly_hours']['wednesday']['open'] = 0;
$rejected = calendar_times( $input, $closed );
calendar_check( array() === $rejected['times'] && 'closed_final_day' === $rejected['diagnostics']['candidates'][0]['reason'], 'closed final Wednesday rejects without extending to Thursday' );
calendar_check( str_contains( calendar_request( 'times', $input )->get_data()['message'], 'closed day' ), 'public empty-time response preserves safe closed-final-day explanation' );
$closed = $settings; $closed['weekly_hours']['monday']['open'] = 0;
calendar_reason( calendar_times( $input, $closed ), 'closed_start_day', 'closed start day diagnostic' );
foreach ( array( '07:30', '18:30', '05:00' ) as $pickup ) {
	$bad = $settings; $bad['pickup_time'] = $pickup;
	$rejected = calendar_times( $input, $bad );
	calendar_check( array() === $rejected['times'] && array( 'pickup_outside_final_hours' ) === array_values( array_unique( array_column( $rejected['diagnostics']['candidates'], 'reason' ) ) ), 'every candidate explains pickup outside final-day hours: ' . $pickup );
	calendar_check( str_contains( calendar_request( 'times', $input )->get_data()['message'], 'outside pickup hours' ), 'public failure no longer silently looks sold out: ' . $pickup );
}
ob_start(); ( new Settings() )->render(); $settings_html = ob_get_clean();
calendar_check( str_contains( $settings_html, 'Calendar-day pickup time is outside operating hours on:' ) && str_contains( $settings_html, 'Wednesday' ), 'admin settings visibly warn about pickup-hours mismatch' );
calendar_check( str_contains( $settings_html, 'Check AM/PM' ) && ! str_contains( $settings_html, 'Public booking is not available' ), 'pickup field explains inclusive final day and AM/PM' );
for ( $offset = 0; $offset < 7; ++$offset ) {
	$weekday_input = array_replace( $input, array( 'date' => ( new DateTimeImmutable( $date, wp_timezone() ) )->modify( '+' . $offset . ' days' )->format( 'Y-m-d' ) ) );
	$bad = $settings; $bad['pickup_time'] = '05:00';
	$rejected = calendar_times( $weekday_input, $bad );
	calendar_check( array() === $rejected['times'] && 'pickup_outside_final_hours' === $rejected['diagnostics']['candidates'][0]['reason'], 'AM pickup mismatch reproduces zero starts for weekday offset ' . $offset );
	calendar_check( 20 === count( calendar_times( $weekday_input, $settings )['times'] ), 'valid PM pickup restores calendar starts for weekday offset ' . $offset );
}
foreach ( array( '08:00', '17:00', '18:00' ) as $pickup ) {
	$valid = $settings; $valid['pickup_time'] = $pickup;
	calendar_check( 20 === count( calendar_times( $input, $valid )['times'] ), 'pickup allowed at opening, inside hours, and at closing: ' . $pickup );
}
$different = $settings; $different['weekly_hours']['monday']['end'] = '12:00';
calendar_check( 8 === count( calendar_times( $input, $different )['times'] ), 'Wednesday 17:00 pickup need not fit inside Monday closing at noon' );
$different = $settings; $different['weekly_hours']['wednesday']['end'] = '16:00';
calendar_check( 'pickup_outside_final_hours' === calendar_times( $input, $different )['diagnostics']['candidates'][0]['reason'], 'pickup checked against final-day hours, not start-day hours' );
$different = $settings; $different['weekly_hours']['monday']['start'] = '08:10'; $different['weekly_hours']['monday']['end'] = '09:20';
calendar_check( array( '08:10', '08:40', '09:10' ) === array_column( calendar_times( $input, $different )['times'], 'time' ), 'candidate increments are relative to start-day opening' );
$hourly = new WC_Product_Simple(); $hourly->set_name( 'Hourly comparison fixture' ); $hourly->set_status( 'publish' ); $hourly->set_regular_price( '50' );
foreach ( array( Packages::ENABLED => 'yes', Packages::ACTIVE => 'yes', Packages::TYPE => 'hours', Packages::AMOUNT => 4 ) as $key => $value ) { $hourly->update_meta_data( $key, $value ); } $hourly_id = $hourly->save();
update_option( Settings::OPTION, $settings );
$hours = calendar_request( 'times', array_replace( $input, array( 'package_id' => $hourly_id ) ) )->get_data();
calendar_check( 13 === count( $hours['times'] ) && '14:00' === end( $hours['times'] )['time'], 'four-hour package behavior and last start remain unchanged' );
$bad = $settings; $bad['pickup_time'] = '05:00'; update_option( Settings::OPTION, $bad );
calendar_check( 13 === count( calendar_request( 'times', array_replace( $input, array( 'package_id' => $hourly_id ) ) )->get_data()['times'] ), 'calendar pickup setting does not affect hourly packages' );
$buffered = $settings; $buffered['preparation_buffer'] = 30; $buffered['turnaround_buffer'] = 60;
$diag = calendar_times( $input, $buffered ); $schedule = $diag['diagnostics']['candidates'][0]['schedule'];
calendar_check( 20 === count( $diag['times'] ) && $date . 'T08:00' === $schedule['local_start'] && $end_date . 'T17:00' === $schedule['local_end'], 'buffers outside operating hours do not reject calendar schedules or change endpoints' );
calendar_check( RentalTime::from_local( $date . 'T07:30' ) === $schedule['occupied_start_utc'] && RentalTime::from_local( $end_date . 'T18:00' ) === $schedule['occupied_end_utc'], 'occupied interval captures buffers separately in UTC' );
$display = calendar_request( 'availability', $input + array( 'time' => '08:00' ) )->get_data();
calendar_check( $end_date . 'T17:00' === $display['rental_end'] && ! isset( $display['occupied_end_utc'] ), 'public display retains unbuffered final date/time' );
$block = Fleet::save_block( array( 'quantity' => 10, 'start' => $end_date . 'T17:30', 'end' => $end_date . 'T18:00', 'reason' => 'Test turnaround overlap', 'active' => 1 ) );
calendar_check( ! is_wp_error( $block ), 'test block outside customer period created' );
$diag = PublicBooking::diagnose_times( $input );
calendar_check( array() === $diag['times'] && array( 'buffer_conflict' ) === array_values( array_unique( array_column( $diag['diagnostics']['candidates'], 'reason' ) ) ), 'protected diagnostics distinguish a buffer-only inventory conflict' );
calendar_check( ! isset( calendar_request( 'times', $input )->get_data()['diagnostics'] ), 'buffer conflict details stay private' );
calendar_reset();
$block = Fleet::save_block( array( 'quantity' => 10, 'start' => $end_date . 'T09:00', 'end' => $end_date . 'T10:00', 'reason' => 'Test final day overlap', 'active' => 1 ) );
$diag = PublicBooking::diagnose_times( $input );
calendar_check( ! is_wp_error( $block ) && array() === $diag['times'] && 'availability' === $diag['diagnostics']['candidates'][0]['reason'], 'inventory on the final day is checked across full occupied interval' );
calendar_reset();
calendar_reason( BookingSchedule::calculate( $package, '2030-06-17', '09:17', $settings, $clock ), 'invalid_start_time', 'misaligned start has specific rejection reason' );
calendar_reason( BookingSchedule::calculate( $package, '2031-06-17', '09:00', $settings, $clock ), 'booking_horizon', 'booking horizon has specific rejection reason' );
$notice = $settings; $notice['minimum_notice'] = 181;
calendar_reason( BookingSchedule::calculate( $package, '2030-06-17', '09:00', $notice, $clock ), 'minimum_notice', 'minimum notice has specific rejection reason' );
$invalid = $package; $invalid['duration_type'] = 'days';
calendar_reason( BookingSchedule::calculate( $invalid, '2030-06-17', '09:00', $settings, $clock ), 'invalid_package_metadata', 'unknown duration type cannot silently use calendar arithmetic' );
$product->update_meta_data( Packages::AMOUNT, 'invalid' ); $product->save();
calendar_reason( PublicBooking::diagnose_times( $input ), 'invalid_package_metadata', 'invalid stored duration has a protected metadata diagnostic' );
$product->update_meta_data( Packages::AMOUNT, 3 ); $product->save();
foreach ( array( array( '2030-03-09', '2030-03-11T17:00', '2030-03-01 00:00:00' ), array( '2030-11-02', '2030-11-04T17:00', '2030-11-01 00:00:00' ) ) as $case ) {
	$result = BookingSchedule::calculate( $package, $case[0], '09:00', $settings, $case[2] );
	calendar_check( ! is_wp_error( $result ) && $case[1] === $result['local_end'], 'calendar endpoint remains local across DST: ' . $case[0] );
}
update_option( 'timezone_string', '' ); update_option( 'gmt_offset', 5.5 );
$result = BookingSchedule::calculate( $package, '2030-06-17', '09:00', $settings, '2030-06-16 00:00:00' );
calendar_check( ! is_wp_error( $result ) && '2030-06-19T17:00' === $result['local_end'] && '2030-06-19 11:30:00' === $result['end_utc'], 'fixed-offset WordPress timezone also preserves inclusive calendar dates' );
update_option( 'timezone_string', 'America/New_York' );
update_option( Settings::OPTION, $settings );
$hold = Reservations::create_booking_hold( $input + array( 'time' => '09:00', 'quantity' => 2 ), 'calendar-hold-test', hash( 'sha256', 'calendar-test-session' ) );
calendar_check( ! is_wp_error( $hold ) && RentalTime::from_local( $end_date . 'T17:00' ) === $hold['end_utc'], 'real calendar hold persists the same inclusive final day as time lookup' );
calendar_check( $end_date . 'T17:00' === json_decode( $hold['snapshot'], true )['local_end'], 'calendar hold snapshot matches displayed schedule' );
echo PHP_EOL . $checks . ' calendar booking checks passed.' . PHP_EOL;
ob_end_flush();
