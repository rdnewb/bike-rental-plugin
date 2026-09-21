<?php
/** Serialized checkout inventory writes. No WooCommerce writes or network inside transactions. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class CheckoutReservation {
	public static function error() { return Database::error( 'checkout', 'This rental hold is no longer valid for checkout. Return to the rental booking form to check availability.' ); }
	public static function fingerprint( $row ) {
		$s = json_decode( $row['snapshot'], true );
		if ( ! is_array( $s ) ) { return ''; }
		unset( $s['status'] );
		return hash( 'sha256', wp_json_encode( array( $row['reference'], (int) $row['package_product_id'], (int) $row['quantity'], $row['start_utc'], $row['end_utc'], $row['occupied_start_utc'], $row['occupied_end_utc'], $row['timezone'], $s ) ) );
	}
	/** Internal trusted caller passes the authenticated guest hash. */
	public static function owned( $id, $owner, $product = null, $quantity = null, $fingerprint = null ) {
		return Database::public_booking( static fn() => Database::locked( static function ( $capacity ) use ( $id, $owner, $product, $quantity, $fingerprint ) {
			$row = Reservations::read( $id );
			if ( is_wp_error( $row ) ) { return self::error(); }
			$result = self::validate( $row, $owner, $capacity );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( ( null !== $product && (string) $product !== (string) $row['package_product_id'] ) || ( null !== $quantity && (string) $quantity !== (string) $row['quantity'] ) || ( null !== $fingerprint && ( ! is_string( $fingerprint ) || ! hash_equals( self::fingerprint( $row ), $fingerprint ) ) ) ) { return self::error(); }
			return $row;
		} ) );
	}
	private static function validate( $row, $owner, $capacity ) {
		$roster = Waivers::roster_ready( $row ); if ( is_wp_error( $roster ) ) { return Database::error( 'checkout', 'Complete one valid rider record per bike before checkout. Return to the booking form or contact the shop.' ); }
		$waivers = WaiverSettings::ready( Waivers::policy( $row ) ); if ( is_wp_error( $waivers ) ) { return Database::error( 'checkout', 'Online rental checkout is unavailable while rider waiver setup needs attention. Please contact the shop.' ); }
		if ( ! is_string( $owner ) || strlen( $owner ) !== 64 || ! hash_equals( (string) $row['session_hash'], $owner ) || 'hold' !== $row['status'] || empty( $row['hold_expires_at'] ) || $row['hold_expires_at'] <= Database::now() ) { return self::error(); }
		$s = json_decode( $row['snapshot'], true ); $p = Packages::get_package( $row['package_product_id'] );
		$product = wc_get_product( $row['package_product_id'] );
		if ( ! $p || ! $p['active'] || 'publish' !== $p['product_status'] || ! is_array( $s ) || (int) ( $s['product_id'] ?? 0 ) !== (int) $row['package_product_id'] || (int) ( $s['quantity'] ?? 0 ) !== (int) $row['quantity'] || ! is_numeric( $s['price'] ?? null ) || $s['price'] <= 0 || (string) $s['price'] !== (string) $product->get_price() || ( $s['currency'] ?? '' ) !== get_woocommerce_currency() || ! in_array( $s['duration_type'] ?? '', array( 'hours', 'calendar_days' ), true ) || ! Database::positive( $s['duration_amount'] ?? null ) ) { return self::error(); }
		try {
			$zone = new \DateTimeZone( $row['timezone'] );
			foreach ( array( 'start', 'end' ) as $part ) {
				$local = ( new \DateTimeImmutable( $row[ $part . '_utc' ], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d\TH:i' );
				if ( $local !== ( $s[ 'local_' . $part ] ?? '' ) ) { return self::error(); }
			}
		} catch ( \Exception $e ) { return self::error(); }
		$fit = Availability::allocation( $capacity, $row, $row['id'] );
		return is_wp_error( $fit ) ? $fit : $row;
	}
	/** Snapshot payment mode once at transfer; subsequent global changes do not rewrite it. */
	public static function prepare( $id, $owner ) {
		return Database::public_booking( static fn() => Database::locked( static function ( $capacity ) use ( $id, $owner ) {
			$row = Reservations::read( $id ); if ( is_wp_error( $row ) ) { return self::error(); }
			$valid = self::validate( $row, $owner, $capacity ); if ( is_wp_error( $valid ) ) { return $valid; }
			$s = json_decode( $row['snapshot'], true ); $s['payment_mode'] = $s['payment_mode'] ?? PaymentMode::configured();
			$valid = PaymentMode::validate_provider( $s['payment_mode'] ); if ( is_wp_error( $valid ) ) { return $valid; }
			return self::write( $row, array( 'snapshot' => wp_json_encode( $s ) ) );
		} ) );
	}
	/** At payment submission only. Atomic primary-order claim and original creation +30m deadline. */
	public static function begin_payment( $id, $owner, $order_id, $item_id, $fingerprint ) {
		return Database::public_booking( static fn() => Database::locked( static function ( $capacity ) use ( $id, $owner, $order_id, $item_id, $fingerprint ) {
			global $wpdb;
			$row = Reservations::read( $id ); if ( is_wp_error( $row ) ) { return self::error(); }
			$valid = self::validate( $row, $owner, $capacity ); if ( is_wp_error( $valid ) ) { return $valid; }
			if ( ! Database::positive( $order_id ) || ! Database::positive( $item_id ) || ! is_string( $fingerprint ) || ! hash_equals( self::fingerprint( $row ), $fingerprint ) ) { return self::error(); }
			if ( RentalTime::shift( $row['created_at'], 30 ) <= Database::now() ) { return self::error(); }
			if ( $row['order_id'] && ( (int) $row['order_id'] !== (int) $order_id || (int) $row['order_item_id'] !== (int) $item_id ) ) { return Database::error( 'checkout', 'This rental is attached to another order. Reload the original checkout or contact the shop.' ); }
			$other = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE order_id = %d AND id <> %d FOR UPDATE', Database::table( 'reservations' ), $order_id, $id ) );
			if ( $wpdb->last_error ) { return Database::retry_error(); } if ( $other ) { return self::error(); }
			return self::write( $row, array( 'order_id' => $order_id, 'order_item_id' => $item_id, 'hold_expires_at' => RentalTime::shift( $row['created_at'], 30 ) ) );
		} ) );
	}
	/** Release only the authenticated cart's unchanged temporary hold, under the shared lock. */
	public static function release_cart_hold( $id, $owner, $product_id, $fingerprint ) {
		return Database::public_booking( static fn() => Database::locked( static function () use ( $id, $owner, $product_id, $fingerprint ) {
			$row = Reservations::read( $id ); if ( is_wp_error( $row ) ) { return $row; }
			if ( ! is_string( $owner ) || strlen( $owner ) !== 64 || ! hash_equals( (string) $row['session_hash'], $owner ) || (int) $product_id !== (int) $row['package_product_id'] || ! is_string( $fingerprint ) || ! hash_equals( self::fingerprint( $row ), $fingerprint ) ) { return self::error(); }
			if ( 'hold' !== $row['status'] ) { return $row; }
			if ( $row['order_id'] ) {
				// Square also empties carts after payment/authorization. Do not treat that as abandonment.
				// Woo reads only: no order writes, financial changes, or network inside this transaction.
				$order = wc_get_order( $row['order_id'] );
				if ( ! $order || (int) $order->get_meta( '_brp_reservation_id' ) !== (int) $id || $order->is_paid() || $order->get_date_paid() || $order->get_transaction_id() || $order->has_status( 'on-hold' ) ) { return $row; }
			}
			return self::write( $row, array( 'status' => 'cancelled', 'hold_expires_at' => null, 'issue_code' => 'cart_removed' ) );
		} ) );
	}
	/** Server-only outcome handler. Late success never overrides capacity or staff cancellation. */
	public static function outcome( $id, $order_id, $fingerprint, $paid, $refunded = false ) {
		return Database::public_booking( static fn() => Database::locked( static function ( $capacity ) use ( $id, $order_id, $fingerprint, $paid, $refunded ) {
			$row = Reservations::read( $id ); if ( is_wp_error( $row ) ) { return $row; }
			if ( (int) $row['order_id'] !== (int) $order_id ) { return self::write( $row, array( 'issue_code' => 'payment_order_link' ) ); }
			if ( ! hash_equals( self::fingerprint( $row ), (string) $fingerprint ) ) { return self::write( $row, array( 'issue_code' => 'payment_booking_changed' ) ); }
			if ( ! $paid ) {
				return in_array( $row['status'], array( 'confirmed', 'active', 'pending_waivers' ), true ) ? self::write( $row, array( 'issue_code' => 'payment_unverified' ) ) : $row;
			}
			if ( 'pending_waivers' === $row['status'] ) {
				if ( $refunded ) { return self::write( $row, array( 'issue_code' => 'payment_staff_review' ) ); }
				if ( 'payment_unverified' === $row['issue_code'] && Waivers::payment_satisfied( $row ) ) { $row = self::write( $row, array( 'issue_code' => null ) ); if ( is_wp_error( $row ) ) { return $row; } }
				return Waivers::confirm_locked( $row );
			}
			if ( in_array( $row['status'], array( 'confirmed', 'active', 'completed' ), true ) ) { return 'payment_unverified' === $row['issue_code'] ? self::write( $row, array( 'issue_code' => null ) ) : $row; }
			if ( 'payment_inventory_conflict' === $row['issue_code'] ) { return $row; } // A definitive conflict requires staff resolution, not repeated allocation attempts.
			if ( $refunded || ! in_array( $row['status'], array( 'hold', 'expired' ), true ) ) { return self::write( $row, array( 'issue_code' => 'payment_staff_review' ) ); }
			$fit = Availability::allocation( $capacity, array_replace( $row, array( 'status' => 'confirmed' ) ), $row['id'] );
			if ( is_wp_error( $fit ) ) {
				return 'brp_conflict' === $fit->get_error_code() ? self::write( $row, array( 'status' => 'expired', 'issue_code' => 'payment_inventory_conflict' ) ) : $fit;
			}
			return self::write( $row, array( 'status' => Waivers::required( $row ) && ! Waivers::progress( $row )['complete'] ? 'pending_waivers' : 'confirmed', 'issue_code' => null ) );
		} ) );
	}
	public static function flag( $id, $issue ) {
		return Database::public_booking( static fn() => Database::locked( static function () use ( $id, $issue ) { $row = Reservations::read( $id ); return is_wp_error( $row ) ? $row : self::write( $row, array( 'issue_code' => $issue ) ); } ) );
	}
	private static function write( $row, $data ) {
		$changed = false; foreach ( $data as $key => $value ) { if ( (string) $row[ $key ] !== (string) $value ) { $changed = true; } }
		if ( ! $changed ) { return $row; }
		$s = json_decode( $data['snapshot'] ?? $row['snapshot'], true ); if ( ! is_array( $s ) ) { return self::error(); }
		$s['status'] = $data['status'] ?? $row['status'];
		$data['snapshot'] = wp_json_encode( $s ); $data['revision'] = (int) $row['revision'] + 1; $data['updated_at'] = Database::now();
		return 1 === Database::update( 'reservations', $data, array( 'id' => $row['id'], 'revision' => $row['revision'] ) ) ? Reservations::read( $row['id'] ) : Database::retry_error();
	}
}
