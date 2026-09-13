<?php
/** Public scheduling rules; no inventory algorithm or customer data. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class BookingSchedule {
	/** Reason codes are for protected diagnostics; REST replies expose only safe messages. */
	public static function error( $message, $reason = 'selection' ) { return new \WP_Error( 'brp_selection', $message, array( 'reason' => $reason ) ); }
	public static function package( $id ) {
		if ( ! Database::positive( $id ) || ! function_exists( 'wc_get_product' ) || ! class_exists( Packages::class ) ) { return self::error( 'Please select an available rental package.', 'invalid_package_metadata' ); }
		$package = Packages::get_package( $id );
		$product = wc_get_product( (int) $id );
		$price = $product ? $product->get_price( 'edit' ) : null;
		if ( ! $package || ! $package['active'] || 'publish' !== $package['product_status'] || ! is_string( $price ) || ! preg_match( '/\A[0-9]+(?:\.[0-9]+)?\z/', $price ) ) { return self::error( 'Please select an available rental package.', 'invalid_package_metadata' ); }
		$package['price'] = $price;
		return $package;
	}
	public static function settings() {
		$result = Settings::validate( Settings::get() );
		return $result['errors'] ? self::error( 'Online rental selection is temporarily unavailable.', 'invalid_settings' ) : $result['values'];
	}
	public static function prepare( $input ) {
		if ( ! is_array( $input ) ) { return self::error( 'Please check your rental selection.' ); }
		$package = self::package( $input['package_id'] ?? null );
		$settings = self::settings();
		if ( is_wp_error( $package ) ) { return $package; }
		if ( is_wp_error( $settings ) ) { return $settings; }
		$schedule = self::calculate( $package, $input['date'] ?? null, $input['time'] ?? null, $settings );
		if ( is_wp_error( $schedule ) ) { return $schedule; }
		return array( 'package' => $package, 'settings' => $settings, 'schedule' => $schedule, 'input' => array( 'package_product_id' => $package['product_id'], 'start' => $schedule['local_start'], 'end' => $schedule['local_end'], 'quantity' => $input['quantity'] ?? 1, 'status' => 'hold' ) );
	}
	/** Start horizon is inclusive in local dates; notice and hourly duration use elapsed UTC. */
	public static function calculate( $package, $date, $time, $settings, $now = null ) {
		if ( ! is_array( $package ) || ! in_array( $package['duration_type'] ?? null, array( 'hours', 'calendar_days' ), true ) || ! Database::positive( $package['duration_amount'] ?? null ) ) { return self::error( 'Please select an available rental package.', 'invalid_package_metadata' ); }
		if ( ! is_string( $date ) || ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date ) || ! is_string( $time ) || ! preg_match( '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time ) ) { return self::error( 'Please select a valid date and start time.', 'invalid_start_time' ); }
		$start = RentalTime::from_local( $date . 'T' . $time );
		if ( is_wp_error( $start ) ) { return self::error( 'Please select a valid, unambiguous start time.', 'invalid_start_time' ); }
		try {
			$utc = new \DateTimeZone( 'UTC' ); $zone = wp_timezone();
			$clock = new \DateTimeImmutable( $now ?? gmdate( 'Y-m-d H:i:s' ), $utc );
			$today = $clock->setTimezone( $zone )->format( 'Y-m-d' );
			$last = ( new \DateTimeImmutable( $today . ' 12:00', $zone ) )->modify( '+' . $settings['booking_horizon'] . ' days' )->format( 'Y-m-d' );
			if ( $date < $today || $date > $last ) { return self::error( 'Please choose a date within the booking window.', 'booking_horizon' ); }
			if ( $start < $clock->modify( '+' . $settings['minimum_notice'] . ' minutes' )->format( 'Y-m-d H:i:s' ) ) { return self::error( 'Please choose a later time to allow the required booking notice.', 'minimum_notice' ); }
			$local = ( new \DateTimeImmutable( $start, $utc ) )->setTimezone( $zone );
			$hours = $settings['weekly_hours'][ strtolower( $local->format( 'l' ) ) ];
			if ( ! $hours['open'] ) { return self::error( 'Please choose an open start day.', 'closed_start_day' ); }
			$minutes = static fn( $value ) => (int) substr( $value, 0, 2 ) * 60 + (int) substr( $value, 3, 2 );
			if ( $time < $hours['start'] || $time >= $hours['end'] || ( $minutes( $time ) - $minutes( $hours['start'] ) ) % $settings['time_increment'] ) { return self::error( 'Please select a valid start time during opening hours.', 'invalid_start_time' ); }
			if ( 'hours' === $package['duration_type'] ) {
				$end = RentalTime::shift( $start, $package['duration_amount'] * 60 );
				$local_end = ( new \DateTimeImmutable( $end, $utc ) )->setTimezone( $zone )->format( 'Y-m-d\TH:i' );
			} else {
				// Inclusive calendar days in the site's timezone; never elapsed 24-hour periods.
				$end_date = ( new \DateTimeImmutable( $date . ' 12:00', $zone ) )->modify( '+' . ( $package['duration_amount'] - 1 ) . ' days' )->format( 'Y-m-d' );
				$local_end = $end_date . 'T' . $settings['pickup_time'];
				$end = RentalTime::from_local( $local_end );
			}
			if ( is_wp_error( $end ) || RentalTime::from_local( $local_end ) !== $end || $end <= $start ) { return self::error( 'This start time cannot produce a valid pickup time.', 'invalid_pickup_time' ); }
			$end_local = ( new \DateTimeImmutable( $end, $utc ) )->setTimezone( $zone );
			$end_hours = $settings['weekly_hours'][ strtolower( $end_local->format( 'l' ) ) ];
			if ( ! $end_hours['open'] ) { return self::error( 'This rental would end on a closed day. Please choose another start date.', 'closed_final_day' ); }
			if ( $end_local->format( 'H:i' ) < $end_hours['start'] || $end_local->format( 'H:i' ) > $end_hours['end'] ) { return self::error( 'This rental would end outside pickup hours. Please choose another date or contact the rental shop.', 'pickup_outside_final_hours' ); }
			return array( 'start_utc' => $start, 'end_utc' => $end, 'local_start' => $date . 'T' . $time, 'local_end' => $local_end, 'timezone' => $zone->getName(), 'occupied_start_utc' => RentalTime::shift( $start, -$settings['preparation_buffer'] ), 'occupied_end_utc' => RentalTime::shift( $end, $settings['turnaround_buffer'] ) );
		} catch ( \Exception $error ) { return self::error( 'Online rental selection is temporarily unavailable.', 'invalid_schedule' ); }
	}
}
