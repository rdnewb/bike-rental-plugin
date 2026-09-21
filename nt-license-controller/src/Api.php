<?php
namespace NTLicenseController;
defined( 'ABSPATH' ) || exit;

final class Api {
 public static function settings() { return array_replace( array( 'ip_limit' => 120, 'key_limit' => 60 ), (array) get_option( 'ntlc_settings', array() ) ); }
 public static function routes() {
  foreach ( array( 'activate', 'validate', 'deactivate' ) as $action ) {
   register_rest_route( 'nt-license/v1', '/' . $action, array( 'methods' => 'POST', 'permission_callback' => static fn() => is_ssl() ? true : new \WP_Error( 'https_required', 'HTTPS is required.', array( 'status' => 403 ) ), 'callback' => array( self::class, 'handle' ) ) );
  }
 }
 /** Hourly counters serialized by DB lock, including persistent object-cache installations. */
 public static function rate( $identity, $limit ) {
  global $wpdb;
  $hash = hash( 'sha256', $identity . gmdate( 'YmdH' ) ); $lock = 'ntlc_rate_' . substr( $hash, 0, 40 ); $name = 'ntlc_r_' . $hash;
  if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,2)', $lock ) ) ) { return false; }
  try { $count = (int) get_transient( $name ); if ( $count >= $limit ) { return false; } return (bool) set_transient( $name, $count + 1, HOUR_IN_SECONDS + 60 ); }
  finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
 }
 public static function handle( $request ) {
  global $wpdb; $old = $wpdb->suppress_errors( true );
  try {
   $settings = self::settings(); $input = $request->get_json_params();
   if ( ! is_ssl() ) { return self::reply( Store::error( 'https_required' ), 403 ); }
   if ( ! self::rate( 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ), max( 10, (int) $settings['ip_limit'] ) ) ) { return self::reply( Store::error( 'rate_limited' ), 429 ); }
   if ( strlen( $request->get_body() ) > 8192 || ! is_array( $input ) ) { return self::reply( Store::error( 'invalid_request' ), 400 ); }
   $key = Licenses::key( $input['license_key'] ?? null );
   if ( $key && ! self::rate( 'key:' . hash( 'sha256', $key ), max( 10, (int) $settings['key_limit'] ) ) ) { return self::reply( Store::error( 'rate_limited' ), 429 ); }
   $result = Licenses::request( $input, basename( $request->get_route() ) );
   return self::reply( $result, is_wp_error( $result ) ? ( 'controller_unavailable' === $result->get_error_code() ? 503 : 400 ) : 200 );
  } catch ( \Throwable $e ) { return self::reply( Store::error(), 503 ); }
  finally { $wpdb->suppress_errors( $old ); }
 }
 private static function reply( $value, $status ) {
  if ( is_wp_error( $value ) ) { $value = array( 'valid' => false, 'status' => 'error', 'code' => $value->get_error_code() ); }
  $response = new \WP_REST_Response( $value, $status ); $response->header( 'Cache-Control', 'no-store, private' );
  if ( 429 === $status ) { $response->header( 'Retry-After', '3600' ); }
  return $response;
 }
}
