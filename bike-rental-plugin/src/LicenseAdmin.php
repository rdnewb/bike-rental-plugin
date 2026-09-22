<?php
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class LicenseAdmin {
 public static function dispatch( $post ) {
  if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'permission_denied', 'Only administrators may manage the license.' ); }
  if ( ! is_array( $post ) || ! is_string( $post['_wpnonce'] ?? null ) || ! wp_verify_nonce( $post['_wpnonce'], 'brp_license' ) ) { return new \WP_Error( 'nonce_failed', 'Reload the License tab and try again.' ); }
  if ( ! is_string( $post['license_key'] ?? '' ) ) { return new \WP_Error( 'license_key', 'Enter a valid license key.' ); }
  return License::request( $post['operation'] ?? '', $post['license_key'] ?? '' );
 }
 public static function handle() {
  $result = self::dispatch( wp_unslash( $_POST ) );
  $message = is_wp_error( $result ) ? $result->get_error_message() : 'License response: ' . $result['code'];
  set_transient( 'brp_license_notice_' . get_current_user_id(), $message, 60 );
  wp_safe_redirect( Settings::tab_url( 'license' ) ); exit;
 }
 public static function notice() {
  if ( ! current_user_can( 'manage_options' ) ) { return; }
  $state = License::entitlement(); $s = License::state();
  if ( 'grace' === $state ) { $message = 'License server could not be reached. Your license is temporarily operating in the grace period.'; }
  elseif ( License::enforced() && 'valid' !== $state ) { $message = 'Bike Rental Plugin license needs attention. New bookings are unavailable; existing reservations, orders and waiver evidence remain accessible.'; }
  elseif ( ! empty( $s['deactivation_pending'] ) ) { $message = 'License deactivation is pending on the controller. The local entitlement is inactive; check the License tab.'; }
  else { return; }
  echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . ' <a href="' . esc_url( Settings::tab_url( 'license' ) ) . '">License settings</a></p></div>';
 }
 private static function date( $time ) { return $time ? wp_date( 'Y-m-d H:i T', is_numeric( $time ) ? (int) $time : strtotime( $time ) ) : 'Not available'; }
 public static function render() {
  if ( ! current_user_can( 'manage_options' ) ) { echo '<p>Only administrators may view or change licensing settings.</p>'; return; }
  $s = License::state(); $entitlement = License::entitlement(); $usable = in_array( $entitlement, array( 'valid', 'grace' ), true );
  $masked = empty( $s['key_suffix'] ) ? '' : 'NTL1-****-' . $s['key_suffix'];
  $notice = get_transient( 'brp_license_notice_' . get_current_user_id() );
  if ( $notice ) { echo '<div class="notice notice-info"><p>' . esc_html( $notice ) . '</p></div>'; delete_transient( 'brp_license_notice_' . get_current_user_id() ); }
  echo '<h2>License</h2>';
  if ( 'valid' === $entitlement ) { echo '<div class="notice notice-success inline"><p><strong>License Active</strong> — This plugin is activated. Your license key is saved securely; no further activation is needed.</p></div>'; }
  elseif ( 'grace' === $entitlement ) { echo '<div class="notice notice-warning inline"><p><strong>License Active — Grace Period</strong> — Your saved license is temporarily usable while validation is pending. Use Check License Now to retry.</p></div>'; }
  else { echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( $masked ? 'License Needs Attention' : 'License Not Activated' ) . '</strong> — ' . esc_html( ! empty( $s['deactivation_pending'] ) ? 'Remote deactivation is pending. Retry Deactivate License.' : ( $masked ? 'A license key is saved, but it is not currently active. Review its status below.' : 'Enter your license key below to activate this plugin.' ) ) . '</p></div>'; }
  echo '<p>' . esc_html( License::enforced() ? 'Production enforcement is enabled for new reservations.' : 'Compatibility mode: licensing enforcement is disabled. Current booking operations continue.' ) . '</p><table class="widefat striped"><tbody>';
  $rows = array( 'License Key' => empty( $s['key_suffix'] ) ? 'Not entered' : 'NTL1-****-' . $s['key_suffix'], 'License Status' => ( $s['status'] ?? 'Never activated' ) . ' / ' . License::entitlement(), 'Plan Type' => $s['plan_type'] ?? 'Not available', 'Expiration' => empty( $s['expires_at'] ) ? ( 'lifetime' === ( $s['plan_type'] ?? '' ) ? 'Lifetime' : 'Not available' ) : self::date( $s['expires_at'] ), 'Activations Used / Limit' => isset( $s['activation_limit'] ) ? $s['activations_used'] . ' / ' . ( $s['activation_limit'] ?: 'Unlimited' ) : 'Not available', 'Last Validation' => self::date( $s['last_checked_at'] ?? 0 ), 'Last Successful Validation' => self::date( $s['last_valid_at'] ?? 0 ), 'Next Validation' => self::date( $s['next_check_at'] ?? wp_next_scheduled( License::HOOK ) ), 'Grace Until' => self::date( $s['grace_until'] ?? 0 ), 'Controller connection status' => $s['connection'] ?? 'Not checked', 'Controller response' => $s['code'] ?? 'None', 'Controller URL' => License::endpoint() ?: 'Invalid HTTPS configuration' );
  foreach ( $rows as $label => $value ) { echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>'; } echo '</tbody></table>';
  echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="brp_license">'; wp_nonce_field( 'brp_license' );
  if ( $usable ) {
   echo '<p><label for="brp-license-saved">Saved License Key</label><br><input id="brp-license-saved" type="text" class="regular-text" value="' . esc_attr( $masked ) . '" disabled></p><p>Your saved key is used automatically. Deactivate this license before entering a different key.</p>';
  } else {
   echo '<p><label for="brp-license-key">' . esc_html( $masked ? 'License Key (leave blank to reuse the saved key)' : 'License Key' ) . '</label><br><input id="brp-license-key" type="password" autocomplete="new-password" class="large-text" name="license_key" value="" maxlength="100"></p><p>Keys are stored securely and never displayed in full. Deactivate the current license before replacing its key.</p>';
  }
  $actions = $usable ? array() : array( 'activate' => 'Activate License' );
  if ( $masked ) { $actions += array( 'validate' => 'Check License Now', 'deactivate' => 'Deactivate License' ); }
  foreach ( $actions as $key => $label ) { echo '<button class="button" name="operation" value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</button> '; }
  if ( $usable ) {
   // Preserve the existing salt-rotation recovery path without suggesting active users must enter a key again.
   echo '<details><summary>Re-enter the stored license key</summary><p>Only needed if a license check asks you to re-enter the original key after a site security change.</p><p><label for="brp-license-key">Original License Key</label><br><input id="brp-license-key" type="password" autocomplete="new-password" class="large-text" name="license_key" value="" maxlength="100"></p><button class="button" name="operation" value="activate">Restore License Key</button></details>';
  }
  echo '</form><p>Daily validation sends only the license credential, product, installation ID, site URL and software versions. No rental, waiver or customer-order data is sent.</p>';
 }
}
