<?php
/** Generic rider storage, invitation orchestration and locked readiness transitions. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class Waivers {
	const NOTICE = 'Action required: Your reservation is not confirmed until all required rider waivers have been completed.';
	private static $providers = array();
	public static function register_provider( $id, WaiverProvider $provider ) { if ( preg_match( '/^[a-z][a-z0-9_]{0,39}$/D', $id ) ) { self::$providers[ $id ] = $provider; } }
	public static function providers() { return self::$providers; }
	public static function policy( $row ) { $s = json_decode( $row['snapshot'] ?? '{}', true ); return $s['waiver_policy'] ?? array( 'required' => 'no' ); }
	public static function required( $row ) { return 'yes' === ( self::policy( $row )['required'] ?? 'no' ); }
	public static function terminal( $row ) { return in_array( $row['status'], array( 'cancelled', 'expired', 'completed' ), true ); }
	public static function roster( $row ) {
		global $wpdb; $gate = Database::gate(); if ( is_wp_error( $gate ) ) { return $gate; }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT r.*,w.id AS waiver_id,w.status AS waiver_status,w.provider,w.provider_submission_id,w.last_invited_at,w.completed_at,w.override_reason,w.override_user_id FROM %i r LEFT JOIN %i w ON w.rider_id=r.id WHERE r.reservation_id=%d AND r.sequence_number<=%d ORDER BY r.sequence_number', Database::table( 'riders' ), Database::table( 'waivers' ), $row['id'], $row['quantity'] ), ARRAY_A );
		return $wpdb->last_error || ! is_array( $rows ) ? Database::retry_error() : $rows;
	}
	public static function progress( $row ) {
		if ( ! self::required( $row ) ) { return array( 'required' => false, 'complete' => true, 'done' => 0, 'total' => (int) $row['quantity'], 'label' => 'Not Required' ); }
		$rows = self::roster( $row ); $done = 0; $valid = 0;
		if ( ! is_wp_error( $rows ) ) { foreach ( $rows as $r ) { if ( ! is_wp_error( self::validate_rider( $r ) ) ) { ++$valid; if ( in_array( $r['waiver_status'], array( 'completed', 'exempt' ), true ) ) { ++$done; } } } }
		$total = (int) $row['quantity']; return array( 'required' => true, 'complete' => $done === $total && $valid === $total, 'done' => $done, 'total' => $total, 'label' => $done . '/' . $total . ( $done === $total ? ' Complete' : ' Pending' ) );
	}
	/** Authorization is inherited from the caller's admin/validated-customer boundary. */
	public static function reconcile_locked( $row, $quantity = null ) {
		global $wpdb; if ( ! Database::in_transaction() ) { return Database::retry_error(); } $quantity = (int) ( $quantity ?? $row['quantity'] );
		$extra = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i r LEFT JOIN %i w ON w.rider_id=r.id WHERE r.reservation_id=%d AND r.sequence_number>%d AND (r.legal_name<>'' OR w.id IS NOT NULL)", Database::table( 'riders' ), Database::table( 'waivers' ), $row['id'], $quantity ) );
		if ( $wpdb->last_error ) { return Database::retry_error(); }
		if ( $extra ) { return Database::error( 'riders', 'Quantity reduction would remove a populated rider or waiver. Resolve the roster explicitly with staff; signed records are never deleted.' ); }
		for ( $i = 1; $i <= $quantity; ++$i ) {
			$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE reservation_id=%d AND sequence_number=%d', Database::table( 'riders' ), $row['id'], $i ) );
			if ( $wpdb->last_error ) { return Database::retry_error(); }
			if ( ! $id && false === Database::insert( 'riders', array( 'reservation_id' => $row['id'], 'sequence_number' => $i, 'created_at' => Database::now(), 'updated_at' => Database::now() ) ) ) { return Database::retry_error(); }
		} return true;
	}
	public static function validate_rider( $input ) {
		if ( ! is_array( $input ) ) { return Database::error( 'rider', 'Complete every rider record.' ); } $v = array();
		foreach ( array( 'legal_name' => 240, 'guardian_name' => 240, 'guardian_relationship' => 120, 'email' => 254, 'guardian_email' => 254 ) as $key => $max ) {
			if ( ! is_string( $input[ $key ] ?? '' ) || strlen( $input[ $key ] ?? '' ) > $max ) { return Database::error( 'rider', 'Rider names and emails must be valid text within the allowed length.' ); }
			$v[ $key ] = sanitize_text_field( $input[ $key ] ?? '' );
		}
		if ( ! is_scalar( $input['age'] ?? null ) || ! preg_match( '/^(0|[1-9][0-9]{0,2})$/D', (string) $input['age'] ) || (int) $input['age'] > 120 || '' === $v['legal_name'] ) { return Database::error( 'rider', 'Each rider needs a full legal name and age from 0 to 120.' ); }
		$v['age'] = (int) $input['age']; $v['rider_type'] = $v['age'] >= 18 ? 'adult' : 'minor';
		if ( 'adult' === $v['rider_type'] ) {
			if ( ! is_email( $v['email'] ) ) { return Database::error( 'rider', 'Every adult rider needs their own email address and must sign for themselves.' ); }
			$v['guardian_name'] = ''; $v['guardian_email'] = ''; $v['guardian_relationship'] = '';
		} elseif ( '' === $v['guardian_name'] || ! is_email( $v['guardian_email'] ) || '' === $v['guardian_relationship'] ) { return Database::error( 'rider', 'Each minor needs a guardian name, email and relationship.' ); }
		if ( '' !== $v['email'] && ! is_email( $v['email'] ) ) { return Database::error( 'rider', 'Enter a valid rider email or leave a minor email blank.' ); }
		$v['email'] = strtolower( $v['email'] ); $v['guardian_email'] = strtolower( $v['guardian_email'] ); return $v;
	}
	public static function save_roster( $id, $input, $revision ) {
		if ( ! Database::positive( $revision ) ) { return Database::error( 'revision', 'Reload the reservation before saving riders.' ); }
		return Database::locked( static function () use ( $id, $input, $revision ) {
			global $wpdb; $row = Reservations::read( $id ); if ( is_wp_error( $row ) ) { return $row; }
			if ( self::terminal( $row ) || 'active' === $row['status'] ) { return Database::error( 'rider', 'Rider changes are unavailable for this reservation status.' ); }
			if ( (string) $revision !== (string) $row['revision'] ) { return Database::error( 'revision', 'The reservation changed elsewhere. Reload before saving riders.' ); }
			if ( ! is_array( $input ) || count( $input ) !== (int) $row['quantity'] ) { return Database::error( 'rider', 'Supply exactly one rider per reserved bike.' ); }
			$sync = self::reconcile_locked( $row ); if ( is_wp_error( $sync ) ) { return $sync; } $roster = self::roster( $row ); if ( is_wp_error( $roster ) ) { return $roster; }
			$changed = false;
			foreach ( $roster as $r ) {
				$v = self::validate_rider( $input[ $r['sequence_number'] ] ?? null ); if ( is_wp_error( $v ) ) { return $v; }
				$diff = array_diff_assoc( $v, array_intersect_key( $r, $v ) );
				if ( $diff && $r['waiver_id'] ) { return Database::error( 'rider', 'A rider with a waiver request cannot be replaced or edited. Contact staff to cancel/rebook if the signer is incorrect; existing evidence is retained.' ); }
				if ( $diff ) { $v['updated_at'] = Database::now(); if ( 1 !== Database::update( 'riders', $v, array( 'id' => $r['id'] ) ) ) { return Database::retry_error(); } $changed = true; }
			}
			if ( $changed && 1 !== Database::update( 'reservations', array( 'revision' => (int) $row['revision'] + 1, 'updated_at' => Database::now() ), array( 'id' => $id, 'revision' => $row['revision'] ) ) ) { return Database::retry_error(); }
			return Reservations::read( $id );
		} );
	}
	/** Validate a numbered roster before allocating inventory. Never trust supplied classification. */
	public static function validate_roster( $input, $quantity ) {
		if ( ! is_array( $input ) || count( $input ) !== (int) $quantity ) { return Database::error( 'rider', 'Supply exactly one rider per reserved bike.' ); }
		$out = array();
		for ( $i = 1; $i <= (int) $quantity; ++$i ) {
			$out[ $i ] = self::validate_rider( $input[ $i ] ?? null );
			if ( is_wp_error( $out[ $i ] ) ) { return $out[ $i ]; }
		}
		return $out;
	}
	public static function roster_ready( $row ) {
		$rows = self::roster( $row );
		if ( is_wp_error( $rows ) ) { return $rows; }
		$input = array(); foreach ( $rows as $r ) { $input[ $r['sequence_number'] ] = $r; }
		$v = self::validate_roster( $input, $row['quantity'] ); return is_wp_error( $v ) ? $v : true;
	}
	/** Used inside the initial hold transaction; rollback covers both reservation and riders. */
	public static function store_initial_roster( $row, $input ) {
		if ( ! Database::in_transaction() ) { return Database::retry_error(); }
		$v = self::validate_roster( $input, $row['quantity'] ); if ( is_wp_error( $v ) ) { return $v; }
		foreach ( $v as $sequence => $r ) {
			$r += array( 'reservation_id' => $row['id'], 'sequence_number' => $sequence, 'created_at' => Database::now(), 'updated_at' => Database::now() );
			if ( false === Database::insert( 'riders', $r ) ) { return Database::retry_error(); }
		} return true;
	}
	/** Requests are frozen only after verified payment; repeated callbacks reuse existing records. */
	public static function activate_requests( $id ) {
		return Database::locked( static function () use ( $id ) {
			$row = Reservations::read( $id ); if ( is_wp_error( $row ) ) { return $row; }
			if ( ! self::required( $row ) || self::terminal( $row ) || ! self::payment_satisfied( $row ) ) { return $row; }
			$valid = self::roster_ready( $row ); if ( is_wp_error( $valid ) ) { return $valid; }
			$policy = self::policy( $row );
			$roster = self::roster( $row ); if ( is_wp_error( $roster ) ) { return $roster; }
			foreach ( $roster as $r ) {
				if ( $r['waiver_id'] ) { continue; }
				$adult = 'adult' === $r['rider_type'];
				$data = array( 'reservation_id' => $id, 'rider_id' => $r['id'], 'provider' => $policy['provider'], 'provider_config' => wp_json_encode( $policy[ $policy['provider'] ] ?? array() ), 'waiver_version' => $policy['version'], 'waiver_text' => $policy['text'], 'text_hash' => hash( 'sha256', $policy['text'] ), 'signer_name' => $adult ? $r['legal_name'] : $r['guardian_name'], 'signer_email' => $adult ? $r['email'] : $r['guardian_email'], 'signer_role' => $adult ? 'self' : 'guardian', 'created_at' => Database::now(), 'updated_at' => Database::now() );
				if ( false === Database::insert( 'waivers', $data ) ) { return Database::retry_error(); }
			} return $row;
		} );
	}
	public static function payment_satisfied( $row ) {
		if ( ! function_exists( 'wc_get_order' ) || ! $row['order_id'] ) { return false; }
		$order = wc_get_order( $row['order_id'] );
		if ( ! $order || (int) $order->get_meta( '_brp_reservation_id' ) !== (int) $row['id'] || $order->get_meta( '_brp_reservation_reference' ) !== $row['reference'] || ! hash_equals( CheckoutReservation::fingerprint( $row ), (string) $order->get_meta( '_brp_fingerprint' ) ) || ! PaymentMode::paid( $order ) || $order->get_total_refunded() > 0 || $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) { return false; }
		$item = $order->get_item( $row['order_item_id'] ); return $item && (int) $item->get_product_id() === (int) $row['package_product_id'] && (int) $item->get_quantity() === (int) $row['quantity'];
	}
	public static function guard_state( $row, $previous = null ) {
		if ( 'pending_waivers' === $row['status'] && ! self::required( $row ) ) { return Database::error( 'waivers', 'This reservation does not require rider waivers.' ); }
		if ( ! self::required( $row ) ) { return true; }
		if ( empty( $row['id'] ) && in_array( $row['status'], array( 'pending_waivers', 'confirmed', 'active', 'completed' ), true ) ) { return Database::error( 'waivers', 'Create a hold first, then complete payment and the required rider waivers.' ); }
		if ( $previous && 'completed' === $previous['status'] && 'completed' === $row['status'] ) { return true; }
		if ( in_array( $row['status'], array( 'confirmed', 'active', 'completed' ), true ) && ! self::progress( $row )['complete'] ) { return Database::error( 'waivers', 'active' === $row['status'] ? 'Rental cannot be marked Active until all required rider waivers are complete.' : 'All required rider waivers must be complete before confirmation or fulfillment.' ); }
		if ( in_array( $row['status'], array( 'pending_waivers', 'confirmed', 'active' ), true ) && ! self::payment_satisfied( $row ) ) { return Database::error( 'waiver_payment', 'Verified payment is required before this reservation can be confirmed or activated.' ); }
		return true;
	}
	/** Caller holds the shared transaction; no emails or provider network requests here. */
	public static function confirm_locked( $row ) {
		if ( 'pending_waivers' !== $row['status'] || ! self::progress( $row )['complete'] || ! self::payment_satisfied( $row ) ) { return $row; }
		$s = json_decode( $row['snapshot'], true ); $s['status'] = 'confirmed';
		$data = array( 'status' => 'confirmed', 'snapshot' => wp_json_encode( $s ), 'revision' => (int) $row['revision'] + 1, 'updated_at' => Database::now() );
		return 1 === Database::update( 'reservations', $data, array( 'id' => $row['id'], 'revision' => $row['revision'] ) ) ? Reservations::read( $row['id'] ) : Database::retry_error();
	}
	public static function context( $token ) {
		global $wpdb;
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { return Database::error( 'waiver_link', 'This waiver link is unavailable or expired. Ask the shop for a new invitation.' ); }
		return Database::public_booking( static function () use ( $token, $wpdb ) {
			$gate = Database::gate(); if ( is_wp_error( $gate ) ) { return $gate; }
			$w = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s', Database::table( 'waivers' ), hash( 'sha256', $token ) ), ARRAY_A );
			if ( ! $w || $wpdb->last_error || ! hash_equals( (string) $w['token_hash'], hash( 'sha256', $token ) ) || ! in_array( $w['status'], array( 'invitation_pending', 'invitation_sent' ), true ) || $w['token_expires_at'] <= gmdate( 'Y-m-d H:i:s' ) ) { return Database::error( 'waiver_link', 'This waiver link is unavailable or expired. Ask the shop for a new invitation.' ); }
			$r = Database::read( 'riders', $w['rider_id'] ); $row = Reservations::read( $w['reservation_id'] );
			if ( is_wp_error( $r ) || is_wp_error( $row ) || self::terminal( $row ) || ! self::required( $row ) || (int) $r['reservation_id'] !== (int) $row['id'] || (int) $r['sequence_number'] > (int) $row['quantity'] ) { return Database::error( 'waiver_link', 'This waiver link is no longer actionable.' ); }
			return array( 'waiver' => $w, 'rider' => $r, 'reservation' => $row );
		} );
	}
	public static function invite( $id, $resend = false ) {
		$page_url = WaiverSettings::signing_url(); if ( is_wp_error( $page_url ) ) { return $page_url; }
		$prepared = Database::locked( static function () use ( $id, $resend ) {
			$w = Database::read( 'waivers', $id ); if ( is_wp_error( $w ) ) { return $w; } $row = Reservations::read( $w['reservation_id'] );
			if ( is_wp_error( $row ) || self::terminal( $row ) || ! self::payment_satisfied( $row ) || in_array( $w['status'], array( 'completed', 'exempt' ), true ) ) { return Database::error( 'waiver', 'This waiver cannot be invited in its current state.' ); }
			if ( $w['last_invited_at'] && ( ! $resend || RentalTime::shift( $w['last_invited_at'], 15 ) > Database::now() ) ) { return Database::error( 'waiver_rate', 'An invitation was already attempted. Wait at least 15 minutes before resending.' ); }
			$p = self::providers()[ $w['provider'] ] ?? null; $ready = $p && $p->available() ? $p->configuration( json_decode( $w['provider_config'], true ) ) : Database::error( 'waiver_provider', 'The waiver provider is unavailable.' ); if ( is_wp_error( $ready ) ) { return $ready; }
			$token = bin2hex( random_bytes( 32 ) ); $hash = hash( 'sha256', $token );
			$data = array( 'token_hash' => $hash, 'token_expires_at' => RentalTime::shift( Database::now(), 7 * 24 * 60 ), 'last_invited_at' => Database::now(), 'invite_count' => (int) $w['invite_count'] + 1, 'status' => 'invitation_pending', 'updated_at' => Database::now() );
			if ( 1 !== Database::update( 'waivers', $data, array( 'id' => $id ) ) ) { return Database::retry_error(); }
			$r = Database::read( 'riders', $w['rider_id'] ); if ( is_wp_error( $r ) ) { return $r; }
			return array( 'token' => $token, 'hash' => $hash, 'waiver' => $w, 'row' => $row, 'rider' => $r );
		} );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$url = add_query_arg( 'brp_waiver', $prepared['token'], $page_url );
		$mail = WaiverEmail::compose( $prepared, $url );
		$sent = wp_mail( $prepared['waiver']['signer_email'], $mail['subject'], $mail['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
		if ( $sent ) { $saved = Database::locked( static fn() => Database::update( 'waivers', array( 'status' => 'invitation_sent', 'updated_at' => Database::now() ), array( 'id' => $id, 'token_hash' => $prepared['hash'], 'status' => 'invitation_pending' ) ) ); if ( is_wp_error( $saved ) ) { return $saved; } if ( false === $saved ) { return Database::retry_error(); } }
		return $sent ? true : Database::error( 'waiver_mail', 'Invitation could not be sent. Check WordPress mail delivery and resend after 15 minutes.' );
	}
	public static function invite_pending( $row ) {
		$activated = self::activate_requests( $row['id'] ); if ( is_wp_error( $activated ) ) { return array( $activated->get_error_message() ); }
		$roster = self::roster( $row ); if ( is_wp_error( $roster ) ) { return $roster; } $errors = array();
		foreach ( $roster as $r ) { if ( $r['waiver_id'] && ! $r['last_invited_at'] && ! in_array( $r['waiver_status'], array( 'completed', 'exempt' ), true ) ) { $sent = self::invite( $r['waiver_id'] ); if ( is_wp_error( $sent ) ) { $errors[] = $sent->get_error_message(); } } } return $errors;
	}
	public static function complete( $token, $submission ) {
		if ( ! is_string( $submission ) && ! is_int( $submission ) ) { return Database::error( 'waiver_proof', 'A valid provider submission reference is required.' ); }
		$ctx = self::context( $token ); if ( is_wp_error( $ctx ) ) { return $ctx; } $w = $ctx['waiver']; $provider = self::providers()[ $w['provider'] ] ?? null;
		$proof = $provider ? $provider->verify_completion( $ctx, (string) $submission ) : Database::error( 'waiver_provider', 'Unknown provider.' );
		if ( is_wp_error( $proof ) ) { return $proof; }
		foreach ( array( 'submission', 'signer_name', 'signer_email', 'text_hash' ) as $key ) { if ( ! is_string( $proof[ $key ] ?? null ) ) { return Database::error( 'waiver_proof', 'Provider completion evidence is incomplete.' ); } }
		if ( ! is_array( $proof ) || empty( $proof['submission'] ) || strlen( $proof['submission'] ) > 100 || (string) $submission !== $proof['submission'] || $proof['signer_name'] !== $w['signer_name'] || strtolower( $proof['signer_email'] ) !== $w['signer_email'] || $proof['text_hash'] !== $w['text_hash'] ) { return Database::error( 'waiver_proof', 'Provider completion evidence does not match the waiver request.' ); }
		return Database::public_booking( static fn() => Database::locked( static function () use ( $token, $proof ) {
			$ctx = self::context( $token ); if ( is_wp_error( $ctx ) ) { return $ctx; } $w = $ctx['waiver'];
			$data = array( 'status' => 'completed', 'provider_submission_id' => $proof['submission'], 'completed_at' => Database::now(), 'token_hash' => null, 'token_expires_at' => null, 'updated_at' => Database::now() );
			if ( 1 !== Database::update( 'waivers', $data, array( 'id' => $w['id'], 'status' => $w['status'], 'token_hash' => $w['token_hash'] ) ) ) { return Database::retry_error(); }
			return self::confirm_locked( $ctx['reservation'] );
		} ) );
	}
	public static function exempt( $id, $reason ) {
		if ( ! Settings::can_manage() ) { return Database::error( 'permission', 'You cannot override rider waivers.' ); }
		if ( ! is_string( $reason ) || '' === trim( sanitize_text_field( $reason ) ) || strlen( $reason ) > 500 ) { return Database::error( 'waiver_reason', 'Enter an exemption reason of at most 500 characters.' ); }
		return Database::locked( static function () use ( $id, $reason ) {
			$w = Database::read( 'waivers', $id ); if ( is_wp_error( $w ) ) { return $w; } $row = Reservations::read( $w['reservation_id'] ); if ( is_wp_error( $row ) ) { return $row; }
			if ( self::terminal( $row ) || in_array( $w['status'], array( 'completed', 'exempt' ), true ) ) { return Database::error( 'waiver', 'Existing completed/exempt evidence or terminal reservations cannot be overwritten.' ); }
			$data = array( 'status' => 'exempt', 'override_reason' => sanitize_text_field( $reason ), 'override_user_id' => get_current_user_id(), 'completed_at' => Database::now(), 'token_hash' => null, 'token_expires_at' => null, 'updated_at' => Database::now() );
			if ( 1 !== Database::update( 'waivers', $data, array( 'id' => $id ) ) ) { return Database::retry_error(); } return self::confirm_locked( $row );
		} );
	}
}
