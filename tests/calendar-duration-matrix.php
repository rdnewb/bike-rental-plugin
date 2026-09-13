<?php
/** Every weekday x durations 1-7, plus longer valid durations, using real product/services. */
ob_start();
require __DIR__ . '/calendar-booking.php';
use BikeRentalPlugin\{Availability, BookingSchedule, Database, Packages, PublicBooking, RentalTime, Reservations, Settings};
$previous_checks = $checks;
// Independent consecutive-date list, including month/year boundaries for longer packages.
$dates = array();
foreach ( new DatePeriod( new DateTimeImmutable( $date, wp_timezone() ), new DateInterval( 'P1D' ), 400 ) as $day ) { $dates[] = $day->format( 'Y-m-d' ); }
$weekdays = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
for ( $duration = 1; $duration <= 7; ++$duration ) {
	$product->update_meta_data( Packages::AMOUNT, $duration ); $product->save();
	foreach ( $weekdays as $offset => $weekday ) {
		calendar_reset();
		$matrix = $settings; $matrix['pickup_time'] = '19:00'; $matrix['preparation_buffer'] = 30; $matrix['turnaround_buffer'] = 60;
		// Every other weekday is closed, including intermediate and final dates for N > 1.
		foreach ( $matrix['weekly_hours'] as $name => &$hours ) { $hours = array( 'open' => $name === $weekday ? 1 : 0, 'start' => '08:00', 'end' => '18:00' ); } unset( $hours );
		update_option( Settings::OPTION, $matrix );
		$case_input = array( 'package_id' => $package_id, 'date' => $dates[ $offset ] );
		$label = $duration . ' days from ' . $weekday;
		$diagnostic = PublicBooking::diagnose_times( $case_input );
		calendar_check( ! is_wp_error( $diagnostic ) && 20 === count( $diagnostic['times'] ) && in_array( '09:00', array_column( $diagnostic['times'], 'time' ), true ), $label . ': start candidates allowed without weekday restrictions' );
		$schedule = $diagnostic['diagnostics']['candidates'][2]['schedule'];
		$expected_end = $dates[ $offset + $duration - 1 ] . 'T19:00';
		calendar_check( $expected_end === $schedule['local_end'] && $dates[ $offset ] . 'T09:00' === $schedule['local_start'], $label . ': final included date and configured pickup correct with other weekdays closed' );
		$expected_start_utc = ( new DateTimeImmutable( $dates[ $offset ] . ' 09:00', wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$expected_end_utc = ( new DateTimeImmutable( $expected_end, wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		calendar_check( $expected_start_utc->format( 'Y-m-d H:i:s' ) === $schedule['start_utc'] && $expected_end_utc->format( 'Y-m-d H:i:s' ) === $schedule['end_utc'], $label . ': customer inventory interval converts to correct UTC endpoints' );
		calendar_check( $expected_start_utc->sub( new DateInterval( 'PT30M' ) )->format( 'Y-m-d H:i:s' ) === $schedule['occupied_start_utc'] && $expected_end_utc->add( new DateInterval( 'PT1H' ) )->format( 'Y-m-d H:i:s' ) === $schedule['occupied_end_utc'], $label . ': preparation and turnaround stay outside customer duration' );
		$current_package = BookingSchedule::package( $package_id );
		$closed_start = $matrix; $closed_start['weekly_hours'][ $weekday ]['open'] = 0;
		calendar_reason( BookingSchedule::calculate( $current_package, $dates[ $offset ], '09:00', $closed_start ), 'closed_start_day', $label . ': Day 1 must be open' );
		calendar_reason( BookingSchedule::calculate( $current_package, $dates[ $offset ], '07:30', $matrix ), 'invalid_start_time', $label . ': Day 1 start must be within hours' );
		calendar_reason( BookingSchedule::calculate( $current_package, $dates[ $offset ], '09:17', $matrix ), 'invalid_start_time', $label . ': Day 1 increment remains enforced' );
		$before_start = $expected_start_utc->sub( new DateInterval( 'PT10M' ) )->format( 'Y-m-d H:i:s' );
		$notice = $matrix; $notice['minimum_notice'] = 11;
		calendar_reason( BookingSchedule::calculate( $current_package, $dates[ $offset ], '09:00', $notice, $before_start ), 'minimum_notice', $label . ': minimum notice remains enforced' );
		$horizon = $matrix; $horizon['booking_horizon'] = 1;
		calendar_reason( BookingSchedule::calculate( $current_package, $dates[ $offset ], '09:00', $horizon, $expected_start_utc->sub( new DateInterval( 'P3D' ) )->format( 'Y-m-d H:i:s' ) ), 'booking_horizon', $label . ': start-date horizon remains enforced' );
		$hold = Reservations::create_booking_hold( $case_input + array( 'time' => '09:00', 'quantity' => 10 ), 'matrix-' . $duration . '-' . $offset, hash( 'sha256', 'matrix-session' ) );
		calendar_check( ! is_wp_error( $hold ) && $schedule['occupied_start_utc'] === $hold['occupied_start_utc'] && $schedule['occupied_end_utc'] === $hold['occupied_end_utc'] && $expected_end === json_decode( $hold['snapshot'], true )['local_end'], $label . ': saved hold and snapshot match generic schedule and buffers' );
		calendar_check( 0 === Availability::check( $schedule['occupied_start_utc'], $schedule['occupied_end_utc'] )['available_quantity'] && 10 === Availability::check( $schedule['occupied_end_utc'], RentalTime::shift( $schedule['occupied_end_utc'], 1 ) )['available_quantity'], $label . ': capacity claimed only through buffered half-open interval' );
		$conflict = Reservations::create_booking_hold( $case_input + array( 'time' => '09:00', 'quantity' => 1 ), 'conflict-' . $duration . '-' . $offset, hash( 'sha256', 'other-matrix-session' ) );
		calendar_check( is_wp_error( $conflict ) && 'brp_conflict' === $conflict->get_error_code(), $label . ': inventory still prevents overselling' );
	}
}
calendar_reset();
// No special cases for the tested durations: exercise beyond one week and across year end.
foreach ( array( 8, 14, 30, 90, Packages::MAX_DAYS ) as $duration ) {
	$product->update_meta_data( Packages::AMOUNT, $duration ); $product->save();
	$long_settings = $settings; $long_settings['pickup_time'] = '05:00';
	foreach ( $long_settings['weekly_hours'] as $name => &$hours ) { $hours['open'] = 'monday' === $name ? 1 : 0; } unset( $hours );
	update_option( Settings::OPTION, $long_settings );
	$current_package = BookingSchedule::package( $package_id );
	$result = BookingSchedule::calculate( $current_package, $dates[0], '09:00', $long_settings );
	calendar_check( ! is_wp_error( $result ) && $dates[ $duration - 1 ] . 'T05:00' === $result['local_end'], $duration . '-day configured product uses generic inclusive dates beyond a week' );
}
$product->update_meta_data( Packages::AMOUNT, 1 ); $product->save();
$one = BookingSchedule::package( $package_id );
calendar_reason( BookingSchedule::calculate( $one, $dates[0], '09:00', $long_settings ), 'invalid_pickup_time', 'one-day pickup before the start cannot create a negative interval' );
$late_pickup = $settings; $late_pickup['pickup_time'] = '19:00';
calendar_check( $dates[0] . 'T19:00' === BookingSchedule::calculate( $one, $dates[0], '17:30', $late_pickup )['local_end'], 'one-day pickup after delivery closing succeeds with positive duration' );
$hour_package = BookingSchedule::package( $hourly_id );
$hour_settings = $settings; $hour_settings['weekly_hours']['monday']['end'] = '12:00';
calendar_reason( BookingSchedule::calculate( $hour_package, $dates[0], '09:00', $hour_settings ), 'pickup_outside_final_hours', 'hourly end must still fit its delivery hours' );
$hour_package['duration_amount'] = 24; $hour_settings = $settings; $hour_settings['weekly_hours']['tuesday']['open'] = 0;
calendar_reason( BookingSchedule::calculate( $hour_package, $dates[0], '09:00', $hour_settings ), 'closed_final_day', 'hourly closed final-day rule remains unchanged' );
foreach ( array( 0, -1, '1.5', null, 'invalid' ) as $invalid_duration ) {
	$bad_package = $one; $bad_package['duration_amount'] = $invalid_duration;
	calendar_reason( BookingSchedule::calculate( $bad_package, $dates[0], '09:00', $settings ), 'invalid_package_metadata', 'invalid duration cannot enter generic calendar arithmetic: ' . var_export( $invalid_duration, true ) );
}
echo PHP_EOL . ( $checks - $previous_checks ) . ' duration-matrix checks + ' . $previous_checks . ' calendar checks = ' . $checks . ' passed.' . PHP_EOL;
ob_end_flush();
