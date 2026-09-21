<?php
/** Authorized customer roster and generic signer pages; no provider-specific UI logic. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class WaiverUI {
	public static $signed = false;
	public static function register_hooks() {
		add_shortcode( 'bike_rental_waiver', array( self::class, 'shortcode' ) );
		add_action( 'brp_waiver_completed', static function () { self::$signed = true; } );
		add_action( 'init', static function () { if ( isset( $_GET['brp_waiver'] ) || isset( $_GET['brp_riders'] ) ) { if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } nocache_headers(); header( 'Referrer-Policy: no-referrer' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); } }, 0 );
		add_action( 'template_redirect', array( self::class, 'page' ), 1 );
		add_action( 'woocommerce_thankyou', array( self::class, 'order_section' ), 25 );
		add_action( 'woocommerce_view_order', array( self::class, 'order_section' ), 25 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'order_admin' ), 25 );
		add_action( 'admin_post_brp_waiver_admin', array( self::class, 'admin_post' ) );
		add_action( 'admin_post_brp_riders', array( self::class, 'customer_post' ) );
		add_action( 'admin_post_nopriv_brp_riders', array( self::class, 'customer_post' ) );
	}
	public static function order_access( $order, $key = '' ) {
		return $order && ( Settings::can_manage() || ( get_current_user_id() && (int) $order->get_customer_id() === get_current_user_id() ) || ( is_string( $key ) && strlen( $key ) >= 10 && hash_equals( $order->get_order_key(), $key ) ) );
	}
	public static function customer_url( $order ) { return add_query_arg( array( 'brp_riders' => $order->get_id(), 'key' => $order->get_order_key() ), home_url( '/' ) ); }
	public static function notice( $row ) {
		$p = Waivers::progress( $row ); if ( ! $p['required'] ) { return ''; }
		$text = Waivers::terminal( $row ) ? 'Reservation status: ' . $row['status'] . '. Waiver records are retained.' : ( ! $p['complete'] ? Waivers::NOTICE : ( in_array( $row['status'], array( 'confirmed', 'active' ), true ) ? 'All required rider waivers have been completed. Your reservation is confirmed.' : 'All required rider waivers have been completed. Payment/confirmation is still being verified.' ) );
		return '<div class="brp-waiver-notice" role="status"><p><strong>' . esc_html( $text ) . '</strong></p><p>Waivers completed: ' . (int) $p['done'] . ' of ' . (int) $p['total'] . '</p></div>';
	}
	public static function order_section( $id ) {
		$order = wc_get_order( $id ); if ( ! self::order_access( $order, wp_unslash( $_GET['key'] ?? '' ) ) ) { return; }
		Database::public_booking( static function () use ( $order ) {
			$id = $order->get_meta( '_brp_reservation_id' ); if ( ! $id ) { return; } $row = Reservations::read( $id ); if ( is_wp_error( $row ) || (int) $row['order_id'] !== $order->get_id() ) { return; }
			echo '<section class="brp-rider-section"><h2>Rental riders and waivers</h2>';
			if ( Waivers::payment_satisfied( $row ) ) { echo '<p>Payment received.</p>'; }
			echo self::notice( $row ); self::customer_progress( $row );
			echo '<p><a href="' . esc_url( self::customer_url( $order ) ) . '">View rider waiver progress</a></p></section>';
		} );
	}
	public static function customer_progress( $row ) {
		$roster = Waivers::roster( $row ); if ( is_wp_error( $roster ) ) { echo '<p>Rider information is temporarily unavailable.</p>'; return; }
		if ( $roster && count( array_filter( $roster, static fn( $r ) => 'invitation_sent' === $r['waiver_status'] ) ) === count( $roster ) ) { echo '<p>Waiver invitations were submitted for delivery.</p>'; }
		echo '<ul>'; foreach ( $roster as $r ) { $status = ! Waivers::required( $row ) ? 'Waiver not required' : ( in_array( $r['waiver_status'], array( 'completed', 'exempt' ), true ) ? ucfirst( $r['waiver_status'] ) : ( 'minor' === $r['rider_type'] ? 'Guardian waiver pending' : 'Waiver pending' ) ); echo '<li>Rider ' . (int) $r['sequence_number'] . ' — ' . esc_html( $r['legal_name'] ?: 'Information needed' ) . ': ' . esc_html( $status . ( 'invitation_sent' === $r['waiver_status'] ? ' — invitation submitted for delivery' : '' ) ) . '</li>'; } echo '</ul>';
	}
	private static function hidden( $name, $value ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">'; }
	private static function field( $name, $label, $value = '', $type = 'text', $readonly = false ) {
		echo '<label>' . esc_html( $label ) . '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ?? '' ) . '"' . ( $readonly ? ' readonly' : '' ) . ( 'number' === $type ? ' min="0" max="120" step="1"' : ' maxlength="254"' ) . '></label>';
	}
	public static function roster_form( $row, $order = null, $admin = false, $use_purchaser = false ) {
		$roster = Waivers::roster( $row ); if ( is_wp_error( $roster ) || Waivers::terminal( $row ) || 'active' === $row['status'] ) { return; }
		if ( ! $admin && ! Waivers::payment_satisfied( $row ) ) { echo '<p>Rider collection opens after payment is verified.</p>'; return; }
		echo '<form class="brp-roster" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( 'action', $admin ? 'brp_waiver_admin' : 'brp_riders' ); self::hidden( 'operation', 'roster' ); self::hidden( 'id', $row['id'] ); self::hidden( 'revision', $row['revision'] );
		if ( $order ) { self::hidden( 'order_id', $order->get_id() ); self::hidden( 'key', $order->get_order_key() ); }
		wp_nonce_field( 'brp_riders_' . $row['id'] );
		if ( $order ) { echo '<p><button type="submit" name="use_purchaser" value="1" formnovalidate>Use purchaser information for Rider 1</button> (Enter the rider’s age separately.)</p>'; }
		for ( $i = 1; $i <= (int) $row['quantity']; ++$i ) {
			$r = $roster[ $i - 1 ] ?? array(); $readonly = ! empty( $r['waiver_id'] );
			if ( 1 === $i && $use_purchaser && $order && ! $readonly ) { $r['legal_name'] = $order->get_formatted_billing_full_name(); $r['email'] = $order->get_billing_email(); }
			echo '<fieldset><legend>Rider ' . $i . '</legend>'; if ( $readonly ) { echo '<p>Invitation/evidence already exists; identity is locked. Contact staff for corrections.</p>'; }
			foreach ( array( 'legal_name' => 'Full legal name', 'age' => 'Age (18+ adult)', 'email' => 'Rider email (required for adults)', 'guardian_name' => 'Guardian full name (minors only)', 'guardian_email' => 'Guardian email (required for minors)', 'guardian_relationship' => 'Relationship to minor' ) as $key => $label ) { self::field( 'riders[' . $i . '][' . $key . ']', $label, $r[ $key ] ?? '', 'age' === $key ? 'number' : ( str_contains( $key, 'email' ) ? 'email' : 'text' ), $readonly ); }
			echo '</fieldset>';
		}
		echo '<button type="submit">Save riders' . ( Waivers::required( $row ) ? ' and send waiver invitations' : '' ) . '</button></form>';
	}
	public static function customer_dispatch( $post ) {
		if ( ! is_array( $post ) || ! Database::positive( $post['id'] ?? null ) ) { return Database::error( 'permission', 'This reservation is unavailable.' ); }
		$order = function_exists( 'wc_get_order' ) && Database::positive( $post['order_id'] ?? null ) ? wc_get_order( $post['order_id'] ) : false;
		if ( ! self::order_access( $order, $post['key'] ?? '' ) || (string) $order->get_meta( '_brp_reservation_id' ) !== (string) ( $post['id'] ?? '' ) ) { return Database::error( 'permission', 'This reservation is unavailable.' ); }
		if ( ! is_string( $post['_wpnonce'] ?? null ) || ! wp_verify_nonce( $post['_wpnonce'], 'brp_riders_' . $post['id'] ) ) { return Database::error( 'nonce', 'Reload this reservation before saving rider information.' ); }
		return Database::public_booking( static function () use ( $post, $order ) {
			$row = Reservations::read( $post['id'] ); if ( is_wp_error( $row ) || (int) $row['order_id'] !== $order->get_id() || ! Waivers::payment_satisfied( $row ) ) { return Database::error( 'payment', 'Rider collection opens after verified payment.' ); }
			$result = Waivers::save_roster( $row['id'], $post['riders'] ?? null, $post['revision'] ?? null );
			if ( ! is_wp_error( $result ) ) { $result['invitation_errors'] = Waivers::invite_pending( $result ); } return $result;
		} );
	}
	public static function customer_post() {
		$post = wp_unslash( $_POST );
		if ( ! empty( $post['use_purchaser'] ) ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $post['order_id'] ?? 0 ) ) : false;
			if ( ! Database::positive( $post['id'] ?? null ) || ! self::order_access( $order, $post['key'] ?? '' ) || ! is_string( $post['_wpnonce'] ?? null ) || ! wp_verify_nonce( $post['_wpnonce'], 'brp_riders_' . $post['id'] ) || (string) $post['id'] !== (string) $order->get_meta( '_brp_reservation_id' ) ) { wp_die( 'Reservation unavailable.', '', array( 'response' => 403 ) ); }
			// Explicit prefill does not save or send anything. Unsaved fields should be entered afterward.
			wp_safe_redirect( add_query_arg( 'use_purchaser', '1', self::customer_url( $order ) ) ); exit;
		}
		$result = self::customer_dispatch( $post );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 'Rider information', array( 'response' => 400, 'back_link' => true ) ); }
		$order = wc_get_order( $post['order_id'] ); wp_safe_redirect( add_query_arg( 'riders_saved', empty( $result['invitation_errors'] ) ? '1' : 'mail_pending', self::customer_url( $order ) ) ); exit;
	}
	public static function admin_dispatch( $post ) {
		if ( ! Settings::can_manage() ) { return Database::error( 'permission', 'You cannot manage rider waivers.' ); }
		if ( ! is_array( $post ) || ! Database::positive( $post['id'] ?? null ) || ! in_array( $post['operation'] ?? null, array( 'roster', 'resend', 'exempt' ), true ) ) { return Database::error( 'operation', 'Invalid waiver action.' ); }
		$op = $post['operation'] ?? ''; $id = $post['id'] ?? null; $action = 'roster' === $op ? 'brp_riders_' . $id : 'brp_waiver_' . $op . '_' . $id;
		if ( ! Database::positive( $id ) || ! is_string( $post['_wpnonce'] ?? null ) || ! wp_verify_nonce( $post['_wpnonce'], $action ) ) { return Database::error( 'nonce', 'Reload before changing waiver records.' ); }
		if ( 'roster' === $op ) { $r = Waivers::save_roster( $id, $post['riders'] ?? null, $post['revision'] ?? null ); if ( ! is_wp_error( $r ) ) { $r['invitation_errors'] = Waivers::invite_pending( $r ); } return $r; }
		return match ( $op ) { 'resend' => Waivers::invite( $id, true ), 'exempt' => Waivers::exempt( $id, $post['reason'] ?? '' ), default => Database::error( 'operation', 'Invalid waiver action.' ) };
	}
	public static function admin_post() {
		$post = wp_unslash( $_POST ); $result = self::admin_dispatch( $post );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 'Rider waiver', array( 'response' => 400, 'back_link' => true ) ); }
		$id = 'roster' === ( $post['operation'] ?? '' ) ? $post['id'] : ( Database::read( 'waivers', $post['id'] )['reservation_id'] ?? 0 );
		set_transient( 'brp_data_notice_' . get_current_user_id(), array( 'error' => ! empty( $result['invitation_errors'] ), 'message' => ! empty( $result['invitation_errors'] ) ? 'Riders saved. Some invitations could not be sent; review provider/mail setup and resend.' : 'Rider/waiver action saved.' ), 60 );
		wp_safe_redirect( DataAdmin::url( DataAdmin::RESERVATIONS, $id ) ); exit;
	}
	public static function reservation_admin( $row ) {
		if ( ! Settings::can_manage() ) { return; } $p = Waivers::progress( $row ); $roster = Waivers::roster( $row );
		echo '<h2>Riders and waivers</h2><p><strong>Waivers: ' . esc_html( $p['label'] ) . '</strong></p>'; if ( is_wp_error( $roster ) ) { echo '<p>Rider records unavailable.</p>'; return; }
		foreach ( $roster as $r ) {
			echo '<details><summary>Rider ' . (int) $r['sequence_number'] . ' — ' . esc_html( $r['legal_name'] ?: 'Information needed' ) . ' — ' . esc_html( $r['waiver_status'] ?? ( $p['required'] ? 'Pending' : 'Not Required' ) ) . '</summary><dl>';
			foreach ( array( 'age', 'rider_type', 'email', 'guardian_name', 'guardian_email', 'guardian_relationship', 'provider', 'provider_submission_id', 'last_invited_at', 'completed_at', 'override_reason', 'override_user_id' ) as $key ) { echo '<dt>' . esc_html( ucwords( str_replace( '_', ' ', $key ) ) ) . '</dt><dd>' . esc_html( $r[ $key ] ?? '—' ) . '</dd>'; } echo '</dl>';
			if ( $r['waiver_id'] && ! Waivers::terminal( $row ) && ! in_array( $r['waiver_status'], array( 'completed', 'exempt' ), true ) ) {
				foreach ( array( 'resend' => 'Resend Waiver Email', 'exempt' => 'Waiver Exempt — record exception' ) as $op => $label ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; self::hidden( 'action', 'brp_waiver_admin' ); self::hidden( 'operation', $op ); self::hidden( 'id', $r['waiver_id'] ); wp_nonce_field( 'brp_waiver_' . $op . '_' . $r['waiver_id'] ); if ( 'exempt' === $op ) { self::field( 'reason', 'Required exemption reason' ); } echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
				}
			} echo '</details>';
		} self::roster_form( $row, null, true );
	}
	public static function order_admin( $order ) {
		if ( ! Settings::can_manage() || ! $order->get_meta( '_brp_reservation_id' ) ) { return; } $row = Reservations::read( $order->get_meta( '_brp_reservation_id' ) ); if ( is_wp_error( $row ) ) { return; }
		echo '<p><a href="' . esc_url( DataAdmin::url( DataAdmin::RESERVATIONS, $row['id'] ) ) . '">Waivers: ' . esc_html( Waivers::progress( $row )['label'] ) . '</a></p>';
	}
	private static function document( $content ) {
		nocache_headers(); header( 'Referrer-Policy: no-referrer' ); header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Rental riders and waivers</title>'; wp_head();
		echo '<style>.brp-waiver-page{max-width:52rem;margin:2rem auto;padding:1rem;overflow-wrap:anywhere}.brp-roster label{display:block;margin:1rem 0}.brp-roster input{font:inherit;min-height:44px;padding:8px;display:block;width:100%;max-width:100%;box-sizing:border-box}.brp-roster fieldset{min-width:0;margin:1rem 0;padding:1rem}.brp-roster button{min-height:48px}.brp-waiver-notice{border:2px solid currentColor;padding:1rem}.brp-waiver-text{white-space:pre-wrap}</style></head><body><main class="brp-waiver-page">' . $content . '</main>'; wp_footer(); echo '</body></html>'; exit;
	}
	/** Provider-independent page content; invalid tokens never reach a form renderer. */
	public static function shortcode() {
		if ( self::$signed ) { return '<p>Your rider waiver was recorded. The purchaser can view reservation progress from their order.</p>'; }
			$token = wp_unslash( $_GET['brp_waiver'] ?? '' ); $ctx = Waivers::context( $token );
			if ( is_wp_error( $ctx ) ) { return '<p>Waiver link unavailable. Ask the shop for a current invitation.</p>'; }
			$w = $ctx['waiver']; $r = $ctx['rider']; $p = Waivers::providers()[ $w['provider'] ] ?? null;
			$content = '<h1>Rider waiver</h1><p>Rider: ' . esc_html( $r['legal_name'] ) . ' · Age: ' . (int) $r['age'] . '</p><p>Signer: ' . esc_html( $w['signer_name'] . ' (' . $w['signer_role'] . ')' ) . '</p><p>Reservation: ' . esc_html( $ctx['reservation']['reference'] ) . ' · Waiver version: ' . esc_html( $w['waiver_version'] ) . '</p><div class="brp-waiver-text" style="white-space:pre-wrap">' . esc_html( $w['waiver_text'] ) . '</div><p>By accepting and signing, you confirm that you are the named adult rider signing for yourself, or the named parent/guardian signing for this minor, and accept the waiver text above.</p>';
			$content .= $p ? $p->render( $ctx, $token ) : '<p>Provider unavailable. Contact the shop.</p>'; return $content;
	}
	public static function page() {
		// Preserve older root invitation URLs; configured pages render the generic shortcode in their theme.
		if ( isset( $_GET['brp_waiver'] ) && ! is_page( WaiverSettings::get()['signing_page'] ) ) { self::document( self::shortcode() ); }
		if ( ! isset( $_GET['brp_riders'] ) ) { return; }
		$order = function_exists( 'wc_get_order' ) && Database::positive( $_GET['brp_riders'] ) ? wc_get_order( (int) $_GET['brp_riders'] ) : false;
		if ( ! self::order_access( $order, wp_unslash( $_GET['key'] ?? '' ) ) ) { status_header( 403 ); self::document( '<h1>Reservation unavailable</h1>' ); }
		$content = Database::public_booking( static function () use ( $order ) {
			$row = Reservations::read( $order->get_meta( '_brp_reservation_id' ) ); if ( is_wp_error( $row ) || (int) $row['order_id'] !== $order->get_id() ) { return '<p>Reservation unavailable.</p>'; }
			ob_start(); echo '<h1>Rental riders</h1>' . self::notice( $row );
			if ( isset( $_GET['riders_saved'] ) ) { echo '<p>Rider information saved. Invitation status is listed below. Contact the shop if an invitation has not arrived.</p>'; }
			self::customer_progress( $row ); self::roster_form( $row, $order, false, '1' === ( $_GET['use_purchaser'] ?? '' ) ); return ob_get_clean();
		} ); self::document( $content );
	}
}
