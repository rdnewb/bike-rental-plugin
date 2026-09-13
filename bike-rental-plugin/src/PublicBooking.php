<?php
/** Shortcode and deliberately small public REST boundary. No checkout integration. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class PublicBooking {
	const API = 'bike-rental/v1';
	public static function register_hooks() {
		add_action( 'init', static function () { add_shortcode( 'bike_rental_booking', array( self::class, 'shortcode' ) ); } );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}
	public static function routes() {
		foreach ( array( 'packages', 'times', 'availability', 'session', 'holds', 'hold-status' ) as $route ) {
			$public = in_array( $route, array( 'packages', 'times', 'availability' ), true );
			register_rest_route( self::API, '/' . $route, array(
				'methods' => $public ? 'GET' : 'POST',
				'permission_callback' => $public ? '__return_true' : static fn( $request ) => GuestSession::permission( $request, 'session' !== $route ),
				'callback' => array( self::class, 'handle' ),
			) );
		}
	}
	public static function handle( $request ) {
		global $wpdb;
		$suppression = $wpdb->suppress_errors( true );
		try { return self::dispatch( $request ); }
		catch ( \Throwable $error ) { return self::reply( new \WP_Error( 'brp_unavailable', 'Online rental selection is temporarily unavailable. Please try again.' ) ); }
		finally { $wpdb->suppress_errors( $suppression ); }
	}
	private static function dispatch( $request ) {
		$route = basename( $request->get_route() );
		$private = in_array( $route, array( 'session', 'holds', 'hold-status' ), true );
		if ( $private ) {
			$permission = GuestSession::permission( $request, 'session' !== $route );
			if ( is_wp_error( $permission ) ) { return self::reply( $permission ); }
		}
		$limit = GuestSession::limit( 'holds' === $route );
		if ( is_wp_error( $limit ) ) { return self::reply( $limit ); }
		$allowed = match ( $route ) { 'packages', 'session' => array(), 'times' => array( 'package_id', 'date' ), 'availability' => array( 'package_id', 'date', 'time' ), 'holds' => array( 'package_id', 'date', 'time', 'quantity', 'request_key' ), 'hold-status' => array( 'request_key' ), default => array() };
		$input = $request->get_params();
		unset( $input['rest_route'] );
		if ( array_diff( array_keys( $input ), $allowed ) || array_diff( $allowed, array_keys( $input ) ) ) { return self::reply( BookingSchedule::error( 'Please complete the requested booking fields.' ) ); }
		try {
			$result = Database::public_booking( static function () use ( $route, $input ) {
				return match ( $route ) {
					'packages' => self::packages(),
					'times' => self::times( $input ),
					'availability' => self::availability( $input ),
					'session' => self::session(),
					'holds' => self::hold( $input ),
					'hold-status' => self::status( $input ),
					default => BookingSchedule::error( 'Please use the booking form.' ),
				};
			} );
		} catch ( \Throwable $error ) { $result = new \WP_Error( 'brp_unavailable', 'Online rental selection is temporarily unavailable. Please try again.' ); }
		return self::reply( $result );
	}
	private static function reply( $result ) {
		$status = 200;
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			$message = 'Online rental selection is temporarily unavailable. Please try again.';
			$status = 503;
			if ( in_array( $code, array( 'brp_selection', 'brp_session', 'brp_existing_hold', 'brp_idempotency', 'brp_rate' ), true ) ) { $message = $result->get_error_message(); $status = $result->get_error_data()['status'] ?? 400; }
			if ( 'brp_conflict' === $code ) { $message = sprintf( 'Only %d bikes are available. Please choose another time or quantity.', max( 0, $result->get_error_data()['available_quantity'] ?? 0 ) ); $status = 409; }
			if ( in_array( $code, array( 'brp_quantity', 'brp_request' ), true ) ) { $message = 'Please check your bike quantity and rental selection.'; $status = 400; }
			$result = array( 'valid' => false, 'message' => $message );
		}
		$response = new \WP_REST_Response( $result, $status );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		$response->header( 'Vary', 'Cookie, Origin' );
		return $response;
	}
	private static function package_view( $package ) {
		return array_intersect_key( $package, array_flip( array( 'product_id', 'name', 'price', 'currency', 'duration_type', 'duration_amount', 'promotional_label' ) ) ) + array( 'price_html' => wc_price( $package['price'], array( 'currency' => $package['currency'] ) ) );
	}
	private static function packages() {
		$settings = BookingSchedule::settings();
		if ( is_wp_error( $settings ) || ! class_exists( Packages::class ) ) { return BookingSchedule::error( 'Online rental selection is temporarily unavailable.' ); }
		$items = array();
		foreach ( Packages::get_active_packages() as $item ) { $package = BookingSchedule::package( $item['product_id'] ); if ( ! is_wp_error( $package ) ) { $items[] = self::package_view( $package ); } }
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		return array( 'valid' => true, 'packages' => $items, 'min_date' => $today->format( 'Y-m-d' ), 'max_date' => $today->modify( '+' . $settings['booking_horizon'] . ' days' )->format( 'Y-m-d' ), 'timezone' => wp_timezone_string() );
	}
	/** Protected PHP/test diagnostic entry point. Deliberately not a REST route or flag. */
	public static function diagnose_times( $input ) {
		if ( ! Settings::can_manage() ) { return Database::error( 'permission', 'You do not have permission to diagnose rental availability.' ); }
		return self::times( $input, true );
	}
	private static function times( $input, $diagnose = false ) {
		$package = BookingSchedule::package( $input['package_id'] ?? null ); $settings = BookingSchedule::settings();
		if ( is_wp_error( $package ) ) { return $package; }
		if ( is_wp_error( $settings ) ) { return $settings; }
		return Database::locked( static function ( $capacity ) use ( $package, $settings, $input, $diagnose ) {
			$date = $input['date'] ?? null;
			$noon = RentalTime::from_local( is_string( $date ) ? $date . 'T12:00' : null );
			if ( is_wp_error( $noon ) ) { return BookingSchedule::error( 'Please select a valid date.', 'invalid_start_time' ); }
			$day = strtolower( ( new \DateTimeImmutable( $date . ' 12:00', wp_timezone() ) )->format( 'l' ) );
			$hours = $settings['weekly_hours'][ $day ];
			if ( ! $hours['open'] ) { return BookingSchedule::error( 'Please choose an open start day.', 'closed_start_day' ); }
			$minutes = static fn( $value ) => (int) substr( $value, 0, 2 ) * 60 + (int) substr( $value, 3, 2 );
			$times = array(); $schedule_errors = array(); $evaluated = 0; $candidates = array();
			for ( $minute = $minutes( $hours['start'] ); $minute < $minutes( $hours['end'] ); $minute += $settings['time_increment'] ) {
				$time = sprintf( '%02d:%02d', intdiv( $minute, 60 ), $minute % 60 );
				// calculate() also checks the opening-relative increment and both endpoints.
				$schedule = BookingSchedule::calculate( $package, $input['date'] ?? null, $time, $settings, Database::now() );
				if ( is_wp_error( $schedule ) ) {
					$reason = $schedule->get_error_data()['reason'] ?? 'invalid_schedule';
					$schedule_errors[ $reason ] = $schedule;
					if ( $diagnose ) { $candidates[] = array( 'time' => $time, 'reason' => $reason, 'message' => $schedule->get_error_message() ); }
					continue;
				}
				++$evaluated;
				$usage = Availability::evaluate( $capacity, $schedule['occupied_start_utc'], $schedule['occupied_end_utc'] );
				if ( is_wp_error( $usage ) ) { return $usage; }
				if ( $usage['fits'] ) { $times[] = array( 'time' => $time, 'available_quantity' => $usage['available_quantity'] ); }
				if ( $diagnose ) {
					$reason = $usage['fits'] ? 'available' : 'availability';
					if ( ! $usage['fits'] && ( $settings['preparation_buffer'] || $settings['turnaround_buffer'] ) ) {
						$unbuffered = Availability::evaluate( $capacity, $schedule['start_utc'], $schedule['end_utc'] );
						if ( is_wp_error( $unbuffered ) ) { return $unbuffered; }
						if ( $unbuffered['fits'] ) { $reason = 'buffer_conflict'; }
					}
					$candidates[] = array( 'time' => $time, 'reason' => $reason, 'schedule' => $schedule, 'available_quantity' => $usage['available_quantity'] );
				}
			}
			$message = $times ? 'Choose a start time.' : 'No available start times for this date. Choose another date.';
			// Configuration/scheduling failures must not masquerade as sold-out inventory.
			if ( ! $times && ! $evaluated && $schedule_errors ) {
				$failure = $schedule_errors['closed_final_day'] ?? $schedule_errors['pickup_outside_final_hours'] ?? reset( $schedule_errors );
				$message = $failure->get_error_message();
			}
			$result = array( 'valid' => true, 'times' => $times, 'message' => $message );
			if ( $diagnose ) {
				$result['diagnostics'] = array( 'date' => $date, 'duration_type' => $package['duration_type'], 'duration_amount' => $package['duration_amount'], 'pickup_time' => $settings['pickup_time'], 'timezone' => wp_timezone_string(), 'database_utc' => Database::now(), 'candidates' => $candidates );
			}
			return $result;
		} );
	}
	private static function availability( $input ) {
		$booking = BookingSchedule::prepare( $input );
		if ( is_wp_error( $booking ) ) { return $booking; }
		$s = $booking['schedule'];
		$result = Availability::check( $s['occupied_start_utc'], $s['occupied_end_utc'] );
		if ( is_wp_error( $result ) ) { return $result; }
		return array( 'valid' => true, 'rental_start' => $s['local_start'], 'rental_end' => $s['local_end'], 'timezone' => $s['timezone'], 'available_quantity' => max( 0, $result['available_quantity'] ), 'package' => self::package_view( $booking['package'] ), 'message' => $result['fits'] ? sprintf( '%d bikes are available.', $result['available_quantity'] ) : 'No bikes are available for that time.' );
	}
	private static function session() {
		$identity = GuestSession::start();
		return is_wp_error( $identity ) ? $identity : array( 'valid' => true, 'token' => $identity['token'] );
	}
	private static function hold( $input ) {
		$result = Reservations::create_booking_hold( $input, $input['request_key'] ?? null, GuestSession::identity()['hash'] );
		// Read the owned receipt with the database clock, even when PHP's clock differs.
		return is_wp_error( $result ) ? $result : self::status( array( 'request_key' => $input['request_key'] ) );
	}
	private static function status( $input ) {
		global $wpdb;
		$key = $input['request_key'] ?? null;
		if ( ! is_string( $key ) || ! preg_match( '/\A[a-zA-Z0-9_-]{1,64}\z/', $key ) ) { return BookingSchedule::error( 'Please check your temporary reservation.' ); }
		return Database::locked( static function () use ( $key ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_key = %s AND session_hash = %s FOR UPDATE', Database::table( 'reservations' ), strtolower( $key ), GuestSession::identity()['hash'] ), ARRAY_A );
			if ( $wpdb->last_error ) { return Database::retry_error(); }
			return $row ? self::hold_view( $row ) : BookingSchedule::error( 'This temporary reservation is not available in your booking session.' );
		} );
	}
	private static function hold_view( $row ) {
		$s = json_decode( $row['snapshot'], true );
		$now = Database::now() ?? gmdate( 'Y-m-d H:i:s' );
		$live = 'hold' === $row['status'] && $row['hold_expires_at'] > $now;
		return array( 'valid' => true, 'reserved' => $live, 'reference' => $row['reference'], 'package' => self::package_view( $s ), 'quantity' => (int) $row['quantity'], 'rental_start' => $s['local_start'], 'rental_end' => $s['local_end'], 'timezone' => $s['timezone'], 'expires_at' => str_replace( ' ', 'T', $row['hold_expires_at'] ) . 'Z', 'server_time' => str_replace( ' ', 'T', $now ) . 'Z', 'message' => $live ? self::next_step_message() : 'Your temporary reservation has expired or is no longer held. Bikes are not reserved by this form.' );
	}
	public static function next_step_message() { return __( 'Your bikes are temporarily reserved. Checkout integration will be added in the next development milestone.', 'bike-rental-plugin' ); }
	public static function shortcode() {
		$file = dirname( __DIR__ ) . '/bike-rental-plugin.php';
		wp_enqueue_style( 'brp-booking', plugins_url( 'assets/css/booking.css', $file ), array(), Plugin::VERSION );
		wp_enqueue_script( 'brp-booking', plugins_url( 'assets/js/booking.js', $file ), array(), Plugin::VERSION, true );
		ob_start();
		// Also supports shortcodes rendered after the theme has printed its head styles.
		wp_print_styles( 'brp-booking' );
		$uid = wp_unique_id( 'brp-booking-' );
		require __DIR__ . '/booking-form.php';
		return ob_get_clean();
	}
}
