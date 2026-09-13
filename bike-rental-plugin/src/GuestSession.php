<?php
/** Signed HttpOnly guest identity plus session-bound CSRF token. No PHP sessions. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class GuestSession {
	public static function cookie_name() { return 'brp_guest_' . get_current_blog_id(); }
	public static function identity() {
		$value = $_COOKIE[ self::cookie_name() ] ?? '';
		if ( ! is_string( $value ) || ! preg_match( '/\A([a-f0-9]{64})\.([0-9]{10})\.([a-f0-9]{64})\z/', $value, $parts ) || (int) $parts[2] <= time() || ! hash_equals( hash_hmac( 'sha256', $parts[1] . '.' . $parts[2], wp_salt( 'auth' ) ), $parts[3] ) ) { return null; }
		return array( 'hash' => hash_hmac( 'sha256', $parts[1], wp_salt( 'secure_auth' ) ), 'token' => hash_hmac( 'sha256', 'csrf:' . $value, wp_salt( 'nonce' ) ) );
	}
	public static function start() {
		$identity = self::identity();
		if ( $identity ) { return $identity; }
		if ( headers_sent() ) { return new \WP_Error( 'brp_session', 'Please reload to enable your secure booking session.' ); }
		$expiry = time() + DAY_IN_SECONDS;
		$value = bin2hex( random_bytes( 32 ) ) . '.' . $expiry;
		$value .= '.' . hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
		if ( ! setcookie( self::cookie_name(), $value, array( 'expires' => $expiry, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) ) ) { return new \WP_Error( 'brp_session', 'Please enable cookies and reload.' ); }
		$_COOKIE[ self::cookie_name() ] = $value;
		return self::identity();
	}
	public static function permission( $request, $require_token = true ) {
		$origin = $request->get_header( 'origin' );
		$home = wp_parse_url( home_url() );
		$expected = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		if ( $origin !== $expected || '1' !== $request->get_header( 'x-brp-request' ) ) { return new \WP_Error( 'brp_session', 'Please use the booking form on this website.', array( 'status' => 403 ) ); }
		if ( ! $require_token ) { return true; }
		$identity = self::identity();
		return $identity && hash_equals( $identity['token'], $request->get_header( 'x-brp-token' ) ) ? true : new \WP_Error( 'brp_session', 'Your booking session expired. Please reload.', array( 'status' => 403 ) );
	}
	/** Advisory abuse limit only. Inventory correctness never depends on transients. */
	public static function limit( $mutation = false ) {
		$key = 'brp_rate_' . hash_hmac( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) . ':' . (int) $mutation . ':' . (int) floor( time() / 60 ), wp_salt( 'auth' ) );
		$count = (int) get_transient( $key );
		if ( $count >= ( $mutation ? 20 : 90 ) ) { return new \WP_Error( 'brp_rate', 'Please wait a minute before trying again.', array( 'status' => 429 ) ); }
		set_transient( $key, $count + 1, 120 );
		return true;
	}
}
