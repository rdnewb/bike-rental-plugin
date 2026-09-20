<?php
/** Authoritative shared-pool sweep. Every caller uses the same capacity-row lock. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class Availability {
	const FOREVER = '9999-12-31 23:59:59';

	/** Use the same locked database clock for reads and proposed allocations. */
	public static function reservation_end( $row ) {
		return 'active' === $row['status'] && $row['occupied_end_utc'] < Database::now() ? self::FOREVER : $row['occupied_end_utc'];
	}

	public static function valid_utc( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) || (int) substr( $value, 0, 4 ) < 1000 ) { return false; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	/** A locked read is a point-in-time answer, not an allocation promise. */
	public static function check( $start, $end, $quantity = 1, $reservation_id = null, $block_id = null ) {
		return Database::locked( static function ( $capacity ) use ( $start, $end, $quantity, $reservation_id, $block_id ) {
			return self::evaluate( $capacity, $start, $end, $quantity, $reservation_id, $block_id );
		} );
	}

	/** Internal: caller already owns the capacity row. NULL end means indefinite. */
	public static function evaluate( $capacity, $start, $end, $quantity = 1, $reservation_id = null, $block_id = null ) {
		global $wpdb;
		if ( ! Database::in_transaction() ) { return Database::error( 'transaction', 'Availability validation requires the inventory lock.' ); }
		$end = null === $end ? self::FOREVER : $end;
		if ( ! self::valid_utc( $start ) || ! self::valid_utc( $end ) || $end <= $start ) { return Database::error( 'interval', 'Enter a valid UTC interval with end after start.' ); }
		if ( ! Database::positive( $quantity ) || ( null !== $reservation_id && ! Database::positive( $reservation_id ) ) || ( null !== $block_id && ! Database::positive( $block_id ) ) ) { return Database::error( 'quantity', 'Invalid quantity or exclusion ID.' ); }
		$reservations = $wpdb->get_results( $wpdb->prepare(
			'SELECT id,quantity,occupied_start_utc,occupied_end_utc,status FROM %i WHERE id <> %d AND occupied_start_utc < %s AND ((status = %s AND occupied_end_utc < %s) OR occupied_end_utc > %s) AND (status IN (%s,%s) OR (status = %s AND hold_expires_at > %s)) FOR UPDATE',
			Database::table( 'reservations' ), $reservation_id ?? 0, $end, 'active', Database::now(), $start, 'confirmed', 'active', 'hold', Database::now()
		), ARRAY_A );
		if ( ! is_array( $reservations ) || $wpdb->last_error ) { return Database::retry_error(); }
		$blocks = $wpdb->get_results( $wpdb->prepare(
			'SELECT id,quantity,start_utc,end_utc FROM %i WHERE record_type = %s AND active = %d AND id <> %d AND start_utc < %s AND (end_utc IS NULL OR end_utc > %s) FOR UPDATE',
			Database::table( 'availability' ), 'block', 1, $block_id ?? 0, $end, $start
		), ARRAY_A );
		if ( ! is_array( $blocks ) || $wpdb->last_error ) { return Database::retry_error(); }
		$intervals = array();
		foreach ( $reservations as $row ) { $intervals[] = array( $row['occupied_start_utc'], self::reservation_end( $row ), $row['quantity'] ); }
		foreach ( $blocks as $row ) { $intervals[] = array( $row['start_utc'], $row['end_utc'] ?? self::FOREVER, $row['quantity'] ); }
		$events = array();
		foreach ( $intervals as $interval ) {
			if ( ! self::valid_utc( $interval[0] ) || ! self::valid_utc( $interval[1] ) || $interval[1] <= $interval[0] || ! Database::positive( $interval[2] ) ) { return Database::error( 'inventory', 'An inventory record is invalid. Repair it before allocating bikes.' ); }
			$events[] = array( max( $start, $interval[0] ), (int) $interval[2] );
			$events[] = array( min( $end, $interval[1] ), -(int) $interval[2] );
		}
		// Negative deltas (ends) sort before positive deltas at equal timestamps.
		usort( $events, static fn( $a, $b ) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] );
		$used = 0; $peak = 0;
		foreach ( $events as $event ) { $used += $event[1]; $peak = max( $peak, $used ); }
		$available = (int) $capacity - $peak;
		return array( 'total_capacity' => (int) $capacity, 'peak_existing_usage' => $peak, 'available_quantity' => $available, 'requested_quantity' => (int) $quantity, 'fits' => (int) $quantity <= $available );
	}

	public static function allocation( $capacity, $row, $exclude = null ) {
		if ( ! in_array( $row['status'], array( 'confirmed', 'active' ), true ) && ! ( 'hold' === $row['status'] && ( $row['hold_expires_at'] ?? '' ) > Database::now() ) ) { return true; }
		$result = self::evaluate( $capacity, $row['occupied_start_utc'], self::reservation_end( $row ), $row['quantity'], $exclude );
		return is_wp_error( $result ) ? $result : ( $result['fits'] ? true : self::conflict( $result ) );
	}

	public static function conflict( $result, $message = '' ) {
		$message = $message ?: sprintf( 'Only %d bikes are available for the selected time.', max( 0, $result['available_quantity'] ) );
		return new \WP_Error( 'brp_conflict', $message, $result );
	}
}
