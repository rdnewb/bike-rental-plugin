<?php
namespace NTLicenseController;
defined( 'ABSPATH' ) || exit;

final class Admin {
 public static function menu() {
  add_menu_page( 'NT Licenses', 'NT Licenses', 'manage_options', 'ntlc', array( self::class, 'render' ), 'dashicons-admin-network' );
  foreach ( array( 'ntlc' => 'Licenses', 'ntlc-add' => 'Add License', 'ntlc-activations' => 'Activations', 'ntlc-events' => 'Events', 'ntlc-settings' => 'Settings' ) as $slug => $label ) { add_submenu_page( 'ntlc', $label, $label, 'manage_options', $slug, array( self::class, 'render' ) ); }
 }
 public static function dispatch( $post ) {
  if ( ! current_user_can( 'manage_options' ) ) { return Store::error( 'permission_denied' ); }
  if ( ! is_array( $post ) || ! is_string( $post['_wpnonce'] ?? null ) || ! wp_verify_nonce( $post['_wpnonce'], 'ntlc_save' ) ) { return Store::error( 'nonce_failed' ); }
  $action = $post['operation'] ?? ''; $id = $post['id'] ?? '0';
  if ( ! is_scalar( $id ) || ! preg_match( '/^[0-9]{1,18}$/D', (string) $id ) ) { return Store::error( 'invalid_input' ); }
  if ( 'settings' === $action ) {
   $values = array(); foreach ( array( 'ip_limit', 'key_limit' ) as $key ) { $v = $post[ $key ] ?? ''; if ( ! is_scalar( $v ) || ! preg_match( '/^[0-9]{2,5}$/D', (string) $v ) || $v < 10 || $v > 10000 ) { return Store::error( 'invalid_rate_limit' ); } $values[ $key ] = (int) $v; }
   update_option( 'ntlc_settings', $values, false ); return array( 'id' => 0 );
  }
  if ( 'deactivate' === $action && $id ) { return Licenses::deactivate( (int) $id ); }
  if ( 'save' !== $action ) { return Store::error( 'invalid_operation' ); }
  if ( ( $post['timezone'] ?? '' ) !== wp_timezone_string() ) { return Store::error( 'timezone_changed' ); }
  $local = $post['expiry_local'] ?? '';
  if ( ! is_string( $local ) ) { return Store::error( 'invalid_expiration' ); }
  if ( $local ) {
   $date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $local, wp_timezone() );
   if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $local ) { return Store::error( 'invalid_expiration' ); }
   $post['expires_at'] = $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
  } else { $post['expires_at'] = ''; }
  return Licenses::save( $post, (int) $id );
 }
 public static function handle() {
  nocache_headers(); header( 'Referrer-Policy: no-referrer' );
  $result = self::dispatch( wp_unslash( $_POST ) );
  if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 'NT Licenses', array( 'response' => 400, 'back_link' => true ) ); }
  if ( isset( $result['key'] ) ) {
   // Raw key exists only in this response; no redirect/transient stores the secret.
   wp_die( '<h1>License created</h1><p>Copy this key now. It is shown only once and cannot be recovered.</p><p><code>' . esc_html( $result['key'] ) . '</code></p><p><a href="' . esc_url( admin_url( 'admin.php?page=ntlc&id=' . $result['id'] ) ) . '">View license</a></p>', 'NT Licenses', array( 'response' => 200 ) );
  }
  wp_safe_redirect( admin_url( 'admin.php?page=ntlc&saved=1' . ( $result['id'] ? '&id=' . $result['id'] : '' ) ) ); exit;
 }
 private static function form_start( $operation, $id = 0 ) {
  echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ntlc_save"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="id" value="' . (int) $id . '">'; wp_nonce_field( 'ntlc_save' );
 }
 public static function date( $value ) { return $value ? wp_date( 'Y-m-d H:i T', strtotime( $value . ' UTC' ) ) : 'None'; }
 private static function field( $name, $label, $value, $type = 'text' ) { echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></label></p>'; }
 private static function select( $name, $values, $value ) {
  echo '<p><label>' . esc_html( ucwords( str_replace( '_', ' ', $name ) ) ) . '<br><select name="' . esc_attr( $name ) . '">';
  foreach ( $values as $v ) { echo '<option value="' . esc_attr( $v ) . '" ' . selected( $value, $v, false ) . '>' . esc_html( ucfirst( $v ) ) . '</option>'; } echo '</select></label></p>';
 }
 private static function edit( $row ) {
  self::form_start( 'save', $row['id'] ?? 0 );
  echo '<input type="hidden" name="timezone" value="' . esc_attr( wp_timezone_string() ) . '">';
  if ( $row ) { echo '<p>Key: NTL1-****-' . esc_html( $row['license_key_display_suffix'] ) . '</p><p>Product: ' . esc_html( $row['product_slug'] ) . ' / ' . esc_html( $row['plan_type'] ) . '</p>'; }
  else { self::field( 'product_slug', 'Product slug (for example bike-rental-plugin)', 'bike-rental-plugin' ); self::select( 'plan_type', Licenses::PLANS, 'lifetime' ); }
  self::field( 'customer_name', 'Customer name', $row['customer_name'] ?? '' ); self::field( 'customer_email', 'Customer email', $row['customer_email'] ?? '', 'email' );
  self::select( 'status', Licenses::STATUSES, $row['status'] ?? 'active' );
  self::field( 'activation_limit', 'Activation limit (0 = unlimited)', $row['activation_limit'] ?? 1, 'number' );
  self::field( 'expiry_local', 'Expiration in ' . wp_timezone_string() . ' (required for monthly/annual; blank for lifetime)', empty( $row['expires_at'] ) ? '' : wp_date( 'Y-m-d\TH:i', strtotime( $row['expires_at'] . ' UTC' ) ), 'datetime-local' );
  echo '<p><label>Internal notes<br><textarea name="notes" class="large-text" rows="4" maxlength="10000">' . esc_textarea( $row['notes'] ?? '' ) . '</textarea></label></p>';
  echo '<p>Set status to Active, Suspended or Revoked here. To extend a term, change its expiration and set Active. Product, plan and key cannot be changed after creation.</p>';
  submit_button( $row ? 'Save license' : 'Generate license key' ); echo '</form>';
 }
 public static function render() {
  if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Permission denied.' ); }
  global $wpdb; echo '<div class="wrap"><h1>NT Licenses</h1>';
  if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>License settings saved.</p></div>'; }
  if ( ! Store::healthy() ) { echo '<p>License storage is unavailable. Verify the controller database installation.</p></div>'; return; }
  $page = is_string( $_GET['page'] ?? null ) ? $_GET['page'] : 'ntlc'; $id = absint( $_GET['id'] ?? 0 );
  if ( 'ntlc-add' === $page || ( 'ntlc' === $page && $id ) ) { $row = $id ? Licenses::read( $id ) : null; if ( $id && ! $row ) { echo '<p>License unavailable.</p>'; } else { self::edit( $row ); } }
  elseif ( 'ntlc-settings' === $page ) {
   self::form_start( 'settings' ); foreach ( Api::settings() as $key => $v ) { self::field( $key, $key . ' requests per UTC hour (10-10000)', $v, 'number' ); }
   echo '<p>Only direct REMOTE_ADDR is trusted. Configure your trusted reverse proxy in WordPress/server infrastructure. Successful validations are logged once per installation per UTC day; events are pruned after 180 days. All API traffic requires HTTPS.</p>'; submit_button(); echo '</form>';
  } else {
   $kind = match ( $page ) { 'ntlc-activations' => 'activations', 'ntlc-events' => 'events', default => 'licenses' };
   $number = max( 1, absint( $_GET['paged'] ?? 1 ) );
   $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 50 OFFSET %d', Store::table( $kind ), ( $number - 1 ) * 50 ), ARRAY_A );
   $cols = match ( $kind ) { 'licenses' => array( 'Key', 'Product', 'Customer', 'Plan', 'Status', 'Expires', 'Activations', 'Created', 'Action' ), 'activations' => array( 'License', 'Site', 'Installation ID', 'Activated', 'Last check', 'Plugin / WordPress / PHP', 'Status', 'Action' ), default => array( 'License', 'Activation', 'Event', 'Admin ID', 'Date' ) };
   echo '<table class="widefat striped"><thead><tr>'; foreach ( $cols as $c ) { echo '<th>' . esc_html( $c ) . '</th>'; } echo '</tr></thead><tbody>';
   foreach ( $rows ?: array() as $r ) {
    if ( 'licenses' === $kind ) { $cells = array( 'NTL1-****-' . $r['license_key_display_suffix'], $r['product_slug'], $r['customer_name'] . ' ' . $r['customer_email'], $r['plan_type'], 'active' === $r['status'] && $r['expires_at'] && $r['expires_at'] <= Store::now() ? 'expired' : $r['status'], self::date( $r['expires_at'] ), Licenses::used( $r['id'] ) . ' / ' . ( $r['activation_limit'] ?: 'Unlimited' ), self::date( $r['created_at'] ) ); }
    elseif ( 'activations' === $kind ) { $cells = array( $r['license_id'], $r['site_url'], $r['installation_id'], self::date( $r['activated_at'] ), self::date( $r['last_checked_at'] ), $r['plugin_version'] . ' / ' . $r['wordpress_version'] . ' / ' . $r['php_version'], $r['status'] ); }
    else { $cells = array( $r['license_id'], $r['activation_id'], $r['event_code'], $r['actor_id'], self::date( $r['created_at'] ) ); }
    echo '<tr>'; foreach ( $cells as $c ) { echo '<td>' . esc_html( $c ) . '</td>'; }
    if ( 'licenses' === $kind ) { echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=ntlc&id=' . $r['id'] ) ) . '">View / Edit</a></td>'; }
    elseif ( 'activations' === $kind ) { echo '<td>'; if ( 'active' === $r['status'] ) { self::form_start( 'deactivate', $r['id'] ); echo '<button class="button">Deactivate Activation</button></form>'; } echo '</td>'; }
    echo '</tr>';
   } echo '</tbody></table><p>';
   if ( $number > 1 ) { echo '<a href="' . esc_url( add_query_arg( 'paged', $number - 1 ) ) . '">Previous</a> '; }
   if ( count( $rows ?: array() ) === 50 ) { echo '<a href="' . esc_url( add_query_arg( 'paged', $number + 1 ) ) . '">Next</a>'; } echo '</p>';
  } echo '</div>';
 }
}
