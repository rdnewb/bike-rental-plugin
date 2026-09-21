<?php
/** Single-record deletion and bounded retention cleanup. Never deletes provider or Woo records. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class ReservationCleanup {
	const WARNING = 'This permanently removes this cancelled/expired reservation and associated non-protected rider/waiver records. This action cannot be undone.';
	const PAYMENT = 'This reservation cannot be permanently deleted because it is linked to protected payment/order records.';
	const RETENTION_DAYS = 30;
	public static function eligible( $row ) {
		global $wpdb;
		$gate = Database::gate(); if ( is_wp_error( $gate ) ) { return $gate; }
		if ( ! in_array( $row['status'], array( 'cancelled', 'expired' ), true ) ) { return Database::error( 'delete_status', 'Only cancelled or expired reservations without protected evidence can be permanently deleted.' ); }
		if ( $row['order_id'] || $row['order_item_id'] || str_starts_with( (string) $row['issue_code'], 'payment_' ) ) { return Database::error( 'delete_payment', self::PAYMENT ); }
		if ( ! function_exists( 'wc_get_orders' ) ) { return Database::error( 'delete_payment', 'Activate WooCommerce to verify order relationships before cleanup.' ); }
		try {
			$meta = array( 'relation' => 'OR', array( 'key' => '_brp_reservation_id', 'value' => (string) $row['id'] ), array( 'key' => '_brp_reservation_reference', 'value' => $row['reference'] ) );
			$args = array( 'limit' => 1, 'return' => 'ids', 'status' => array_merge( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft', 'trash', 'auto-draft' ) ) );
			// CPT ignores the HPOS-only meta_query argument; translate only this scoped lookup.
			$cpt = static function ( $query, $vars ) { if ( isset( $vars['brp_cleanup_meta'] ) ) { $query['meta_query'] = $vars['brp_cleanup_meta']; } return $query; };
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt, 10, 2 );
			try {
				$args[ \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'meta_query' : 'brp_cleanup_meta' ] = $meta;
				$orders = wc_get_orders( $args );
			} finally { remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt, 10 ); }
			if ( $wpdb->last_error || ! is_array( $orders ) ) { return Database::retry_error(); }
			if ( $orders ) { return Database::error( 'delete_payment', self::PAYMENT ); }
		} catch ( \Throwable $e ) { return Database::retry_error(); }
		$evidence = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i w LEFT JOIN %i r ON r.id=w.rider_id WHERE (w.reservation_id=%d OR r.reservation_id=%d) AND (w.status IN ('completed','exempt') OR w.completed_at IS NOT NULL OR w.provider_submission_id IS NOT NULL OR w.override_user_id<>0 OR w.override_reason<>'' OR r.reservation_id IS NULL OR r.reservation_id<>w.reservation_id)", Database::table( 'waivers' ), Database::table( 'riders' ), $row['id'], $row['id'] ) );
		if ( $wpdb->last_error || null === $evidence ) { return Database::retry_error(); }
		if ( $evidence ) { return Database::error( 'delete_evidence', 'This reservation has protected completed waiver, exemption, provider, or inconsistent audit evidence and cannot be permanently deleted.' ); }
		$blocks = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE reason=%s', Database::table( 'availability' ), 'Return turnaround: ' . $row['reference'] ) );
		if ( $wpdb->last_error || null === $blocks ) { return Database::retry_error(); }
		return $blocks ? Database::error( 'delete_evidence', 'Return-turnaround audit evidence prevents permanent deletion.' ) : true;
	}
	public static function remove( $id, $revision, $confirmation ) {
		if ( ! Settings::can_manage() ) { return Database::error( 'permission', 'You cannot permanently delete reservations.' ); }
		if ( 'yes' !== $confirmation ) { return Database::error( 'delete_confirm', 'Confirm permanent deletion before continuing.' ); }
		return Database::locked( static function () use ( $id, $revision ) {
			$row = Reservations::read( $id ); if ( is_wp_error( $row ) ) { return $row; }
			if ( ! Database::positive( $revision ) || (string) $revision !== (string) $row['revision'] ) { return Database::error( 'revision', 'The reservation changed. Reload before deleting.' ); }
			$eligible = self::eligible( $row ); if ( is_wp_error( $eligible ) ) { return $eligible; }
			foreach ( array( 'waivers', 'riders' ) as $table ) { if ( false === Database::delete( $table, array( 'reservation_id' => $id ) ) ) { return Database::retry_error(); } }
			if ( 1 !== Database::delete( 'reservations', array( 'id' => $id, 'revision' => $revision ) ) ) { return Database::retry_error(); }
			return array( 'id' => 0 );
		} );
	}
	/** Preserve reservation audit metadata, remove abandoned PII only after 30 terminal days. */
	public static function retention() {
		$cursor = (int) get_option( 'brp_rider_retention_cursor', 0 );
		$result = Database::public_booking( static fn() => Database::locked( static function () use ( $cursor ) {
			global $wpdb; $cutoff = RentalTime::shift( Database::now(), -self::RETENTION_DAYS * 1440 );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i r WHERE r.id>%d AND r.status IN ('cancelled','expired') AND r.order_id IS NULL AND r.updated_at<%s AND EXISTS (SELECT 1 FROM %i ri WHERE ri.reservation_id=r.id) ORDER BY r.id LIMIT 50", Database::table( 'reservations' ), $cursor, $cutoff, Database::table( 'riders' ) ), ARRAY_A );
			if ( $wpdb->last_error || ! is_array( $rows ) ) { return Database::retry_error(); } $count = 0;
			foreach ( $rows as $row ) {
				$eligible = self::eligible( $row ); if ( is_wp_error( $eligible ) ) { continue; }
				foreach ( array( 'waivers', 'riders' ) as $table ) { if ( false === Database::delete( $table, array( 'reservation_id' => $row['id'] ) ) ) { return Database::retry_error(); } }
				++$count;
			} return array( 'count' => $count, 'cursor' => count( $rows ) === 50 ? (int) end( $rows )['id'] : 0 );
		} ) );
		if ( is_wp_error( $result ) ) { return $result; }
		update_option( 'brp_rider_retention_cursor', $result['cursor'], false ); return $result['count'];
	}
	public static function render( $row ) {
		if ( ! Settings::can_manage() || ! in_array( $row['status'], array( 'cancelled', 'expired' ), true ) ) { return; }
		$eligible = self::eligible( $row );
		if ( is_wp_error( $eligible ) ) { echo '<p>' . esc_html( $eligible->get_error_message() ) . '</p>'; return; }
		echo '<details><summary>Delete Permanently</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p><strong>' . esc_html( self::WARNING ) . '</strong></p>';
		foreach ( array( 'action' => 'brp_data_save', 'operation' => 'reservation_delete', 'id' => $row['id'], 'revision' => $row['revision'] ) as $key => $value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
		wp_nonce_field( 'brp_reservation_delete_' . $row['id'] );
		echo '<p><label><input type="checkbox" name="confirm_delete" value="yes" required> I confirm permanent deletion of ' . esc_html( $row['reference'] ) . '.</label></p><button class="button" type="submit">Permanently Delete Reservation</button></form></details>';
	}
}
