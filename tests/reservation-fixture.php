<?php
/** Historical-state fixtures only. Not loaded or packaged by the plugin. */
if ( PHP_SAPI !== 'cli' || getenv( 'BRP_ALLOW_DISPOSABLE_TESTS' ) !== '1' || ! defined( 'DB_NAME' ) || DB_NAME !== 'brp_m3_disposable' || DB_HOST !== '127.0.0.1:33316' || $GLOBALS['wpdb']->prefix !== 'm3_' ) { throw new RuntimeException( 'Disposable reservation fixture required.' ); }

final class BrpReservationFixture {
 public static function create( $input ) {
  if ( is_array( $input ) && 'hold' === ( $input['status'] ?? 'hold' ) ) {
   return self::create_hold( $input, $input['request_key'] ?? wp_generate_uuid4(), hash( 'sha256', 'admin:' . get_current_user_id() ) );
  }
  if ( is_array( $input ) && isset( $input['request_key'] ) ) { return self::invoke( 'create_request', $input, $input['request_key'], hash( 'sha256', 'admin:' . get_current_user_id() ) ); }
  return self::invoke( 'create_record', $input );
 }
 public static function create_hold( $input, $key, $session ) {
  if ( is_array( $input ) ) { $input['status'] = 'hold'; }
  return self::invoke( 'create_request', $input, $key, $session );
 }
 private static function invoke( $method, ...$args ) {
  // Exercise unchanged allocation/validation internals without shipping an admin creation API.
  return ( new ReflectionMethod( \BikeRentalPlugin\Reservations::class, $method ) )->invoke( null, ...$args );
 }
}
