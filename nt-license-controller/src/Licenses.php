<?php
namespace NTLicenseController;
defined( 'ABSPATH' ) || exit;

final class Licenses {
 const STATUSES = array( 'active', 'expired', 'suspended', 'revoked' );
 const PLANS = array( 'lifetime', 'monthly', 'annual' );
 public static function key( $raw ) {
  if ( ! is_string( $raw ) || strlen( $raw ) > 100 ) { return false; }
  $key = strtoupper( trim( $raw ) );
  return preg_match( '/^NTL1(?:-[A-F0-9]{8}){8}$/D', $key ) ? $key : false;
 }
 public static function site( $raw ) {
  if ( ! is_string( $raw ) || strlen( $raw ) > 2048 || preg_match( '/[\s\\\\]/', $raw ) ) { return false; }
  $url = wp_parse_url( $raw );
  if ( ! is_array( $url ) || strtolower( $url['scheme'] ?? '' ) !== 'https' || isset( $url['user'], $url['pass'] ) || isset( $url['user'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) ) { return false; }
  $host = strtolower( rtrim( $url['host'] ?? '', '.' ) );
  if ( ! filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) { return false; }
  $path = $url['path'] ?? ''; // Preserve subdirectory and path case; remove trailing slash only.
  if ( preg_match( '~(?:^|/)(?:\.|\.\.)(?:/|$)|%|//~', $path ) ) { return false; }
  $port = isset( $url['port'] ) && 443 !== $url['port'] ? ':' . $url['port'] : '';
  return array( 'url' => 'https://' . $host . $port . rtrim( $path, '/' ), 'host' => $host );
 }
 public static function fields( $input, $current = null ) {
  if ( ! is_array( $input ) ) { return Store::error( 'invalid_input' ); }
  $product = $current['product_slug'] ?? ( $input['product_slug'] ?? '' ); $plan = $current['plan_type'] ?? ( $input['plan_type'] ?? '' );
  if ( ! is_string( $product ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,99}$/D', $product ) || ! in_array( $plan, self::PLANS, true ) || ! in_array( $input['status'] ?? '', self::STATUSES, true ) ) { return Store::error( 'invalid_input' ); }
  $limit = $input['activation_limit'] ?? '';
  if ( ! is_scalar( $limit ) || ! preg_match( '/^[0-9]{1,6}$/D', (string) $limit ) ) { return Store::error( 'invalid_activation_limit' ); }
  $expiry = $input['expires_at'] ?? '';
  if ( ! is_string( $expiry ) ) { return Store::error( 'invalid_expiration' ); }
  if ( 'lifetime' === $plan ) { if ( '' !== $expiry ) { return Store::error( 'lifetime_has_no_expiration' ); } $expiry = null; }
  else {
   $date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $expiry, new \DateTimeZone( 'UTC' ) );
   if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $expiry ) { return Store::error( 'expiration_required' ); }
  }
  $out = array( 'product_slug' => $product, 'plan_type' => $plan, 'status' => $input['status'], 'activation_limit' => (int) $limit, 'expires_at' => $expiry, 'updated_at' => Store::now() );
  foreach ( array( 'customer_name' => 240, 'customer_email' => 254, 'notes' => 10000 ) as $key => $max ) {
   $raw = $input[ $key ] ?? '';
   if ( ! is_string( $raw ) || strlen( $raw ) > $max || ( 'customer_email' === $key && $raw && ! is_email( $raw ) ) ) { return Store::error( 'invalid_customer_metadata' ); }
   $out[ $key ] = 'notes' === $key ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
  }
  return $out;
 }
 /** Trusted service seam for future billing adapters. No billing API is exposed. */
 public static function save( $input, $id = 0 ) {
  return Store::locked( static function () use ( $input, $id ) {
   global $wpdb;
   $current = $id ? self::read( $id ) : null;
   if ( $id && ! $current ) { return Store::error( 'license_not_found' ); }
   $v = self::fields( $input, $current ); if ( is_wp_error( $v ) ) { return $v; }
   if ( ! $id ) {
    $key = 'NTL1-' . implode( '-', str_split( strtoupper( bin2hex( random_bytes( 32 ) ) ), 8 ) );
    $v += array( 'license_key_hash' => hash( 'sha256', $key ), 'license_key_display_suffix' => substr( $key, -8 ), 'issued_at' => Store::now(), 'created_at' => Store::now() );
    $id = Store::write( 'licenses', $v ); Store::event( 'license_created', $id, 0, false, get_current_user_id() );
    return array( 'id' => $id, 'key' => $key ); // One-time delivery, not recoverable from the database.
   }
   Store::write( 'licenses', $v, array( 'id' => $id ) );
   Store::event( $current['status'] !== $v['status'] ? 'license_' . $v['status'] : 'license_updated', $id, 0, false, get_current_user_id() );
   if ( $current['status'] !== $v['status'] ) { Store::event( 'admin_status_changed', $id, 0, false, get_current_user_id() ); }
   return array( 'id' => (int) $id );
  } );
 }
 public static function read( $id ) { global $wpdb; return Store::checked( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Store::table( 'licenses' ), $id ), ARRAY_A ) ); }
 public static function used( $id ) { global $wpdb; return (int) Store::checked( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE license_id=%d AND status='active'", Store::table( 'activations' ), $id ) ) ); }
 public static function deactivate( $id ) {
  return Store::locked( static function () use ( $id ) {
   global $wpdb;
   $a = Store::checked( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Store::table( 'activations' ), $id ), ARRAY_A ) );
   if ( ! $a ) { return Store::error( 'installation_not_activated' ); }
   self::end_activation( $a, get_current_user_id() ); return array( 'id' => (int) $a['license_id'] );
  } );
 }
 private static function end_activation( $a, $actor = 0 ) {
  if ( 'active' !== $a['status'] ) { return; }
  Store::write( 'activations', array( 'status' => 'inactive', 'deactivated_at' => Store::now(), 'updated_at' => Store::now() ), array( 'id' => $a['id'] ) );
  Store::event( 'deactivated', $a['license_id'], $a['id'], false, $actor );
 }
 public static function request( $input, $action ) {
  if ( ! is_array( $input ) || ! in_array( $action, array( 'activate', 'validate', 'deactivate' ), true ) ) { return Store::error( 'invalid_request' ); }
  $key = self::key( $input['license_key'] ?? null ); $site = self::site( $input['site_url'] ?? null ); $installation = $input['installation_id'] ?? null; $product = $input['product_slug'] ?? null;
  if ( ! $key || ! $site || ! is_string( $installation ) || ! preg_match( '/^[a-f0-9]{64}$/D', $installation ) || ! is_string( $product ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,99}$/D', $product ) ) { return Store::error( 'invalid_request' ); }
  $versions = array(); foreach ( array( 'plugin_version', 'wordpress_version', 'php_version' ) as $field ) {
   $v = $input[ $field ] ?? ''; if ( ! is_string( $v ) || strlen( $v ) > 40 || ! preg_match( '/^[a-zA-Z0-9._+ -]*$/D', $v ) ) { return Store::error( 'invalid_request' ); } $versions[ $field ] = $v;
  }
  return Store::locked( static function () use ( $key, $site, $installation, $product, $action, $versions ) {
   global $wpdb;
   $l = Store::checked( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE license_key_hash=%s FOR UPDATE', Store::table( 'licenses' ), hash( 'sha256', $key ) ), ARRAY_A ) );
   $response = array( 'valid' => false, 'status' => 'invalid', 'plan_type' => null, 'expires_at' => null, 'activation_limit' => null, 'activations_used' => 0, 'controller_timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'product_slug' => $product, 'installation_id' => $installation, 'site_url' => $site['url'], 'code' => 'license_not_found', 'update_entitlement' => false );
   if ( ! $l ) { return $response; } // Never persist guessed keys/IPs in event logs.
   if ( $l['product_slug'] !== $product ) { $response['code'] = 'product_mismatch'; return $response; }
   $a = Store::checked( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE license_id=%d AND installation_id=%s', Store::table( 'activations' ), $l['id'], $installation ), ARRAY_A ) );
   $response['plan_type'] = $l['plan_type']; $response['expires_at'] = $l['expires_at'] ? str_replace( ' ', 'T', $l['expires_at'] ) . 'Z' : null; $response['activation_limit'] = (int) $l['activation_limit'];
   if ( 'active' === $l['status'] && $l['expires_at'] && $l['expires_at'] <= Store::now() ) {
    $l['status'] = 'expired'; Store::write( 'licenses', array( 'status' => 'expired', 'updated_at' => Store::now() ), array( 'id' => $l['id'] ) ); Store::event( 'license_expired', $l['id'] );
   }
   $response['status'] = $l['status']; $response['activations_used'] = self::used( $l['id'] );
   $site_hash = hash( 'sha256', $site['url'] );
   if ( $a && 'active' === $a['status'] && ! hash_equals( $a['site_hash'], $site_hash ) ) { $response['code'] = 'site_mismatch'; }
   elseif ( 'deactivate' === $action ) {
    if ( $a ) { self::end_activation( $a ); }
    $response['status'] = 'inactive'; $response['code'] = 'installation_deactivated'; $response['activations_used'] = self::used( $l['id'] ); return $response;
   }
   elseif ( 'active' !== $l['status'] ) { $response['code'] = 'license_' . $l['status']; }
   elseif ( 'activate' === $action && ( ! $a || 'active' !== $a['status'] ) && (int) $l['activation_limit'] > 0 && $response['activations_used'] >= (int) $l['activation_limit'] ) { $response['code'] = 'activation_limit_reached'; }
   elseif ( 'validate' === $action && ( ! $a || 'active' !== $a['status'] ) ) { $response['code'] = 'installation_not_activated'; }
   else {
    if ( 'activate' === $action && ( ! $a || 'active' !== $a['status'] ) ) {
     $values = $versions + array( 'license_id' => $l['id'], 'site_url' => $site['url'], 'site_host' => $site['host'], 'site_hash' => $site_hash, 'installation_id' => $installation, 'status' => 'active', 'activated_at' => Store::now(), 'deactivated_at' => null, 'updated_at' => Store::now() );
     if ( $a ) { Store::write( 'activations', $values, array( 'id' => $a['id'] ) ); }
     else { $values['created_at'] = Store::now(); $a = array( 'id' => Store::write( 'activations', $values ) ); }
     Store::event( 'activated', $l['id'], $a['id'] );
    }
    // A reduced limit does not silently evict active sites. Admin chooses which to deactivate.
    Store::write( 'activations', $versions + array( 'last_checked_at' => Store::now(), 'updated_at' => Store::now() ), array( 'id' => $a['id'] ) );
    Store::event( 'validation_success', $l['id'], $a['id'], true );
    $response['valid'] = true; $response['code'] = 'license_valid'; $response['update_entitlement'] = true; $response['activations_used'] = self::used( $l['id'] ); return $response;
   }
   Store::event( $response['code'], $l['id'], $a['id'] ?? 0, true );
   Store::event( 'activate' === $action ? 'activation_denied' : 'validation_failure', $l['id'], $a['id'] ?? 0, true );
   return $response;
  } );
 }
}
