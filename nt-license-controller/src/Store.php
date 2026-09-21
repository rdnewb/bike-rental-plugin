<?php
namespace NTLicenseController;
defined( 'ABSPATH' ) || exit;

final class Store {
 const SCHEMA = '1';
 private static $token = null;
 public static function checked( $value ) { global $wpdb; if ( $wpdb->last_error ) { throw new \RuntimeException( 'Storage failure.' ); } return $value; }
 public static function table( $kind ) {
  global $wpdb;
  if ( ! in_array( $kind, array( 'licenses', 'activations', 'events' ), true ) ) { throw new \InvalidArgumentException( 'Unknown table.' ); }
  return $wpdb->prefix . 'ntlc_' . $kind;
 }
 public static function now() { return gmdate( 'Y-m-d H:i:s' ); }
 public static function error( $code = 'controller_unavailable' ) { return new \WP_Error( $code, 'License operation could not be completed: ' . $code . '.' ); }
 public static function install() {
  global $wpdb;
  if ( self::SCHEMA === get_option( 'ntlc_schema' ) ) { return; }
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  $collate = $wpdb->get_charset_collate(); $l = self::table( 'licenses' ); $a = self::table( 'activations' ); $e = self::table( 'events' );
  dbDelta( "CREATE TABLE $l (
id bigint unsigned NOT NULL AUTO_INCREMENT,
product_slug varchar(100) NOT NULL,
license_key_hash char(64) NOT NULL,
license_key_display_suffix varchar(8) NOT NULL,
customer_name varchar(240) NOT NULL DEFAULT '',
customer_email varchar(254) NOT NULL DEFAULT '',
status varchar(20) NOT NULL,
plan_type varchar(20) NOT NULL,
issued_at datetime NOT NULL,
expires_at datetime NULL,
activation_limit int unsigned NOT NULL DEFAULT 1,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
notes text NOT NULL,
billing_provider varchar(40) NULL,
billing_customer_id varchar(191) NULL,
billing_subscription_id varchar(191) NULL,
PRIMARY KEY  (id),
UNIQUE KEY license_key_hash (license_key_hash),
KEY product_status (product_slug,status)
) ENGINE=InnoDB $collate;" );
  dbDelta( "CREATE TABLE $a (
id bigint unsigned NOT NULL AUTO_INCREMENT,
license_id bigint unsigned NOT NULL,
site_url varchar(2048) NOT NULL,
site_host varchar(253) NOT NULL,
site_hash char(64) NOT NULL,
installation_id char(64) NOT NULL,
status varchar(20) NOT NULL,
activated_at datetime NOT NULL,
last_checked_at datetime NULL,
deactivated_at datetime NULL,
plugin_version varchar(40) NOT NULL DEFAULT '',
wordpress_version varchar(40) NOT NULL DEFAULT '',
php_version varchar(40) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY license_installation (license_id,installation_id),
KEY license_status (license_id,status),
KEY site_hash (site_hash)
) ENGINE=InnoDB $collate;" );
  dbDelta( "CREATE TABLE $e (
id bigint unsigned NOT NULL AUTO_INCREMENT,
license_id bigint unsigned NOT NULL DEFAULT 0,
activation_id bigint unsigned NOT NULL DEFAULT 0,
event_code varchar(60) NOT NULL,
actor_id bigint unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY license_event (license_id,event_code,created_at),
KEY created_at (created_at)
) ENGINE=InnoDB $collate;" );
  if ( self::healthy() ) { update_option( 'ntlc_schema', self::SCHEMA, false ); }
 }
 public static function healthy() {
  global $wpdb;
  foreach ( array( 'licenses', 'activations', 'events' ) as $kind ) {
   $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', self::table( $kind ) ) );
   if ( 'InnoDB' !== $engine ) { return false; }
  }
  return true;
 }
 /** All entitlement mutations share one short controller lock; count/insert is atomic. */
 public static function locked( $callback ) {
  global $wpdb;
  $name = 'ntlc_' . substr( hash( 'sha256', DB_NAME . $wpdb->prefix ), 0, 40 );
  $old = $wpdb->suppress_errors( true ); $locked = false;
  try {
   if ( ! self::healthy() || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $name ) ) ) { return self::error(); }
   $locked = true;
   if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error(); }
   $connection = self::checked( $wpdb->get_var( 'SELECT CONNECTION_ID()' ) );
   self::$token = bin2hex( random_bytes( 16 ) );
   self::checked( $wpdb->query( $wpdb->prepare( 'SET @ntlc_transaction = %s', self::$token ) ) );
   $value = $callback();
   if ( is_wp_error( $value ) || $wpdb->last_error || $connection !== $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) { $wpdb->query( 'ROLLBACK' ); return is_wp_error( $value ) ? $value : self::error(); }
   if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error(); }
   return $value;
  } catch ( \Throwable $e ) { $wpdb->query( 'ROLLBACK' ); return self::error(); }
  finally { self::$token = null; if ( $locked ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); } $wpdb->suppress_errors( $old ); }
 }
 public static function write( $kind, $values, $where = null ) {
  global $wpdb;
  if ( null === self::$token ) { throw new \RuntimeException( 'Transaction required.' ); }
  $columns = array(); $literals = array(); $sets = array();
  foreach ( $values as $key => $value ) {
   $column = $wpdb->prepare( '%i', $key ); $literal = null === $value ? 'NULL' : $wpdb->prepare( '%s', $value );
   $columns[] = $column; $literals[] = $literal; $sets[] = $column . '=' . $literal;
  }
  $guard = $wpdb->prepare( '@ntlc_transaction = %s', self::$token );
  $table = $wpdb->prepare( '%i', self::table( $kind ) );
  if ( null === $where ) { $sql = 'INSERT INTO ' . $table . ' (' . implode( ',', $columns ) . ') SELECT ' . implode( ',', $literals ) . ' WHERE ' . $guard; }
  else { foreach ( $where as $key => $value ) { $guard .= $wpdb->prepare( ' AND %i=%s', $key, $value ); } $sql = 'UPDATE ' . $table . ' SET ' . implode( ',', $sets ) . ' WHERE ' . $guard; }
  // If wpdb reconnects/retries a query, its new connection lacks this token and cannot write outside the transaction.
  $result = $wpdb->query( $sql ); $insert_id = (int) $wpdb->insert_id;
  if ( false === $result ) { throw new \RuntimeException( 'Storage failure.' ); }
  if ( self::$token !== self::checked( $wpdb->get_var( 'SELECT @ntlc_transaction' ) ) ) { throw new \RuntimeException( 'Transaction lost.' ); }
  return null === $where ? $insert_id : $result;
 }
 public static function event( $code, $license = 0, $activation = 0, $daily = false, $actor = 0 ) {
  global $wpdb;
  if ( $daily ) {
   $found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE license_id=%d AND activation_id=%d AND event_code=%s AND created_at>=%s LIMIT 1', self::table( 'events' ), $license, $activation, $code, gmdate( 'Y-m-d 00:00:00' ) ) );
   if ( $wpdb->last_error ) { throw new \RuntimeException( 'Storage failure.' ); }
   if ( $found ) { return; }
  }
  self::write( 'events', array( 'license_id' => $license, 'activation_id' => $activation, 'event_code' => $code, 'actor_id' => $actor, 'created_at' => self::now() ) );
 }
 public static function cleanup() {
  global $wpdb;
  // Bounded pruning; lifetime license and activation records are never pruned.
  $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at<%s ORDER BY id LIMIT 10000', self::table( 'events' ), gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS ) ) );
 }
}
