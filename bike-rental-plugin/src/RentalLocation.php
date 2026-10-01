<?php
/** Structured service address. Billing/shipping remain owned by WooCommerce. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class RentalLocation {
 const META = '_brp_rental_location';
 public static function labels() { return array( 'name' => 'Name', 'company' => 'Company / Property Name', 'address_1' => 'Address Line 1', 'address_2' => 'Address Line 2', 'city' => 'City', 'state' => 'State/Province', 'postcode' => 'Postal Code', 'country' => 'Country', 'notes' => 'Location / Delivery Notes' ); }
 public static function rules( $country ) {
  $fields = WC()->countries->get_address_fields( $country, 'rental_' );
  $rules = array();
  foreach ( array( 'state', 'postcode' ) as $field ) {
   $spec = $fields[ 'rental_' . $field ] ?? array();
   $rules[$field] = array( 'required' => ! empty( $spec['required'] ) && empty( $spec['hidden'] ), 'hidden' => ! empty( $spec['hidden'] ) );
  }
  $rules['states'] = WC()->countries->get_states( $country ) ?: array();
  return $rules;
 }
 public static function validate( $input ) {
  if ( ! is_array( $input ) || ! function_exists( 'WC' ) || ! WC()->countries ) { return self::error( 'Enter the Drop Off / Pick Up Location.' ); }
  if ( array_diff( array_keys( $input ), array_keys( self::labels() ) ) ) { return self::error( 'Please check the Drop Off / Pick Up Location fields.' ); }
  $values = array();
  foreach ( self::labels() as $key => $label ) {
   $value = $input[$key] ?? '';
   if ( ! is_string( $value ) || strlen( $value ) > ( 'notes' === $key ? 2000 : 240 ) ) { return self::error( $label . ' is invalid or too long.' ); }
   $values[$key] = 'notes' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
  }
  $values['country'] = strtoupper( $values['country'] );
  if ( ! isset( WC()->countries->get_countries()[$values['country']] ) ) { return self::error( 'Select a valid country for the Drop Off / Pick Up Location.' ); }
  $rules = self::rules( $values['country'] );
  foreach ( array( 'name', 'address_1', 'city', 'state', 'postcode' ) as $key ) {
   if ( ( ! isset( $rules[$key] ) || $rules[$key]['required'] ) && '' === $values[$key] ) { return self::error( self::labels()[$key] . ' is required for the Drop Off / Pick Up Location.' ); }
   if ( ! empty( $rules[$key]['hidden'] ) ) { $values[$key] = ''; }
  }
  if ( $values['state'] && $rules['states'] ) {
   $values['state'] = strtoupper( $values['state'] );
   if ( ! isset( $rules['states'][$values['state']] ) ) { return self::error( 'Select a valid state/province for the Drop Off / Pick Up Location.' ); }
  }
  if ( $values['postcode'] ) {
   $values['postcode'] = wc_format_postcode( $values['postcode'], $values['country'] );
   if ( ! \WC_Validation::is_postcode( $values['postcode'], $values['country'] ) ) { return self::error( 'Enter a valid postal code for the Drop Off / Pick Up Location.' ); }
  }
  return $values;
 }
 private static function error( $message ) { return new \WP_Error( 'brp_location', $message, array( 'status' => 400 ) ); }
 public static function from_row( $row ) { $s = json_decode( $row['snapshot'] ?? '{}', true ); return is_array( $s['rental_location'] ?? null ) ? $s['rental_location'] : array(); }
 public static function text( $location ) {
  if ( ! is_array( $location ) || empty( $location['address_1'] ) ) { return ''; }
  $lines = array();
  foreach ( array( 'name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $key ) {
   $value = $location[$key] ?? ''; if ( ! is_string( $value ) || '' === $value ) { continue; }
   if ( function_exists( 'WC' ) && WC()->countries ) {
    if ( 'country' === $key ) { $value = WC()->countries->get_countries()[$value] ?? $value; }
    if ( 'state' === $key ) { $value = ( WC()->countries->get_states( $location['country'] ?? '' ) ?: array() )[$value] ?? $value; }
   }
   $lines[] = $value;
  }
  if ( ! empty( $location['notes'] ) && is_string( $location['notes'] ) ) { $lines[] = 'Location / Delivery Notes: ' . $location['notes']; }
  return implode( "\n", $lines );
 }
 public static function html( $location ) { $text = self::text( $location ); return $text ? '<section class="brp-rental-location"><h3>Drop Off / Pick Up Location</h3><p>' . nl2br( esc_html( $text ) ) . '</p></section>' : ''; }
 public static function order_admin( $order ) { if ( Settings::can_manage() ) { echo self::html( $order->get_meta( self::META ) ); } }
 public static function order_customer( $id ) {
  $order = wc_get_order( $id );
  if ( WaiverUI::order_access( $order, wp_unslash( $_GET['key'] ?? '' ) ) ) { echo self::html( $order->get_meta( self::META ) ); }
 }
 public static function email( $order, $sent_to_admin, $plain_text, $email ) {
  $location = $order->get_meta( self::META ); $text = self::text( $location );
  if ( ! $text ) { return; }
  echo $plain_text ? "\nDrop Off / Pick Up Location\n" . $text . "\n" : self::html( $location );
 }
 public static function form( $uid ) {
  if ( ! function_exists( 'WC' ) || ! WC()->countries ) { return; }
  $countries = WC()->countries->get_countries(); $rules = array();
  foreach ( $countries as $code => $name ) { $rules[$code] = self::rules( $code ); }
  echo '<fieldset class="brp-location" data-rules="' . esc_attr( wp_json_encode( $rules ) ) . '"><legend>Drop Off / Pick Up Location</legend><p>Where the bikes will be delivered and picked up. Billing details are collected separately at checkout.</p><div class="brp-location-grid">';
  foreach ( array( 'country', 'name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'notes' ) as $key ) {
   $id = $uid . '-location-' . $key; $optional = in_array( $key, array( 'company', 'address_2', 'notes' ), true );
   echo '<label class="brp-location-field" data-location-field="' . esc_attr( $key ) . '" for="' . esc_attr( $id ) . '">' . esc_html( self::labels()[$key] . ( $optional ? ' (optional)' : '' ) );
   $attrs = ' id="' . esc_attr( $id ) . '" name="location_' . esc_attr( $key ) . '" data-location="' . esc_attr( $key ) . '"' . ( $optional || in_array( $key, array( 'state', 'postcode' ), true ) ? '' : ' required' );
   if ( 'country' === $key ) {
    echo '<select' . $attrs . ' autocomplete="section-rental country"><option value="">Select country</option>';
    foreach ( $countries as $code => $name ) { echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $name ) . '</option>'; } echo '</select>';
   } elseif ( 'notes' === $key ) { echo '<textarea' . $attrs . ' rows="3" maxlength="2000"></textarea>'; }
   else { echo '<input type="text"' . $attrs . ' maxlength="240" autocomplete="section-rental ' . esc_attr( array( 'name' => 'name', 'company' => 'organization', 'address_1' => 'address-line1', 'address_2' => 'address-line2', 'city' => 'address-level2', 'state' => 'address-level1', 'postcode' => 'postal-code' )[$key] ) . '">'; }
   echo '</label>';
  }
  echo '</div></fieldset>';
 }
}
