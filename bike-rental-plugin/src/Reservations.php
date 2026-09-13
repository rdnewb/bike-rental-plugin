<?php
/** Administrator-only persistent reservation foundation; no availability promise. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class Reservations {
	const STATUSES = array( 'hold', 'confirmed', 'active', 'completed', 'cancelled', 'expired' );
	const TRANSITIONS = array(
		'hold' => array( 'confirmed', 'cancelled', 'expired' ),
		'confirmed' => array( 'active', 'cancelled' ),
		'active' => array( 'completed' ),
		'completed' => array(), 'cancelled' => array(), 'expired' => array(),
	);

	public static function reference() { return 'BRP-' . gmdate( 'Ymd' ) . '-' . strtoupper( bin2hex( random_bytes( 8 ) ) ); }
	public static function read( $id ) { return Database::read( 'reservations', $id ); }

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
		return Database::locked( static function ( $capacity ) use ( $input ) {
			global $wpdb;
			if ( ! is_array( $input ) ) { return Database::error( 'input', 'Invalid reservation input.' ); }
			$package = self::package( $input['package_product_id'] ?? null );
			if ( is_wp_error( $package ) ) { return $package; }
			$status = $input['status'] ?? 'hold';
			if ( ! in_array( $status, self::STATUSES, true ) ) { return Database::error( 'status', 'Invalid reservation status.' ); }
			$validated = Settings::validate( Settings::get() );
			if ( $validated['errors'] ) { return Database::error( 'settings', 'Repair rental settings before creating test reservations.' ); }
			$settings = $validated['values'];
			$buffers = array_intersect_key( $settings, array_flip( array( 'preparation_buffer', 'turnaround_buffer' ) ) );
			$data = self::schedule( $input, $capacity, $buffers );
			if ( is_wp_error( $data ) ) { return $data; }
			$snapshot = array_intersect_key( $package, array_flip( array( 'product_id', 'name', 'price', 'currency', 'duration_type', 'duration_amount', 'promotional_label' ) ) );
			$snapshot['local_start'] = $input['start'];
			$snapshot['local_end'] = $input['end'];
			$snapshot['timezone'] = $data['timezone'];
			$snapshot['buffers'] = $buffers;
			$snapshot['quantity'] = $data['quantity'];
			$snapshot['status'] = $status;
			$data += array( 'package_product_id' => $package['product_id'], 'status' => $status, 'snapshot' => wp_json_encode( $snapshot ), 'revision' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
			if ( false === $data['snapshot'] ) { return Database::error( 'snapshot', 'Could not encode the package snapshot.' ); }
			// A unique index is the final guard; retry only a verified reference collision.
			for ( $attempt = 0; $attempt < 3; ++$attempt ) {
				$data['reference'] = self::reference();
				if ( false !== $wpdb->insert( Database::table( 'reservations' ), $data ) ) { return self::read( $wpdb->insert_id ); }
				$collision = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE reference = %s', Database::table( 'reservations' ), $data['reference'] ) );
				if ( ! $collision ) { break; }
			}
			return Database::error( 'database', 'Could not create the reservation. Check database health before retrying.' );
		} );
	}

	/** Administrative correction of current state, including any valid status. No overlap checks. */
	public static function update( $id, $input, $expected_revision ) {
		return Database::locked( static function ( $capacity ) use ( $id, $input, $expected_revision ) {
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
			$data = self::schedule( $input, $capacity, $buffers );
			if ( is_wp_error( $data ) ) { return $data; }
			$data['package_product_id'] = (int) $package_id;
			$data['status'] = $status;
			$data['issue_code'] = '' === $issue ? null : $issue;
			if ( $package_changed ) {
				$product = wc_get_product( (int) $package_id );
				$price = $product ? $product->get_price( 'edit' ) : null;
				if ( ! is_string( $price ) || ! preg_match( '/\A[0-9]+(?:\.[0-9]+)?\z/', $price ) ) { return Database::error( 'price', 'The replacement package must have a valid WooCommerce selling price.' ); }
				$snapshot = array_intersect_key( $package, array_flip( array( 'product_id', 'name', 'price', 'currency', 'duration_type', 'duration_amount', 'promotional_label' ) ) );
				$snapshot['price'] = $price;
				$snapshot['buffers'] = $buffers;
			}
			return self::write_revision( $row, $data, $expected_revision, $snapshot );
		} );
	}

	public static function change_status( $id, $status, $expected_revision ) {
		$gate = Database::gate();
		if ( is_wp_error( $gate ) ) { return $gate; }
		$row = self::read( $id );
		if ( is_wp_error( $row ) ) { return $row; }
		if ( ! in_array( $status, self::STATUSES, true ) || ! isset( self::TRANSITIONS[ $row['status'] ] ) ) { return Database::error( 'status', 'Invalid reservation status.' ); }
		if ( $status !== $row['status'] && ! in_array( $status, self::TRANSITIONS[ $row['status'] ], true ) ) { return Database::error( 'transition', 'This status transition is not allowed. Cancelled, expired, and completed reservations are retained as terminal records.' ); }
		return self::write_revision( $row, array( 'status' => $status ), $expected_revision );
	}

	private static function write_revision( $row, $data, $expected_revision, $snapshot = null ) {
		global $wpdb;
		if ( ! Database::positive( $expected_revision ) || (int) $expected_revision !== (int) $row['revision'] ) { return Database::error( 'revision', 'The reservation was changed elsewhere. Reload the detail page before saving again.' ); }
		$changed = false;
		foreach ( $data as $key => $value ) { if ( (string) $row[ $key ] !== (string) $value ) { $changed = true; } }
		if ( ! $changed ) { return $row; }
		// Refresh only the current agreed state. No client-supplied JSON or order writes.
		$snapshot = $snapshot ?? json_decode( $row['snapshot'], true );
		if ( ! is_array( $snapshot ) ) { return Database::error( 'snapshot', 'The stored package snapshot needs review before editing.' ); }
		$state = array_replace( $row, $data );
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
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$result = $wpdb->update( Database::table( 'reservations' ), $data, array( 'id' => (int) $row['id'], 'revision' => (int) $expected_revision ) );
		if ( false === $result ) { return Database::error( 'database', 'Could not update the reservation.' ); }
		if ( 1 !== $result ) { return Database::error( 'revision', 'The reservation changed during saving. Reload before retrying.' ); }
		return self::read( $row['id'] );
	}

	public static function cancel( $id, $revision ) { return self::change_status( $id, 'cancelled', $revision ); }
	public static function mark_active( $id, $revision ) { return self::change_status( $id, 'active', $revision ); }
	public static function mark_completed( $id, $revision ) { return self::change_status( $id, 'completed', $revision ); }
}
