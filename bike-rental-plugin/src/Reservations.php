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

	private static function package( $id ) {
		if ( ! Database::positive( $id ) ) { return Database::error( 'package', 'Select a valid rental package.' ); }
		if ( ! function_exists( 'wc_get_product' ) || ! class_exists( Packages::class ) ) { return Database::error( 'woocommerce', 'Activate WooCommerce to create test reservations. Existing rental data remains available.' ); }
		$package = Packages::get_package( (int) $id );
		return $package && $package['active'] && 'publish' === $package['product_status'] && null !== $package['price'] ? $package : Database::error( 'package', 'Select a published, active rental package with valid duration and regular price.' );
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

	/** Edit schedule/quantity only. Original snapshot and package identity are immutable. */
	public static function update( $id, $input, $expected_revision ) {
		return Database::locked( static function ( $capacity ) use ( $id, $input, $expected_revision ) {
			$row = self::read( $id );
			if ( is_wp_error( $row ) ) { return $row; }
			if ( ! is_array( $input ) ) { return Database::error( 'input', 'Invalid reservation input.' ); }
			if ( ! in_array( $row['status'], array( 'hold', 'confirmed' ), true ) ) { return Database::error( 'status', 'Only hold or confirmed reservations can have their schedule or quantity edited.' ); }
			$snapshot = json_decode( $row['snapshot'], true );
			$buffers = $snapshot['buffers'] ?? null;
			if ( ! is_array( $buffers ) || ! isset( $buffers['preparation_buffer'], $buffers['turnaround_buffer'] ) || ! is_int( $buffers['preparation_buffer'] ) || ! is_int( $buffers['turnaround_buffer'] ) || min( $buffers ) < 0 || max( $buffers ) > 1440 ) { return Database::error( 'snapshot', 'The stored buffer snapshot needs review before schedule edits.' ); }
			$data = self::schedule( $input, $capacity, $buffers );
			return is_wp_error( $data ) ? $data : self::write_revision( $row, $data, $expected_revision );
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

	private static function write_revision( $row, $data, $expected_revision ) {
		global $wpdb;
		if ( ! Database::positive( $expected_revision ) || (int) $expected_revision !== (int) $row['revision'] ) { return Database::error( 'revision', 'The reservation changed. Reload the detail page before saving again.' ); }
		$changed = false;
		foreach ( $data as $key => $value ) { if ( (string) $row[ $key ] !== (string) $value ) { $changed = true; } }
		if ( ! $changed ) { return $row; }
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
