<?php
/** Cached licensing only. Rental records, payments and existing workflows never call the controller. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class License {
 const OPTION = 'brp_license';
 const INSTALLATION = 'brp_license_installation';
 const HOOK = 'brp_license_daily';
 const PRODUCT = 'bike-rental-plugin';
 const PUBLIC_MESSAGE = 'Online booking is temporarily unavailable. Please contact the rental provider.';
 const GRACE = 7 * DAY_IN_SECONDS;
 public static function hooks() {
  add_action( 'init', array( self::class, 'schedule' ), 40 );
  add_action( self::HOOK, array( self::class, 'scheduled' ) );
  add_action( 'admin_post_brp_license', array( LicenseAdmin::class, 'handle' ) );
  add_action( 'admin_notices', array( LicenseAdmin::class, 'notice' ) );
 }
 public static function schedule() { if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK ); } }
 public static function scheduled() {
  $s = self::state(); if ( empty( $s['key_cipher'] ) ) { return; }
  if ( ! empty( $s['deactivation_pending'] ) ) { self::request( 'deactivate' ); }
  elseif ( ! empty( $s['activated'] ) ) { self::request( 'validate' ); }
 }
 public static function state() { $s = get_option( self::OPTION, array() ); return is_array( $s ) ? $s : array(); }
 private static function save( $s ) { update_option( self::OPTION, $s, false ); }
 public static function installation() {
  $id = get_option( self::INSTALLATION, '' );
  if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{64}$/D', $id ) ) {
   $id = bin2hex( random_bytes( 32 ) ); add_option( self::INSTALLATION, $id, '', false ); $id = get_option( self::INSTALLATION );
  } return $id;
 }
 public static function key_format( $key ) { return is_string( $key ) && preg_match( '/^NTL1(?:-[A-F0-9]{8}){8}$/D', $key ); }
 /** Local encryption protects DB-only disclosures, not an administrator controlling PHP/config. */
 public static function encrypt( $key ) {
  if ( ! function_exists( 'sodium_crypto_secretbox' ) ) { return new \WP_Error( 'crypto_unavailable', 'PHP sodium is required to store a license key.' ); }
  $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
  return base64_encode( $nonce . sodium_crypto_secretbox( $key, $nonce, hash( 'sha256', wp_salt( 'auth' ) . '|brp-license-v1', true ) ) );
 }
 private static function decrypt( $cipher ) {
  if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || ! is_string( $cipher ) ) { return false; }
  $bytes = base64_decode( $cipher, true ); if ( false === $bytes || strlen( $bytes ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) { return false; }
  try { return sodium_crypto_secretbox_open( substr( $bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), hash( 'sha256', wp_salt( 'auth' ) . '|brp-license-v1', true ) ); } catch ( \Throwable $e ) { return false; }
 }
 public static function site() {
  $raw = home_url( '/' ); $u = wp_parse_url( $raw );
  if ( ! is_array( $u ) || strtolower( $u['scheme'] ?? '' ) !== 'https' || isset( $u['user'] ) || isset( $u['query'] ) || isset( $u['fragment'] ) || preg_match( '/[\s\\\\]/', $raw ) ) { return false; }
  $host = strtolower( rtrim( $u['host'] ?? '', '.' ) ); $path = $u['path'] ?? '';
  if ( ! filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) || preg_match( '~(?:^|/)(?:\.|\.\.)(?:/|$)|%|//~', $path ) ) { return false; }
  return 'https://' . $host . ( isset( $u['port'] ) && 443 !== $u['port'] ? ':' . $u['port'] : '' ) . rtrim( $path, '/' );
 }
 public static function endpoint() {
  $url = defined( 'BRP_LICENSE_CONTROLLER_URL' ) ? BRP_LICENSE_CONTROLLER_URL : 'https://newbytechnologies.com/wp-json/nt-license/v1';
  return is_string( $url ) && 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && ! wp_parse_url( $url, PHP_URL_USER ) && ! wp_parse_url( $url, PHP_URL_QUERY ) && ! wp_parse_url( $url, PHP_URL_FRAGMENT ) ? rtrim( $url, '/' ) : false;
 }
 public static function enforced() {
  // Enforcement is on unless explicitly overridden in wp-config.php.
  return defined( 'BRP_LICENSE_ENFORCE' ) ? (bool) BRP_LICENSE_ENFORCE : true;
 }
 public static function entitlement() {
  $s = self::state(); $now = time();
  if ( empty( $s['activated'] ) || empty( $s['valid'] ) || ( $s['site_url'] ?? '' ) !== self::site() || ( $s['installation_id'] ?? '' ) !== get_option( self::INSTALLATION ) ) { return 'invalid'; }
  if ( ! empty( $s['expires_at'] ) && strtotime( $s['expires_at'] ) <= $now ) { return 'expired'; }
  if ( ( $s['grace_until'] ?? 0 ) <= $now ) { return 'invalid'; }
  if ( ( $s['connection'] ?? '' ) !== 'connected' || ( $s['next_check_at'] ?? 0 ) <= $now ) { return 'grace'; }
  return 'valid';
 }
 public static function allows_new() { return ! self::enforced() || in_array( self::entitlement(), array( 'valid', 'grace' ), true ); }
 public static function guard() { return self::allows_new() ? true : new \WP_Error( 'brp_booking_disabled', self::PUBLIC_MESSAGE ); }
 /** Serialize background and manual updates so a slow validation cannot undo deactivation. */
 public static function request( $action, $new_key = '' ) {
  global $wpdb;
  $lock = 'brp_license_' . substr( hash( 'sha256', DB_NAME . $wpdb->prefix ), 0, 40 );
  if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,2)', $lock ) ) ) { return new \WP_Error( 'license_busy', 'A license check is already running. Try again shortly.' ); }
  try { wp_cache_delete( self::OPTION, 'options' ); return self::perform( $action, $new_key ); }
  catch ( \Throwable $e ) { return new \WP_Error( 'license_unavailable', 'License check could not be completed. Cached entitlement was retained.' ); }
  finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
 }
 private static function perform( $action, $new_key ) {
  if ( ! in_array( $action, array( 'activate', 'validate', 'deactivate' ), true ) ) { return new \WP_Error( 'license_action', 'Invalid license action.' ); }
  $s = self::state(); $key = self::decrypt( $s['key_cipher'] ?? '' );
  if ( 'validate' === $action && ! empty( $s['deactivation_pending'] ) ) { return new \WP_Error( 'deactivation_pending', 'Retry Deactivate License to finish the pending deactivation first.' ); }
  if ( 'activate' !== $action && $new_key ) { return new \WP_Error( 'license_action', 'Use Activate License when entering a key; leave the field blank for other actions.' ); }
  if ( $new_key ) {
   if ( ! is_string( $new_key ) || ! self::key_format( strtoupper( trim( $new_key ) ) ) ) { return new \WP_Error( 'license_key', 'Enter a valid NT license key.' ); }
   $new_key = strtoupper( trim( $new_key ) );
   if ( ! $key && ! empty( $s['key_fingerprint'] ) && hash_equals( $s['key_fingerprint'], hash( 'sha256', $new_key ) ) ) {
    $cipher = self::encrypt( $new_key ); if ( is_wp_error( $cipher ) ) { return $cipher; }
    $s['key_cipher'] = $cipher; $key = $new_key; // Re-enter the original key after WordPress salt rotation.
   }
   if ( $new_key !== $key ) {
    if ( ! empty( $s['activated'] ) || ! empty( $s['deactivation_pending'] ) ) { return new \WP_Error( 'license_deactivate_first', 'Deactivate the current key successfully before replacing it.' ); }
    $cipher = self::encrypt( $new_key ); if ( is_wp_error( $cipher ) ) { return $cipher; }
    $s = array( 'key_cipher' => $cipher, 'key_fingerprint' => hash( 'sha256', $new_key ), 'key_suffix' => substr( $new_key, -8 ), 'status' => 'inactive', 'valid' => false, 'activated' => false ); $key = $new_key;
   }
  }
  if ( ! $key ) { return new \WP_Error( 'license_key', 'Enter the license key again. It is missing or cannot be decrypted with the current WordPress salts.' ); }
  $site = self::site(); $endpoint = self::endpoint();
  if ( ! $site || ! $endpoint ) { return new \WP_Error( 'license_https', 'Configure valid HTTPS site and controller URLs before licensing.' ); }
  $id = self::installation(); $now = time();
  // Deactivation always clears local entitlement, even if the server must be retried later.
  if ( 'deactivate' === $action ) { $s['valid'] = false; $s['activated'] = false; $s['grace_until'] = 0; $s['deactivation_pending'] = true; $s['status'] = 'inactive'; }
  $s['last_checked_at'] = $now; $s['next_check_at'] = $now + DAY_IN_SECONDS;
  self::save( $s );
  $payload = array( 'license_key' => $key, 'product_slug' => self::PRODUCT, 'installation_id' => $id, 'site_url' => $site, 'plugin_version' => Plugin::VERSION, 'wordpress_version' => get_bloginfo( 'version' ), 'php_version' => PHP_VERSION );
  $remote = wp_safe_remote_post( $endpoint . '/' . $action, array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), 'body' => wp_json_encode( $payload ), 'limit_response_size' => 16384 ) );
  $body = is_wp_error( $remote ) ? null : json_decode( wp_remote_retrieve_body( $remote ), true );
  if ( is_wp_error( $remote ) || 200 !== wp_remote_retrieve_response_code( $remote ) || ! self::valid_response( $body, $id, $site ) ) {
   $s['connection'] = 'unreachable'; $s['code'] = 'controller_unreachable'; self::save( $s );
   return new \WP_Error( 'controller_unreachable', 'License server could not be reached. Previously valid entitlement is retained only within its grace period and expiration.' );
  }
  $s['connection'] = 'connected'; $s['code'] = $body['code']; $s['status'] = $body['status']; $s['valid'] = $body['valid'];
  foreach ( array( 'plan_type', 'expires_at', 'activation_limit', 'activations_used', 'controller_timestamp', 'update_entitlement' ) as $field ) { $s[ $field ] = $body[ $field ]; }
  $s['site_url'] = $site; $s['installation_id'] = $id;
  if ( 'deactivate' === $action ) { $s['activated'] = false; $s['valid'] = false; $s['grace_until'] = 0; $s['deactivation_pending'] = 'installation_deactivated' !== $body['code']; }
  elseif ( $body['valid'] ) { $s['activated'] = true; $s['deactivation_pending'] = false; $s['last_valid_at'] = $now; $s['grace_until'] = $now + self::GRACE; }
  else { $s['grace_until'] = 0; } // Definitive invalidity never gets outage grace.
  self::save( $s ); return $body;
 }
 private static function valid_response( $v, $id, $site ) {
  if ( ! is_array( $v ) || ! is_bool( $v['valid'] ?? null ) || ( $v['product_slug'] ?? null ) !== self::PRODUCT || ( $v['installation_id'] ?? null ) !== $id || ( $v['site_url'] ?? null ) !== $site ) { return false; }
  $codes = array( 'license_valid', 'license_not_found', 'product_mismatch', 'license_expired', 'license_revoked', 'license_suspended', 'activation_limit_reached', 'installation_not_activated', 'installation_deactivated', 'site_mismatch' );
  if ( ! in_array( $v['code'] ?? null, $codes, true ) || ! in_array( $v['status'] ?? null, array( 'active', 'inactive', 'invalid', 'expired', 'suspended', 'revoked' ), true ) || ! in_array( $v['plan_type'] ?? null, array( null, 'lifetime', 'monthly', 'annual' ), true ) || ! is_bool( $v['update_entitlement'] ?? null ) || ! is_int( $v['activations_used'] ?? null ) || $v['activations_used'] < 0 || ! array_key_exists( 'activation_limit', $v ) || ( null !== $v['activation_limit'] && ( ! is_int( $v['activation_limit'] ) || $v['activation_limit'] < 0 ) ) ) { return false; }
  foreach ( array( 'expires_at', 'controller_timestamp' ) as $field ) {
   if ( ! array_key_exists( $field, $v ) ) { return false; }
   if ( 'expires_at' === $field && null === $v[ $field ] ) { continue; }
   if ( ! is_string( $v[ $field ] ) ) { return false; }
   $d = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $v[ $field ], new \DateTimeZone( 'UTC' ) );
   if ( ! $d || $d->format( 'Y-m-d\TH:i:s\Z' ) !== $v[ $field ] ) { return false; }
  }
  if ( abs( time() - strtotime( $v['controller_timestamp'] ) ) > 15 * MINUTE_IN_SECONDS ) { return false; }
  return ! $v['valid'] || ( 'active' === $v['status'] && 'license_valid' === $v['code'] && null !== $v['plan_type'] && null !== $v['activation_limit'] && ( 'lifetime' === $v['plan_type'] ? null === $v['expires_at'] : null !== $v['expires_at'] && strtotime( $v['expires_at'] ) > time() ) );
 }
}
