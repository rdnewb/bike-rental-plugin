<?php
/** Shared fleet capacity and quantity-based unavailability records. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class Fleet {
	public static function capacity() {
		$row = Database::read( 'availability', 1 );
		if ( is_wp_error( $row ) ) { return $row; }
		return 'capacity' === $row['record_type'] && Database::positive( $row['quantity'] ) && 1 === (int) $row['active'] ? (int) $row['quantity'] : Database::error( 'capacity', 'Invalid fleet capacity record.' );
	}

	public static function set_capacity( $quantity ) {
		return Database::locked( static function ( $capacity ) use ( $quantity ) {
			global $wpdb;
			if ( ! Database::positive( $quantity ) ) { return Database::error( 'quantity', 'Fleet quantity must be a positive whole number, at most 2147483647.' ); }
			if ( (int) $quantity < $capacity ) {
				$usage = Availability::evaluate( (int) $quantity, Database::now(), null );
				if ( is_wp_error( $usage ) ) { return $usage; }
				if ( $usage['peak_existing_usage'] > (int) $quantity ) { return Availability::conflict( $usage, 'This fleet reduction would conflict with existing reservations or blocks.' ); }
			}
			$result = Database::update( 'availability', array( 'quantity' => (int) $quantity, 'updated_at' => Database::now() ), array( 'id' => 1, 'record_type' => 'capacity' ) );
			return false === $result ? Database::retry_error() : (int) $quantity;
		} );
	}

	public static function block( $id ) {
		$row = Database::read( 'availability', $id );
		if ( is_wp_error( $row ) ) { return $row; }
		return 'block' === $row['record_type'] ? $row : Database::error( 'block', 'This record is not an unavailability block.' );
	}

	public static function save_block( $input, $id = null ) {
		return Database::locked( static function ( $capacity ) use ( $input, $id ) {
			global $wpdb;
			if ( ! is_array( $input ) ) { return Database::error( 'input', 'Invalid block input.' ); }
			if ( null !== $id ) {
				$old = self::block( $id );
				if ( is_wp_error( $old ) ) { return $old; }
			}
			$quantity = $input['quantity'] ?? null;
			if ( ! Database::positive( $quantity ) || (int) $quantity > $capacity ) { return Database::error( 'quantity', 'Block quantity must be at least 1 and no greater than total fleet.' ); }
			$interval = RentalTime::interval( $input['start'] ?? null, $input['end'] ?? '', true );
			if ( is_wp_error( $interval ) ) { return $interval; }
			$reason = $input['reason'] ?? null;
			if ( ! is_string( $reason ) ) { return Database::error( 'reason', 'Enter a block reason.' ); }
			$reason = sanitize_text_field( $reason );
			if ( '' === $reason || strlen( $reason ) > 240 ) { return Database::error( 'reason', 'A block reason is required (maximum 240 UTF-8 bytes).' ); }
			$active = $input['active'] ?? '1';
			if ( ! in_array( $active, array( 0, 1, '0', '1' ), true ) ) { return Database::error( 'active', 'Invalid block active flag.' ); }
			$data = array_merge( $interval, array( 'record_type' => 'block', 'quantity' => (int) $quantity, 'reason' => $reason, 'active' => (int) $active, 'updated_at' => Database::now() ) );
			if ( $data['active'] ) {
				$availability = Availability::evaluate( $capacity, $data['start_utc'], $data['end_utc'], $data['quantity'], null, $id );
				if ( is_wp_error( $availability ) ) { return $availability; }
				if ( ! $availability['fits'] ) { return Availability::conflict( $availability, 'This maintenance block would exceed available fleet capacity.' ); }
			}
			if ( null === $id ) {
				$data['created_at'] = $data['updated_at'];
				$data['created_by'] = get_current_user_id();
				$result = Database::insert( 'availability', $data );
				$id = $wpdb->insert_id;
			} else {
				$result = Database::update( 'availability', $data, array( 'id' => (int) $id, 'record_type' => 'block' ) );
			}
			return false === $result ? Database::retry_error() : self::block( $id );
		} );
	}

	public static function disable_block( $id ) {
		return Database::locked( static function () use ( $id ) {
			global $wpdb;
			$row = self::block( $id );
			if ( is_wp_error( $row ) ) { return $row; }
			$result = Database::update( 'availability', array( 'active' => 0, 'updated_at' => Database::now() ), array( 'id' => (int) $id, 'record_type' => 'block' ) );
			return false === $result ? Database::retry_error() : self::block( $id );
		} );
	}
}
