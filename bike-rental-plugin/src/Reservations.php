<?php
/** Shared reservation allocation and hold services. No checkout or gateway integration. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class Reservations {
	const HOLD_MINUTES = 15;
	const STATUSES = array( 'hold', 'confirmed', 'active', 'completed', 'cancelled', 'expired', 'pending_waivers' );
	const TRANSITIONS = array(
		'hold' => array( 'pending_waivers', 'confirmed', 'cancelled', 'expired' ),
		'pending_waivers' => array( 'confirmed', 'cancelled' ),
		'confirmed' => array( 'active', 'cancelled' ),
		'active' => array( 'completed' ),
		'completed' => array(), 'cancelled' => array(), 'expired' => array(),
	);

	public static function reference() { return 'BRP-' . gmdate( 'Ymd' ) . '-' . strtoupper( bin2hex( random_bytes( 8 ) ) ); }
	public static function read( $id ) { return Database::read( 'reservations', $id ); }

	/** Validate the proposed schedule under the lock, including administrative corrections. */
	private static function validate_status_time( $state, $previous = null ) {
		$waiver_guard = Waivers::guard_state( $state, $previous ); if ( is_wp_error( $waiver_guard ) ) { return $waiver_guard; }
		if ( 'active' === $state['status'] && $state['start_utc'] > Database::now() ) {
			return Database::error( 'active_start', 'This reservation cannot be marked Active before its scheduled start time.' );
		}
		if ( 'completed' === $state['status'] ) {
			// Active -> completed records staff confirmation of return, including early return.
			$returned = $previous && 'active' === $previous['status'];
			$retained_return = $previous && 'completed' === $previous['status'] && $state['start_utc'] === $previous['start_utc'] && $state['end_utc'] === $previous['end_utc'];
			if ( $state['start_utc'] > Database::now() || ( $state['end_utc'] > Database::now() && ! $returned && ! $retained_return ) ) {
				return Database::error( 'completed_time', 'This reservation cannot be marked Completed before it has ended or an Active rental has been returned.' );
			}
		}
		return true;
	}

	private static function package( $id, $require_active = true ) {
		if ( ! Database::positive( $id ) ) { return Database::error( 'package', 'Select a valid rental package.' ); }
		if ( ! function_exists( 'wc_get_product' ) || ! class_exists( Packages::class ) ) { return Database::error( 'woocommerce', 'Activate WooCommerce to create or edit reservation packages. Existing rental data remains available.' ); }
		$package = Packages::get_package( (int) $id );
		return $package && ( ! $require_active || ( $package['active'] && 'publish' === $package['product_status'] && null !== $package['price'] ) ) ? $package : Database::error( 'package', 'Select a valid rental package. A replacement must be published, active, and have a valid duration and price.' );
	}

	private static function schedule( $input, $capacity, $buffers ) {
		$quantity = $input['quantity'] ?? null;
		if ( ! Database::positive( $quantity ) || (int) $quantity > $capacity ) { return Database::error( 'quantity', 'Reservation quantity must be at least 1 and no greater than total fleet.' ); }
		$interval = RentalTime::interval( $input['start'] ?? null, $input['end'] ?? null );
		if ( is_wp_error( $interval ) ) { return $interval; }
		return array_merge( $interval, array(
			'quantity' => (int) $quantity,
			'occupied_start_utc' => RentalTime::shift( $interval['start_utc'], -$buffers['preparation_buffer'] ),
			'occupied_end_utc' => RentalTime::shift( $interval['end_utc'], $buffers['turnaround_buffer'] ),
			'timezone' => wp_timezone()->getName(),
		) );
	}

	public static function create( $input ) {
		if ( is_array( $input ) && 'hold' === ( $input['status'] ?? 'hold' ) ) {
			return self::create_hold( $input, $input['request_key'] ?? wp_generate_uuid4(), hash( 'sha256', 'admin:' . get_current_user_id() ) );
		}
		if ( is_array( $input ) && isset( $input['request_key'] ) ) { return self::create_request( $input, $input['request_key'], hash( 'sha256', 'admin:' . get_current_user_id() ) ); }
		return self::create_record( $input );
	}

	/** Server-derived intent hash; only hashes, never raw session identifiers, are stored. */
	public static function create_hold( $input, $request_key, $session_hash ) {
		if ( is_array( $input ) ) { $input['status'] = 'hold'; }
		return self::create_request( $input, $request_key, $session_hash );
	}

	/** Called only by the protected public controller, never with browser-supplied endpoints/prices. */
	public static function create_booking_hold( $input, $request_key, $session_hash ) {
		$booking = BookingSchedule::prepare( $input );
		if ( is_wp_error( $booking ) ) { return $booking; }
		return self::create_request( $booking['input'], $request_key, $session_hash, $booking );
	}

	private static function create_request( $input, $request_key, $session_hash, $booking = null ) {
		$gate = Database::gate();
		if ( is_wp_error( $gate ) ) { return $gate; }
		if ( ! is_string( $request_key ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $request_key ) || ! is_string( $session_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $session_hash ) || ! is_array( $input ) || ! Database::positive( $input['package_product_id'] ?? null ) || ! Database::positive( $input['quantity'] ?? null ) ) { return Database::error( 'request', 'Invalid hold request key, session hash, package, or quantity.' ); }
		$interval = RentalTime::interval( $input['start'] ?? null, $input['end'] ?? null );
		if ( is_wp_error( $interval ) ) { return $interval; }
		if ( ! in_array( $input['status'] ?? null, self::STATUSES, true ) ) { return Database::error( 'status', 'Invalid reservation status.' ); }
		$identity = array( 'request_key' => strtolower( $request_key ), 'session_hash' => $session_hash, 'request_hash' => hash( 'sha256', wp_json_encode( array( (int) $input['package_product_id'], (int) $input['quantity'], $interval, wp_timezone_string(), $input['status'] ) ) ) );
		$existing = Database::locked( static fn() => self::existing_request( $identity ) );
		if ( null !== $existing ) { return $existing; }
		return self::create_record( $input, $identity, $booking );
	}

	private static function existing_request( $identity ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_key = %s FOR UPDATE', Database::table( 'reservations' ), $identity['request_key'] ), ARRAY_A );
		if ( $wpdb->last_error ) { return Database::retry_error(); }
		if ( ! $row ) { return null; }
		if ( ! hash_equals( (string) $row['request_hash'], $identity['request_hash'] ) || ! hash_equals( (string) $row['session_hash'], $identity['session_hash'] ) ) { return Database::error( 'idempotency', 'This request key already belongs to different rental details or a different session. Use a new key for a new request.' ); }
		return $row;
	}

	private static function create_record( $input, $identity = null, $booking = null ) {
		$gate = Database::gate();
		if ( is_wp_error( $gate ) ) { return $gate; }
		if ( ! is_array( $input ) ) { return Database::error( 'input', 'Invalid reservation input.' ); }
		$package = $booking ? $booking['package'] : self::package( $input['package_product_id'] ?? null );
		if ( is_wp_error( $package ) ) { return $package; }
		$status = $input['status'] ?? 'hold';
		if ( ! in_array( $status, self::STATUSES, true ) ) { return Database::error( 'status', 'Invalid reservation status.' ); }
		$validated = Settings::validate( Settings::get() );
		if ( $validated['errors'] ) { return Database::error( 'settings', 'Repair rental settings before creating test reservations.' ); }
		$settings = $validated['values'];
		$buffers = array_intersect_key( $settings, array_flip( array( 'preparation_buffer', 'turnaround_buffer' ) ) );
		$data = self::schedule( $input, 2147483647, $buffers );
		if ( is_wp_error( $data ) ) { return $data; }
		$snapshot = array_intersect_key( $package, array_flip( array( 'product_id', 'name', 'price', 'currency', 'duration_type', 'duration_amount', 'promotional_label' ) ) );
		$snapshot['local_start'] = $input['start'];
		$snapshot['local_end'] = $input['end'];
		$snapshot['timezone'] = $data['timezone'];
		$snapshot['buffers'] = $buffers;
		$snapshot['quantity'] = $data['quantity'];
		$snapshot['status'] = $status;
		$snapshot['waiver_policy'] = WaiverSettings::get();
		$data += array( 'package_product_id' => $package['product_id'], 'status' => $status, 'snapshot' => wp_json_encode( $snapshot ), 'revision' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		if ( false === $data['snapshot'] ) { return Database::error( 'snapshot', 'Could not encode the package snapshot.' ); }
		return Database::locked( static function ( $capacity ) use ( $data, $identity, $booking ) {
			global $wpdb;
			if ( $identity ) {
				$existing = self::existing_request( $identity );
				if ( null !== $existing ) { return $existing; }
				$data = array_merge( $data, $identity );
				if ( 'hold' === $data['status'] ) { $data['hold_expires_at'] = RentalTime::shift( Database::now(), self::HOLD_MINUTES ); }
			}
			if ( $booking ) {
				$check = BookingSchedule::calculate( $booking['package'], substr( $booking['input']['start'], 0, 10 ), substr( $booking['input']['start'], 11 ), $booking['settings'], Database::now() );
				if ( is_wp_error( $check ) ) { return $check; }
				$other = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE session_hash = %s AND status = %s AND hold_expires_at > %s LIMIT 1 FOR UPDATE', Database::table( 'reservations' ), $identity['session_hash'], 'hold', Database::now() ) );
				if ( $wpdb->last_error ) { return Database::retry_error(); }
				if ( $other ) { return Database::error( 'existing_hold', 'You already have a temporary reservation. Wait for it to expire before starting another.' ); }
			}
			if ( $data['quantity'] > $capacity ) { return Database::error( 'quantity', 'Reservation quantity must be at least 1 and no greater than total fleet.' ); }
			$status_time = self::validate_status_time( $data );
			if ( is_wp_error( $status_time ) ) { return $status_time; }
			$available = Availability::allocation( $capacity, $data );
			if ( is_wp_error( $available ) ) { return $available; }
			$data['created_at'] = Database::now(); $data['updated_at'] = Database::now();
			// Never retry a failed statement inside a possibly deadlock-aborted transaction.
			$data['reference'] = self::reference();
			if ( false === Database::insert( 'reservations', $data ) ) { return Database::retry_error(); }
			$row = self::read( $wpdb->insert_id ); if ( is_wp_error( $row ) ) { return $row; }
			$roster = Waivers::reconcile_locked( $row ); return is_wp_error( $roster ) ? $roster : $row;
		} );
	}

	/** Prepare product data outside the transaction; replace only under the shared lock. */
	public static function update( $id, $input, $expected_revision ) {
		$row = self::read( $id );
		if ( is_wp_error( $row ) ) { return $row; }
		if ( ! Database::positive( $expected_revision ) || (int) $expected_revision !== (int) $row['revision'] ) { return Database::error( 'revision', 'The reservation was changed elsewhere. Reload the detail page before saving again.' ); }
		if ( ! is_array( $input ) ) { return Database::error( 'input', 'Invalid reservation input.' ); }
		$package_id = array_key_exists( 'package_product_id', $input ) ? $input['package_product_id'] : $row['package_product_id'];
		if ( ! Database::positive( $package_id ) ) { return Database::error( 'package', 'Select a valid rental package.' ); }
		$package_changed = (string) $package_id !== (string) $row['package_product_id'];
		$package = self::package( $package_id, $package_changed );
		if ( is_wp_error( $package ) ) { return $package; }
		$status = array_key_exists( 'status', $input ) ? $input['status'] : $row['status'];
		if ( ! in_array( $status, self::STATUSES, true ) ) { return Database::error( 'status', 'Invalid reservation status.' ); }
		$issue = array_key_exists( 'issue_code', $input ) ? $input['issue_code'] : ( $row['issue_code'] ?? '' );
		if ( ! is_string( $issue ) || strlen( $issue ) > 64 ) { return Database::error( 'issue', 'Issue code must be plain text, at most 64 UTF-8 bytes.' ); }
		$issue = sanitize_text_field( $issue );
		$snapshot = json_decode( $row['snapshot'], true );
		$buffers = $snapshot['buffers'] ?? null;
		if ( ! is_array( $buffers ) || ! isset( $buffers['preparation_buffer'], $buffers['turnaround_buffer'] ) || ! is_int( $buffers['preparation_buffer'] ) || ! is_int( $buffers['turnaround_buffer'] ) || min( $buffers ) < 0 || max( $buffers ) > 1440 ) { return Database::error( 'snapshot', 'The stored buffer snapshot needs review before schedule edits.' ); }
		$data = self::schedule( $input, 2147483647, $buffers );
		if ( is_wp_error( $data ) ) { return $data; }
		$data['package_product_id'] = (int) $package_id;
		$data['status'] = $status;
		$data['issue_code'] = '' === $issue ? null : $issue;
		if ( $package_changed ) {
			$product = wc_get_product( (int) $package_id );
			$price = $product ? $product->get_price( 'edit' ) : null;
			if ( ! is_string( $price ) || ! preg_match( '/\A[0-9]+(?:\.[0-9]+)?\z/', $price ) ) { return Database::error( 'price', 'The replacement package must have a valid WooCommerce selling price.' ); }
			$payment_mode = $snapshot['payment_mode'] ?? null; $waiver_policy = $snapshot['waiver_policy'] ?? null;
			$snapshot = array_intersect_key( $package, array_flip( array( 'product_id', 'name', 'price', 'currency', 'duration_type', 'duration_amount', 'promotional_label' ) ) );
			if ( null !== $payment_mode ) { $snapshot['payment_mode'] = $payment_mode; }
			if ( null !== $waiver_policy ) { $snapshot['waiver_policy'] = $waiver_policy; }
			$snapshot['price'] = $price;
			$snapshot['buffers'] = $buffers;
		}
		return Database::locked( static function ( $capacity ) use ( $id, $data, $expected_revision, $snapshot ) {
			$row = self::read( $id );
			if ( is_wp_error( $row ) ) { return $row; }
			if ( $data['quantity'] > $capacity ) { return Database::error( 'quantity', 'Reservation quantity must be at least 1 and no greater than total fleet.' ); }
			return self::write_revision( $row, $data, $expected_revision, $snapshot, $capacity );
		} );
	}

	public static function change_status( $id, $status, $expected_revision ) {
		return Database::locked( static function ( $capacity ) use ( $id, $status, $expected_revision ) {
		$row = self::read( $id );
		if ( is_wp_error( $row ) ) { return $row; }
		if ( ! in_array( $status, self::STATUSES, true ) || ! isset( self::TRANSITIONS[ $row['status'] ] ) ) { return Database::error( 'status', 'Invalid reservation status.' ); }
		if ( $status !== $row['status'] && ! in_array( $status, self::TRANSITIONS[ $row['status'] ], true ) ) { return Database::error( 'transition', 'This status transition is not allowed. Cancelled, expired, and completed reservations are retained as terminal records.' ); }
		return self::write_revision( $row, array( 'status' => $status ), $expected_revision, null, $capacity );
		} );
	}

	private static function write_revision( $row, $data, $expected_revision, $snapshot, $capacity ) {
		global $wpdb;
		if ( ! Database::positive( $expected_revision ) || (int) $expected_revision !== (int) $row['revision'] ) { return Database::error( 'revision', 'The reservation was changed elsewhere. Reload the detail page before saving again.' ); }
		$state = array_replace( $row, $data );
		$roster = Waivers::reconcile_locked( $row, $state['quantity'] ); if ( is_wp_error( $roster ) ) { return $roster; }
		$status_time = self::validate_status_time( $state, $row );
		if ( is_wp_error( $status_time ) ) { return $status_time; }
		$changed = false;
		foreach ( $data as $key => $value ) { if ( (string) $row[ $key ] !== (string) $value ) { $changed = true; } }
		if ( ! $changed ) { return $row; }
		// Refresh only the current agreed state. No client-supplied JSON or order writes.
		$snapshot = $snapshot ?? json_decode( $row['snapshot'], true );
		if ( ! is_array( $snapshot ) ) { return Database::error( 'snapshot', 'The stored package snapshot needs review before editing.' ); }
		if ( $state['quantity'] > $capacity && ! in_array( $state['status'], array( 'cancelled', 'expired', 'completed' ), true ) ) { return Database::error( 'quantity', 'Reservation quantity exceeds total fleet.' ); }
		if ( 'hold' === $state['status'] && 'hold' !== $row['status'] ) {
			$data['hold_expires_at'] = RentalTime::shift( Database::now(), self::HOLD_MINUTES );
			// Manual re-holds also have stable identifiers; existing keys retain original intent.
			if ( empty( $row['request_key'] ) ) {
				$data['request_key'] = bin2hex( random_bytes( 16 ) );
				$data['request_hash'] = hash( 'sha256', wp_json_encode( array( $state['package_product_id'], $state['quantity'], $state['start_utc'], $state['end_utc'] ) ) );
				$data['session_hash'] = hash( 'sha256', 'admin:' . get_current_user_id() );
			}
			$state = array_replace( $state, $data );
		}
		$available = Availability::allocation( $capacity, $state, $row['id'] );
		if ( is_wp_error( $available ) ) { return $available; }
		if ( 'active' === $row['status'] && 'completed' === $state['status'] ) {
			$minutes = $snapshot['buffers']['turnaround_buffer'] ?? null;
			if ( ! is_int( $minutes ) || $minutes < 0 || $minutes > 1440 ) { return Database::error( 'snapshot', 'Repair the turnaround buffer before completing this rental.' ); }
			if ( $minutes > 0 ) {
				$end = RentalTime::shift( Database::now(), $minutes );
				$fit = Availability::evaluate( $capacity, Database::now(), $end, $row['quantity'], $row['id'] );
				if ( is_wp_error( $fit ) ) { return $fit; }
				if ( ! $fit['fits'] ) { return Availability::conflict( $fit, 'Returning this rental requires turnaround capacity. Resolve the conflicting commitments before completing it.' ); }
				if ( false === Database::insert( 'availability', array( 'record_type' => 'block', 'quantity' => $row['quantity'], 'start_utc' => Database::now(), 'end_utc' => $end, 'reason' => 'Return turnaround: ' . $row['reference'], 'active' => 1, 'created_by' => get_current_user_id(), 'created_at' => Database::now(), 'updated_at' => Database::now() ) ) ) { return Database::retry_error(); }
			}
		}
		try {
			$zone = new \DateTimeZone( $state['timezone'] );
			foreach ( array( 'start', 'end' ) as $part ) {
				$snapshot[ 'local_' . $part ] = ( new \DateTimeImmutable( $state[ $part . '_utc' ], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d\TH:i' );
			}
		} catch ( \Exception $error ) { return Database::error( 'snapshot', 'The stored schedule timezone needs review before editing.' ); }
		$snapshot['timezone'] = $state['timezone'];
		$snapshot['quantity'] = (int) $state['quantity'];
		$snapshot['status'] = $state['status'];
		$data['snapshot'] = wp_json_encode( $snapshot );
		if ( false === $data['snapshot'] ) { return Database::error( 'snapshot', 'Could not encode the current package snapshot.' ); }
		$data['revision'] = (int) $row['revision'] + 1;
		$data['updated_at'] = Database::now();
		$result = Database::update( 'reservations', $data, array( 'id' => (int) $row['id'], 'revision' => (int) $expected_revision ) );
		if ( false === $result ) { return Database::retry_error(); }
		if ( 1 !== $result ) { return Database::error( 'revision', 'The reservation changed during saving. Reload before retrying.' ); }
		return self::read( $row['id'] );
	}

	public static function cancel( $id, $revision ) { return self::change_status( $id, 'cancelled', $revision ); }
	public static function mark_active( $id, $revision ) { return self::change_status( $id, 'active', $revision ); }
	public static function mark_completed( $id, $revision ) { return self::change_status( $id, 'completed', $revision ); }
	public static function confirm_hold( $id, $revision ) {
		return Database::locked( static function ( $capacity ) use ( $id, $revision ) {
			$row = self::read( $id );
			if ( is_wp_error( $row ) ) { return $row; }
			if ( ! in_array( $row['status'], array( 'hold', 'expired', 'confirmed' ), true ) ) { return Database::error( 'hold', 'Only a hold or its expired result can be confirmed by this method.' ); }
			return self::write_revision( $row, array( 'status' => 'confirmed' ), $revision, null, $capacity );
		} );
	}

	/** Housekeeping, in bounded batches; null legacy expiry remains unallocated, not renewed. */
	public static function expire_holds() {
		return Database::locked( static function () {
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s AND hold_expires_at <= %s ORDER BY id LIMIT 100 FOR UPDATE', Database::table( 'reservations' ), 'hold', Database::now() ), ARRAY_A );
			if ( ! is_array( $rows ) || $wpdb->last_error ) { return Database::retry_error(); }
			foreach ( $rows as $row ) {
				$snapshot = json_decode( $row['snapshot'], true );
				$data = array( 'status' => 'expired', 'revision' => (int) $row['revision'] + 1, 'updated_at' => Database::now() );
				if ( is_array( $snapshot ) ) { $snapshot['status'] = 'expired'; $data['snapshot'] = wp_json_encode( $snapshot ); }
				if ( 1 !== Database::update( 'reservations', $data, array( 'id' => $row['id'], 'revision' => $row['revision'] ) ) ) { return Database::retry_error(); }
			}
			return count( $rows );
		}, true );
	}
}
