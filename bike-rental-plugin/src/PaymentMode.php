<?php
/** Small payment-policy boundary. WooCommerce owns all financial amounts. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class PaymentMode {
	public static function configured() { return Settings::get()['payment_mode'] ?? 'full'; }
	public static function is_deposit_mode_available() { return false; }
	public static function label( $mode ) { return match ( $mode ) { 'full' => 'Full Payment', 'deposit' => 'Deposit', default => 'Not started' }; }
	public static function validate_provider( $mode ) {
		if ( 'full' !== $mode ) { return Database::error( 'checkout', 'Online rental payment is not available with the current payment configuration. Please contact the shop.' ); }
		// Do not permit an unsupported extension to split a full-payment order behind this policy.
		if ( Plugin::dependencies()['deposits']['available'] ) { return Database::error( 'checkout', 'Online rental payment is temporarily unavailable. Please contact the shop.' ); }
		return true;
	}
	public static function admin_notice() {
		if ( ! Settings::can_manage() ) { return; }
		$message = '';
		if ( 'deposit' === self::configured() ) { $message = 'Deposit payment mode is selected, but no compatible deposit provider is configured. New rental checkout is blocked.'; }
		elseif ( Plugin::dependencies()['deposits']['available'] ) { $message = 'Deactivate unsupported deposit extensions before using rental Full Payment checkout. No deposit extension is required for Full Payment.'; }
		if ( $message ) { echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>'; }
	}
	public static function get_amount_due_now( $order ) { return $order && 'full' === $order->get_meta( '_brp_payment_mode' ) ? $order->get_total() : null; }
	public static function get_remaining_balance( $order ) { return $order && 'full' === $order->get_meta( '_brp_payment_mode' ) && self::paid( $order ) ? '0' : null; }
	/** Read Square's persisted capture evidence. An order status by itself is insufficient. */
	public static function paid( $order ) {
		return $order && 'full' === $order->get_meta( '_brp_payment_mode' ) && 'square_credit_card' === $order->get_payment_method() && $order->get_date_paid() && '' !== $order->get_transaction_id() && 'yes' === $order->get_meta( '_wc_square_credit_card_charge_captured' ) && (float) $order->get_total() > 0 && wc_format_decimal( $order->get_meta( '_wc_square_credit_card_authorization_amount' ), wc_get_price_decimals() ) === wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
	}
	public static function get_payment_summary( $order ) {
		if ( ! $order ) { return 'Order data unavailable.'; }
		return self::label( $order->get_meta( '_brp_payment_mode' ) ) . ' | Order: ' . wc_get_order_status_name( $order->get_status() ) . ' | Payment: ' . ( self::paid( $order ) ? 'captured' : 'not verified' ) . ' | Total: ' . wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) . ' | Refunded: ' . wp_strip_all_tags( wc_price( $order->get_total_refunded(), array( 'currency' => $order->get_currency() ) ) );
	}
}
