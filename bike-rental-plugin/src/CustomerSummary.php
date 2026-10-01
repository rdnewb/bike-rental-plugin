<?php
/** Customer-only date presentation. Never mutates operational order metadata. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class CustomerSummary {
 private static $email_audience = array();
 const TIMING_KEYS = array( 'Rental Start', 'Rental End', 'Delivery Time' );
 public static function register_hooks() {
  add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( self::class, 'order_item_meta' ), 20, 2 );
  add_action( 'woocommerce_email_before_order_table', array( self::class, 'email_begin' ), 0, 4 );
  add_action( 'woocommerce_email_after_order_table', array( self::class, 'email_end' ), PHP_INT_MAX, 4 );
 }
 public static function email_begin( $order, $sent_to_admin, $plain_text, $email ) { self::$email_audience[] = (bool) $sent_to_admin; }
 public static function email_end( $order, $sent_to_admin, $plain_text, $email ) { array_pop( self::$email_audience ); }
 private static function staff_output() {
  return self::$email_audience ? end( self::$email_audience ) : ( is_admin() && Settings::can_manage() );
 }
 public static function date( $local ) {
  if ( ! is_string( $local ) ) { return ''; }
  $day = substr( $local, 0, 10 );
  $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day, new \DateTimeZone( 'UTC' ) );
  return $date && $date->format( 'Y-m-d' ) === $day ? $date->format( 'F j, Y' ) : '';
 }
 public static function dates( $start, $end ) {
  $first = self::date( $start ); $last = self::date( $end );
  if ( ! $first || ! $last ) { return ''; }
  return $first === $last ? $first : $first . ' – ' . $last;
 }
 public static function details( $row ) {
  $details = Checkout::details( $row );
  foreach ( self::TIMING_KEYS as $key ) { unset( $details[$key] ); }
  $snapshot = json_decode( $row['snapshot'], true );
  $details['Rental Date'] = self::dates( $snapshot['local_start'] ?? '', $snapshot['local_end'] ?? '' );
  $location = RentalLocation::text( RentalLocation::from_row( $row ) );
  if ( $location ) { $details['Drop Off / Pick Up Location'] = $location; }
  return $details;
 }
 public static function order_item_meta( $formatted, $item ) {
  if ( self::staff_output() || ! $item->get_meta( 'Reservation Reference' ) ) { return $formatted; }
  $order = $item->get_order();
  if ( ! $order || ! $order->get_meta( '_brp_reservation_id' ) ) { return $formatted; }
  foreach ( $formatted as $id => $meta ) { if ( in_array( $meta->key, self::TIMING_KEYS, true ) ) { unset( $formatted[$id] ); } }
  // Use the agreed order snapshot, not a subsequently edited reservation.
  $snapshot = $order->get_meta( '_brp_snapshot' );
  $dates = is_array( $snapshot ) ? self::dates( $snapshot['local_start'] ?? '', $snapshot['local_end'] ?? '' ) : '';
  if ( $dates ) { $formatted['brp_rental_date'] = (object) array( 'key' => 'Rental Date', 'value' => $dates, 'display_key' => 'Rental Date', 'display_value' => esc_html( $dates ) ); }
  return $formatted;
 }
}
