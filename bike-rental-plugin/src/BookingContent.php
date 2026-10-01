<?php
/** Safe customer-facing booking text, without executing page shortcodes or dynamic blocks. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;
final class BookingContent {
 public static function clean( $text ) {
  if ( ! is_string( $text ) ) { return ''; }
  $text = preg_replace( '~<(script|style|iframe|object)\b[^>]*>.*?</\1\s*>~is', '', $text );
  return wp_kses( $text, array( 'p' => array(), 'br' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'a' => array( 'href' => true, 'title' => true ), 'ul' => array(), 'ol' => array(), 'li' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'blockquote' => array(), 'table' => array(), 'thead' => array(), 'tbody' => array(), 'tr' => array(), 'th' => array( 'scope' => true ), 'td' => array(), 'div' => array() ) );
 }
 public static function policy() {
  if ( ! function_exists( 'wc_get_page_id' ) ) { return ''; }
  $id = wc_get_page_id( 'refund_returns' ); if ( $id <= 0 ) { return ''; }
  $page = get_post( $id );
  if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status || $page->post_password ) { return ''; }
  return self::clean( wpautop( strip_shortcodes( $page->post_content ) ) );
 }
 public static function disclaimer() { return self::clean( Settings::get()['dropoff_disclaimer'] ?? '' ); }
}
