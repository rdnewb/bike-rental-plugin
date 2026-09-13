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
		return Database::locked( static function () use ( $quantity ) {
			global $wpdb;
			if ( ! Database::positive( $quantity ) ) { return Database::error( 'quantity', 'Fleet quantity must be a positive whole number, at most 2147483647.' ); }
			// Individual block bounds only; no overlap or remaining-pool calculation.
			$largest = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(quantity) FROM %i WHERE record_type = %s AND active = %d AND (end_utc IS NULL OR end_utc > %s)', Database::table( 'availability' ), 'block', 1, gmdate( 'Y-m-d H:i:s' ) ) );
			if ( $wpdb->last_error ) { return Database::error( 'database', 'Could not validate existing blocks.' ); }
			if ( (int) $largest > (int) $quantity ) { return Database::error( 'quantity', 'An active current or upcoming block exceeds this fleet quantity. Edit or disable that block first.' ); }
			$result = $wpdb->update( Database::table( 'availability' ), array( 'quantity' => (int) $quantity, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => 1, 'record_type' => 'capacity' ), array( '%d', '%s' ), array( '%d', '%s' ) );
			return false === $result ? Database::error( 'database', 'Could not save fleet quantity.' ) : (int) $quantity;
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
			$data = array_merge( $interval, array( 'record_type' => 'block', 'quantity' => (int) $quantity, 'reason' => $reason, 'active' => (int) $active, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			if ( null === $id ) {
				$data['created_at'] = $data['updated_at'];
				$data['created_by'] = get_current_user_id();
				$result = $wpdb->insert( Database::table( 'availability' ), $data );
				$id = $wpdb->insert_id;
			} else {
				$result = $wpdb->update( Database::table( 'availability' ), $data, array( 'id' => (int) $id, 'record_type' => 'block' ) );
			}
			return false === $result ? Database::error( 'database', 'Could not save the block.' ) : self::block( $id );
		} );
	}

	public static function disable_block( $id ) {
		return Database::locked( static function () use ( $id ) {
			global $wpdb;
			$row = self::block( $id );
			if ( is_wp_error( $row ) ) { return $row; }
			$result = $wpdb->update( Database::table( 'availability' ), array( 'active' => 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $id, 'record_type' => 'block' ) );
			return false === $result ? Database::error( 'database', 'Could not disable the block.' ) : self::block( $id );
		} );
	}
}
