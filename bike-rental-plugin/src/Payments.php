<?php
/** Payment observation, bounded reconciliation, and staff visibility. Never changes financial records. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class Payments {
	public static function register_hooks() {
		add_action( 'woocommerce_payment_complete', array( self::class, 'observe' ), 50 );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'observe' ), 50 );
		add_action( HoldCleanup::HOOK, array( self::class, 'reconcile' ), 20 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'order_admin' ) );
	}
	public static function issue_message( $issue ) {
		return match ( $issue ) {
			'payment_inventory_conflict' => 'Payment received — inventory conflict requires staff resolution.',
			'payment_booking_changed' => 'Rental details changed after checkout. Review the reservation and paid order before fulfillment.',
			'payment_unverified' => 'Reservation is confirmed or active, but full payment could not be verified. Staff review required.',
			'payment_staff_review' => 'Payment received for a refunded or cancelled rental. Staff review required.',
			'payment_order_link' => 'Order/reservation association is missing or inconsistent. Staff review required.',
			default => '',
		};
	}
	public static function observe( $order_id ) {
		$order = wc_get_order( $order_id ); if ( ! $order || ! $order->get_meta( '_brp_reservation_id' ) ) { return; }
		$id = $order->get_meta( '_brp_reservation_id' );
		$row = Database::public_booking( static fn() => Reservations::read( $id ) );
		if ( is_wp_error( $row ) ) { self::order_issue( $order, 'payment_order_link' ); return; }
		$items = $order->get_items(); $item = count( $items ) === 1 ? reset( $items ) : false;
		if ( ! $item || (int) $row['order_id'] !== (int) $order_id || (int) $row['order_item_id'] !== $item->get_id() || (int) $row['package_product_id'] !== (int) $item->get_product_id() || (int) $row['quantity'] !== (int) $item->get_quantity() || $order->get_meta( '_brp_reservation_reference' ) !== $row['reference'] ) {
			CheckoutReservation::flag( $id, 'payment_order_link' ); self::order_issue( $order, 'payment_order_link' ); return;
		}
		$result = CheckoutReservation::outcome( $id, $order_id, $order->get_meta( '_brp_fingerprint' ), PaymentMode::paid( $order ), $order->get_total_refunded() > 0 );
		if ( is_wp_error( $result ) ) {
			// Keep the order discoverable for the next cron pass; do not lose payment evidence.
			self::order_issue( $order, 'payment_order_link' ); return;
		}
		self::order_issue( $order, self::issue_message( $result['issue_code'] ) ? $result['issue_code'] : '' );
	}
	private static function order_issue( $order, $issue ) {
		if ( $order->get_meta( '_brp_payment_issue' ) === $issue ) { return; }
		$order->update_meta_data( '_brp_payment_issue', $issue ); $order->save();
		if ( $issue ) { $order->add_order_note( self::issue_message( $issue ) ); }
	}
	/** Two bounded cursors inspect both directions of the relation, including crash windows. */
	public static function reconcile() {
		global $wpdb;
		if ( ! function_exists( 'wc_get_order' ) || Database::VERSION !== get_option( Database::OPTION ) || get_option( Database::ERROR ) ) { return; }
		$cursor = (int) get_option( 'brp_payment_reservation_cursor', 0 );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,order_id,snapshot FROM %i WHERE id > %d ORDER BY id LIMIT 50', Database::table( 'reservations' ), $cursor ), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error ) { return; }
		foreach ( $rows as $row ) {
			$cursor = (int) $row['id']; $snapshot = json_decode( $row['snapshot'], true );
			if ( ! $row['order_id'] ) { continue; } // Transferred carts without orders are ordinary bounded holds.
			$order = wc_get_order( $row['order_id'] );
			if ( ! $order || (int) $order->get_meta( '_brp_reservation_id' ) !== (int) $row['id'] ) { CheckoutReservation::flag( $row['id'], 'payment_order_link' ); if ( $order ) { self::order_issue( $order, 'payment_order_link' ); } }
			else { self::observe( $row['order_id'] ); }
		}
		update_option( 'brp_payment_reservation_cursor', count( $rows ) < 50 ? 0 : $cursor, false );
		// Standard WC query works with CPT and HPOS; no order-table SQL or provider-specific query.
		$page = max( 1, (int) get_option( 'brp_payment_order_page', 1 ) );
		$orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => 50, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC' ) );
		foreach ( $orders as $order ) { if ( $order->get_meta( '_brp_reservation_id' ) ) { self::observe( $order->get_id() ); } }
		update_option( 'brp_payment_order_page', count( $orders ) < 50 ? 1 : $page + 1, false );
	}
	public static function order_admin( $order ) {
		if ( ! Settings::can_manage() || ! $order->get_meta( '_brp_reservation_id' ) ) { return; }
		$id = $order->get_meta( '_brp_reservation_id' );
		$url = DataAdmin::url( DataAdmin::RESERVATIONS, $id );
		echo '<p><strong>Rental reservation:</strong> <a href="' . esc_url( $url ) . '">' . esc_html( $order->get_meta( '_brp_reservation_reference' ) ) . '</a></p>';
		echo '<p>' . esc_html( PaymentMode::get_payment_summary( $order ) ) . '</p>';
		self::warning( $order->get_meta( '_brp_payment_issue' ) );
	}
	public static function warning( $issue ) {
		$message = self::issue_message( $issue );
		if ( $message ) { echo '<div class="notice notice-error inline"><p><strong>' . esc_html( $message ) . '</strong></p></div>'; }
	}
	public static function reservation_admin( $row ) {
		if ( ! Settings::can_manage() ) { return; }
		$s = json_decode( $row['snapshot'], true );
		echo '<h3>Payment</h3><p><strong>Payment Mode:</strong> ' . esc_html( PaymentMode::label( $s['payment_mode'] ?? '' ) ) . '</p>';
		self::warning( $row['issue_code'] );
		$order = function_exists( 'wc_get_order' ) && $row['order_id'] ? wc_get_order( $row['order_id'] ) : false;
		if ( $order ) { echo '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">Order #' . esc_html( $order->get_order_number() ) . '</a></p>'; }
		echo '<p>' . esc_html( PaymentMode::get_payment_summary( $order ) ) . '</p>';
	}
}
