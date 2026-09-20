<?php
/** Real WP/Woo/InnoDB; explicit WPForms storage/hook doubles, never a live signature or charge. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
use BikeRentalPlugin\{Settings, Database, Packages, Reservations, Availability, GuestSession, Checkout, CheckoutReservation, Payments, Waivers, WaiverSettings, WaiverUI, WPFormsWaiverProvider, AdminCalendar};
$checks = 0; $orders = array(); $saved = Settings::get(); $mail = array();
function wcheck( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo "PASS: $label\n"; }
function wok( $v, $label ) { wcheck( ! is_wp_error( $v ), $label . ( is_wp_error( $v ) ? ': ' . $v->get_error_message() : '' ) ); return $v; }
function wbad( $v, $label ) { wcheck( is_wp_error( $v ), $label ); }
function wr( $id ) { return Database::public_booking( static fn() => Reservations::read( $id ) ); }
function wh( $qty = 1, $pay = true ) {
 wc_load_cart(); WC()->cart->empty_cart(); unset( $_COOKIE[ GuestSession::cookie_name() ] ); GuestSession::start();
 $r = wok( Database::public_booking( static fn() => Reservations::create_booking_hold( array( 'package_id' => $GLOBALS['product']->get_id(), 'quantity' => $qty, 'date' => $GLOBALS['date'], 'time' => '09:00' ), bin2hex( random_bytes( 16 ) ), GuestSession::identity()['hash'] ) ), 'create waiver fixture hold' );
 wok( Checkout::transfer( $r['request_key'] ), 'transfer waiver fixture' );
 $order = wc_create_order( array( 'created_via' => 'store-api' ) ); $GLOBALS['orders'][] = $order->get_id();
 $order->add_product( $GLOBALS['product'], $qty ); $order->set_currency( 'USD' ); $order->set_payment_method( 'square_credit_card' );
 $order->set_billing_first_name( 'Buyer' ); $order->set_billing_last_name( 'Example' ); $order->set_billing_email( 'buyer@example.test' ); $order->calculate_totals();
 do_action( 'woocommerce_store_api_checkout_update_order_meta', $order ); do_action( 'woocommerce_store_api_checkout_order_processed', $order );
 if ( $pay ) { $order->update_meta_data( '_wc_square_credit_card_charge_captured', 'yes' ); $order->update_meta_data( '_wc_square_credit_card_authorization_amount', $order->get_total() ); $order->save(); $order->payment_complete( 'fixture-' . $order->get_id() ); }
 return array( wr( $r['id'] ), wc_get_order( $order->get_id() ) );
}
function wadult( $name = 'Adult One' ) { return array( 'legal_name' => $name, 'age' => '18', 'email' => strtolower( str_replace( ' ', '.', $name ) ) . '@example.test' ); }
function wminor( $name = 'Minor One' ) { return array( 'legal_name' => $name, 'age' => '17', 'guardian_name' => 'Guardian One', 'guardian_email' => 'guardian@example.test', 'guardian_relationship' => 'Parent' ); }
function wsave( $r, $riders ) { return wok( Waivers::save_roster( $r['id'], $riders, $r['revision'] ), 'save exact roster' ); }
function wtoken( $id ) { wok( Waivers::invite( $id ), 'send invitation' ); preg_match( '/brp_waiver=([a-f0-9]{64})/', end( $GLOBALS['mail'] )['message'], $m ); return $m[1] ?? ''; }
function wentry( $token, $id, $alter = null ) {
 $ctx = wok( Waivers::context( $token ), 'resolve private signer context' ); $config = json_decode( $ctx['waiver']['provider_config'], true ); $fields = array();
 foreach ( WPFormsWaiverProvider::expected( $ctx ) as $key => $value ) { $fields[ $config['mapping'][ $key ] ] = array( 'value' => $value ); }
 $fields[ $config['mapping']['signature'] ] = array( 'type' => 'signature', 'value' => 'https://example.test/private/signature.png' ); $fields[ $config['mapping']['consent'] ] = array( 'value' => 'I accept' );
 if ( $alter ) { $fields = $alter( $fields, $config ); }
 $GLOBALS['wpf']->entry->rows[ $id ] = (object) array( 'form_id' => 77, 'fields' => wp_json_encode( $fields ), 'status' => '' ); return $fields;
}
add_filter( 'pre_wp_mail', static function ( $pre, $args ) { $GLOBALS['mail'][] = $args; return ! ( $GLOBALS['mail_fail'] ?? false ); }, 999, 2 );
$settings = Settings::defaults(); foreach ( $settings['weekly_hours'] as &$h ) { $h = array( 'open' => 1, 'start' => '08:00', 'end' => '18:00' ); } unset( $h );
update_option( Settings::OPTION, $settings ); update_option( 'timezone_string', 'America/New_York' ); update_option( 'woocommerce_currency', 'USD' );
foreach ( array( 'waivers', 'riders', 'reservations' ) as $table ) { $wpdb->query( 'DELETE FROM ' . Database::table( $table ) ); } $wpdb->query( 'DELETE FROM ' . Database::table( 'availability' ) . ' WHERE id<>1' ); $wpdb->update( Database::table( 'availability' ), array( 'quantity' => 100 ), array( 'id' => 1 ) );
$date = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+7 days' )->format( 'Y-m-d' );
$product = new WC_Product_Simple(); $product->set_name( 'Waiver fixture' ); $product->set_status( 'publish' ); $product->set_regular_price( '20' ); $product->set_tax_status( 'none' );
foreach ( array( Packages::ENABLED => 'yes', Packages::ACTIVE => 'yes', Packages::TYPE => 'hours', Packages::AMOUNT => 4 ) as $k => $v ) { $product->update_meta_data( $k, $v ); } $product->save();
try {
 wcheck( 'no' === WaiverSettings::get()['required'], 'disabled default' );
 list( $legacy, $old_order ) = wh(); wcheck( 'confirmed' === $legacy['status'], 'disabled paid booking confirms' );
 $s = json_decode( $legacy['snapshot'], true ); unset( $s['waiver_policy'] ); $wpdb->update( Database::table( 'reservations' ), array( 'snapshot' => wp_json_encode( $s ) ), array( 'id' => $legacy['id'] ) ); $legacy = wr( $legacy['id'] );
 update_option( Database::OPTION, '1' ); Database::install(); wcheck( '2' === get_option( Database::OPTION ) && wr( $legacy['id'] ) === $legacy, 'schema 1 upgrade preserves original reservation bytes' );
 foreach ( array( 'riders', 'waivers' ) as $table ) { wcheck( 'InnoDB' === $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', Database::table( $table ) ) ), "$table is InnoDB" ); }
 $policy = array_replace( WaiverSettings::defaults(), array( 'required' => 'yes', 'provider' => 'wpforms', 'version' => 'test-v1', 'text' => "Approved fixture waiver\nAdult or guardian accepts." ) );
 wbad( WaiverSettings::ready( $policy ), 'missing WPForms fails closed' ); $settings['waivers'] = $policy; update_option( Settings::OPTION, $settings );
 ob_start(); WaiverSettings::notice(); wcheck( str_contains( ob_get_clean(), 'notice-error' ), 'missing provider admin warning' );
 wcheck( ! Waivers::required( $legacy ) && 'confirmed' === wr( $legacy['id'] )['status'], 'enabling does not retrofit old reservation' );
 wbad( WaiverSettings::validate( array_replace( $policy, array( 'provider' => 'invalid' ) ) ), 'unregistered provider rejected' );
 $GLOBALS['wpf'] = (object) array( 'entry' => new class { public $rows = array(); public function get( $id ) { return $this->rows[ $id ] ?? null; } }, 'form' => new class { public $value; public function get( $id, $args ) { return 77 === (int) $id ? $this->value : false; } }, 'process' => (object) array( 'errors' => array() ) );
 eval( 'function wpforms() { return $GLOBALS["wpf"]; }' );
 $provider = new WPFormsWaiverProvider(); wcheck( ! $provider->available() && str_contains( $provider->diagnostic(), 'Signature' ), 'missing signature capability diagnosed' );
 add_action( 'wpforms_display_field_signature', '__return_null' ); add_action( 'wpforms_process_validate_signature', '__return_null' ); wcheck( (bool) $provider->available(), 'entry/signature capabilities detected' );
 $config = array( 'form_id' => 77, 'mapping' => array() ); $form = array( 'id' => 77, 'fields' => array(), 'settings' => array() ); $i = 0;
 foreach ( WPFormsWaiverProvider::mapping() as $key => $label ) { $config['mapping'][ $key ] = $i; $form['fields'][ $i ] = array( 'id' => $i, 'required' => 1, 'type' => match( $key ) { 'signer_name' => 'text', 'signer_email' => 'email', 'signature' => 'signature', 'consent' => 'checkbox', default => 'hidden' } ); ++$i; }
 $wpf->form->value = $form; $policy['wpforms'] = $config; $settings['waivers'] = $policy; update_option( Settings::OPTION, $settings ); wok( WaiverSettings::ready( $policy ), 'valid provider mapping ready' ); wcheck( 0 === $config['mapping']['reservation'], 'WPForms field ID zero is valid' );
 foreach ( array( 'form_id', 'mapping' ) as $key ) { $bad = $config; $bad[ $key ] = 'form_id' === $key ? 999 : array(); wbad( $provider->configuration( $bad ), "invalid $key fails closed" ); }
 $wpf->form->value['settings']['disable_entries'] = 1; wbad( $provider->configuration( $config ), 'disabled entry storage rejected' ); $wpf->form->value = $form;
 $_POST['brp_settings_tab'] = 'waivers'; $updated = ( new Settings() )->sanitize( array( 'waivers' => $policy, 'business_name' => 'Injected' ) ); wcheck( $updated['business_name'] === $settings['business_name'] && $updated['payment_mode'] === 'full', 'waiver tab preserves General and full payment' ); $_POST = array();
 ob_start(); WaiverSettings::render(); $html = ob_get_clean(); wcheck( str_contains( $html, 'Waiver Form ID' ) && str_contains( $html, 'Available and configured.' ), 'provider settings mapping and green diagnostics rendered' );
 if ( $dir = getenv( 'BRP_WAIVER_FIXTURE_DIR' ) ) { $_GET = array( 'tab' => 'waivers' ); ob_start(); ( new Settings() )->render(); file_put_contents( $dir . '/waiver-settings.html', ob_get_clean() ); $_GET = array(); }
 foreach ( array( 18 => 'adult', 17 => 'minor', 0 => 'minor', 120 => 'adult' ) as $age => $type ) { $r = array_replace( 'adult' === $type ? wadult() : wminor(), array( 'age' => (string) $age ) ); wcheck( $type === wok( Waivers::validate_rider( $r ), 'valid rider age' )['rider_type'], "classification age $age" ); }
 foreach ( array( 'legal_name', 'age', 'email' ) as $key ) { $r = wadult(); unset( $r[ $key ] ); wbad( Waivers::validate_rider( $r ), "adult requires $key" ); }
 foreach ( array( 'guardian_name', 'guardian_email', 'guardian_relationship' ) as $key ) { $r = wminor(); unset( $r[ $key ] ); wbad( Waivers::validate_rider( $r ), "minor requires $key" ); }
 foreach ( array( '-1', '18.5', '121', array() ) as $age ) { wbad( Waivers::validate_rider( array_replace( wadult(), array( 'age' => $age ) ) ), 'invalid age rejected' ); }
 $r = Waivers::validate_rider( wadult( '<b>Adult One</b>' ) + array() ); wcheck( ! is_wp_error( $r ) && $r['email'] === 'adult.one@example.test', 'decorated email sanitized' ); $r = wadult(); $r['legal_name'] = '<b>Adult One</b>'; wcheck( 'Adult One' === Waivers::validate_rider( $r )['legal_name'], 'rider name sanitized' );
 list( $row, $order ) = wh( 3 ); wcheck( 'pending_waivers' === $row['status'] && '60.00' === $order->get_total(), 'full paid amount unchanged and status pending waivers' );
 wcheck( 3 === count( Waivers::roster( $row ) ) && ! Waivers::progress( $row )['complete'], 'one blank rider per bike blocks readiness' );
 wbad( Waivers::save_roster( $row['id'], array( 1 => wadult() ), $row['revision'] ), 'missing rider rejected' );
 $row = wsave( $row, array( 1 => wadult(), 2 => wminor(), 3 => wminor( 'Minor Two' ) ) ); $roster = Waivers::roster( $row );
 wcheck( 3 === count( $roster ) && count( array_unique( array_column( $roster, 'waiver_id' ) ) ) === 3, 'individual adult and two guardian waiver records' );
 wbad( Waivers::save_roster( $row['id'], array(), 1 ), 'stale roster revision rejected' );
 wbad( Database::locked( static fn() => Waivers::reconcile_locked( $row, 2 ) ), 'populated roster cannot be silently reduced' );
 wbad( Waivers::guard_state( array_replace( $row, array( 'status' => 'active' ) ), $row ), 'activation blocked by incomplete waivers' );
 wcheck( str_contains( WaiverUI::notice( $row ), Waivers::NOTICE ) && str_contains( WaiverUI::notice( $row ), '0 of 3' ), 'prominent pending notice and progress' );
 ob_start(); WaiverUI::roster_form( $row, $order, false, true ); $html = ob_get_clean(); wcheck( str_contains( $html, 'Use purchaser information for Rider 1' ) && ! str_contains( $html, 'signature.png' ), 'purchaser shortcut and no signatures in roster' );
 $tokens = array(); foreach ( $roster as $r ) { $tokens[] = wtoken( $r['waiver_id'] ); }
 $invitations = array_slice( $mail, -3 ); wcheck( $invitations[0]['to'] === 'adult.one@example.test', 'adult emailed directly' ); wcheck( $invitations[1]['to'] === 'guardian@example.test' && $invitations[2]['to'] === 'guardian@example.test', 'same guardian receives individual minor links' );
 wcheck( 3 === count( array_unique( $tokens ) ) && strlen( $tokens[0] ) === 64, 'unique random 256-bit tokens' );
 $w = Database::read( 'waivers', $roster[0]['waiver_id'] ); wcheck( $w['token_hash'] === hash( 'sha256', $tokens[0] ) && ! str_contains( wp_json_encode( $w ), $tokens[0] ), 'only hash stored' );
 $count = count( $mail ); Waivers::invite_pending( $row ); Payments::observe( $order->get_id() ); wcheck( count( $mail ) === $count, 'repeated hooks do not spam invitations' );
 wbad( Waivers::invite( $w['id'], true ), 'immediate resend rate limited' );
 foreach ( array( '', '1', str_repeat( 'f', 64 ), $tokens[0] . 'x', array() ) as $bad ) { wbad( Waivers::context( $bad ), 'invalid/tampered token rejected' ); }
 $ctx = Waivers::context( $tokens[0] ); wcheck( $ctx['rider']['legal_name'] === 'Adult One' && ! str_contains( wp_json_encode( $ctx['rider'] ), 'Minor Two' ), 'signer context reveals only intended rider' );
 $fields = wentry( $tokens[0], 101 ); $bad = wentry( $tokens[0], 102, static function( $f, $c ) { unset( $f[ $c['mapping']['signature'] ] ); return $f; } ); wbad( Waivers::complete( $tokens[0], '102' ), 'missing persisted signature rejected' );
 wentry( $tokens[0], 103, static function( $f, $c ) { $f[ $c['mapping']['rider_name'] ]['value'] = 'Minor Two'; return $f; } ); wbad( Waivers::complete( $tokens[0], '103' ), 'wrong rider stored payload rejected' );
 wbad( Waivers::complete( $tokens[0], '999999' ), 'nonexistent entry rejected' );
 WPFormsWaiverProvider::process( $fields, array( 'brp_waiver_token' => $tokens[0] ), $form ); do_action( 'wpforms_process_complete', $fields, array(), $form, 101 );
 $row = wr( $row['id'] ); wcheck( Waivers::progress( $row )['done'] === 1 && 'pending_waivers' === $row['status'], 'verified WPForms completion advances only one rider' );
 $w = Database::read( 'waivers', $w['id'] ); foreach ( array( 'provider_submission_id' => '101', 'waiver_version' => 'test-v1', 'signer_name' => 'Adult One', 'signer_role' => 'self', 'status' => 'completed' ) as $key => $value ) { wcheck( $w[ $key ] === $value, "completion preserves $key" ); }
 wcheck( ! empty( $w['completed_at'] ) && null === $w['token_hash'] && $w['text_hash'] === hash( 'sha256', $policy['text'] ), 'completion timestamp legal snapshot and consumed token' );
 wcheck( ! str_contains( wp_json_encode( $w ), 'signature.png' ), 'signature blob/reference stays in provider entry' );
 do_action( 'wpforms_process_complete', $fields, array(), $form, 101 ); wbad( Waivers::complete( $tokens[0], '101' ), 'consumed callback token cannot replay' ); wcheck( wr( $row['id'] ) === $row && count( $mail ) === $count, 'duplicate completion does not change revision or email' );
 foreach ( array( 1, 2 ) as $i ) { wentry( $tokens[ $i ], 104 + $i ); wok( Waivers::complete( $tokens[ $i ], (string) ( 104 + $i ) ), 'guardian completion' ); }
 $row = wr( $row['id'] ); wcheck( 'confirmed' === $row['status'] && Waivers::progress( $row )['done'] === 3, 'last required waiver confirms paid reservation' );
 wcheck( str_contains( WaiverUI::notice( $row ), 'Your reservation is confirmed.' ) && ! str_contains( WaiverUI::notice( $row ), Waivers::NOTICE ), 'complete notice replaces action notice' );
 wcheck( '' === WaiverUI::notice( $legacy ), 'disabled notices absent' );
 list( $pending, $po ) = wh(); $pending = wsave( $pending, array( 1 => wadult() ) ); $pr = Waivers::roster( $pending )[0]; $pt = wtoken( $pr['waiver_id'] );
 $wpdb->update( Database::table( 'waivers' ), array( 'last_invited_at' => '2000-01-01 00:00:00' ), array( 'id' => $pr['waiver_id'] ) );
 $post = array( 'operation' => 'resend', 'id' => $pr['waiver_id'], '_wpnonce' => wp_create_nonce( 'brp_waiver_resend_' . $pr['waiver_id'] ) );
 wbad( WaiverUI::admin_dispatch( array_replace( $post, array( '_wpnonce' => 'bad' ) ) ), 'resend nonce required' ); wp_set_current_user( 0 ); wbad( WaiverUI::admin_dispatch( $post ), 'resend capability required' ); wp_set_current_user( 1 ); wok( WaiverUI::admin_dispatch( $post ), 'authorized resend' ); wbad( Waivers::context( $pt ), 'resend invalidates previous link' );
 preg_match( '/brp_waiver=([a-f0-9]{64})/', end( $mail )['message'], $match ); $pt = $match[1];
 ob_start(); WaiverUI::reservation_admin( $pending ); $html = ob_get_clean(); wcheck( str_contains( $html, 'Resend Waiver Email' ) && str_contains( $html, 'Adult One' ), 'admin roster summary and resend' );
 ob_start(); WaiverUI::order_admin( $po ); wcheck( str_contains( ob_get_clean(), 'Waivers: 0/1 Pending' ), 'order admin compact summary' );
 ob_start(); WaiverUI::customer_progress( $pending ); $html = ob_get_clean(); wcheck( str_contains( $html, 'invitation emailed' ) && ! str_contains( $html, '@' ), 'customer invitation state no private email details: ' . $html );
 $po->update_meta_data( '_wc_square_credit_card_charge_captured', 'no' ); $po->save(); wentry( $pt, 200 ); wok( Waivers::complete( $pt, '200' ), 'waiver proof retained despite lost payment evidence' ); wcheck( 'pending_waivers' === wr( $pending['id'] )['status'], 'missing payment prevents confirmation' );
 $po->update_meta_data( '_wc_square_credit_card_charge_captured', 'yes' ); $po->save(); Payments::observe( $po->get_id() ); wcheck( 'confirmed' === wr( $pending['id'] )['status'], 'restored verified payment permits confirmation' );
 foreach ( array( 'cancelled', 'expired', 'completed' ) as $status ) {
  list( $r, $o ) = wh(); $r = wsave( $r, array( 1 => wadult() ) ); $rr = Waivers::roster( $r )[0]; $token = wtoken( $rr['waiver_id'] );
  $wpdb->update( Database::table( 'reservations' ), array( 'status' => $status ), array( 'id' => $r['id'] ) ); wbad( Waivers::context( $token ), "$status link unavailable" ); wbad( Waivers::complete( $token, '101' ), "$status cannot complete/reconfirm" ); wcheck( wr( $r['id'] )['status'] === $status, "$status preserved" );
 }
 list( $r, $o ) = wh(); $r = wsave( $r, array( 1 => wminor() ) ); $rr = Waivers::roster( $r )[0]; $token = wtoken( $rr['waiver_id'] ); $wpdb->update( Database::table( 'waivers' ), array( 'token_expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $rr['waiver_id'] ) ); wbad( Waivers::context( $token ), 'expired link rejected' );
 $post = array( 'operation' => 'exempt', 'id' => $rr['waiver_id'], 'reason' => 'Paper evidence reviewed by staff', '_wpnonce' => wp_create_nonce( 'brp_waiver_exempt_' . $rr['waiver_id'] ) ); wbad( WaiverUI::admin_dispatch( array_replace( $post, array( 'reason' => '' ) ) ), 'exemption requires reason' ); wok( WaiverUI::admin_dispatch( $post ), 'audited exemption' );
 $w = Database::read( 'waivers', $rr['waiver_id'] ); wcheck( $w['status'] === 'exempt' && (int) $w['override_user_id'] === 1 && $w['override_reason'] === $post['reason'] && $w['completed_at'], 'exemption audit actor reason timestamp' ); wcheck( 'confirmed' === wr( $r['id'] )['status'], 'paid complete exemption confirms' );
 wp_set_current_user( 0 ); wcheck( ! WaiverUI::order_access( $o, 'bad' ) && WaiverUI::order_access( $o, $o->get_order_key() ), 'guest roster requires private order key' );
 wbad( WaiverUI::customer_dispatch( array( 'id' => $r['id'], 'order_id' => $o->get_id(), 'key' => $o->get_order_key(), '_wpnonce' => 'bad' ) ), 'customer roster nonce enforced' ); wp_set_current_user( 1 );
 list( $r, $o ) = wh( 2 );
 $fit = Database::locked( static fn( $capacity ) => Availability::evaluate( $capacity, $r['occupied_start_utc'], $r['occupied_end_utc'] ) );
 $excluding = Database::locked( static fn( $capacity ) => Availability::evaluate( $capacity, $r['occupied_start_utc'], $r['occupied_end_utc'], 1, $r['id'] ) );
 wcheck( $fit['peak_existing_usage'] - $excluding['peak_existing_usage'] === 2, 'pending waivers claims exact bike quantity after payment' );
 $data = wok( AdminCalendar::load( $date, 'pending_waivers' ), 'calendar loads pending waivers' ); wcheck( in_array( $r['id'], array_column( $data['rows'], 'id' ), true ), 'calendar includes pending reservation' );
 $_GET = array( 'week' => $date, 'status' => 'pending_waivers' ); ob_start(); AdminCalendar::render(); $html = ob_get_clean(); wcheck( str_contains( $html, 'Waivers: 0/2 Pending' ) && str_contains( $html, 'brp-calendar-warning' ), 'calendar compact incomplete warning without roster' );
 ob_start(); ( new \BikeRentalPlugin\DataAdmin() )->reservations(); $html = ob_get_clean(); wcheck( str_contains( $html, '>Waivers</th>' ) && str_contains( $html, '0/2 Pending' ), 'reservation list waiver column' );
 $_GET = array( 'key' => $o->get_order_key() ); ob_start(); WaiverUI::order_section( $o->get_id() ); $html = ob_get_clean(); wcheck( str_contains( $html, 'Payment received.' ) && str_contains( $html, Waivers::NOTICE ), 'thank-you received-payment and action notice' );
 $method = new ReflectionMethod( \BikeRentalPlugin\PublicBooking::class, 'hold_view' ); $receipt = $method->invoke( null, $r ); wcheck( str_contains( $receipt['waiver_notice'], '0 of 2' ) && ! str_contains( $receipt['message'], 'expired' ), 'public receipt has progress and pending status' );
 ob_start(); WaiverUI::roster_form( $r, $o, false, true ); $html = ob_get_clean(); wcheck( str_contains( $html, 'value="Buyer Example"' ) && str_contains( $html, 'value="buyer@example.test"' ), 'purchaser shortcut prefills without duplicate name/email entry' );
 if ( $dir = getenv( 'BRP_WAIVER_FIXTURE_DIR' ) ) {
  preg_match( '#<style>(.*?)</style>#s', file_get_contents( dirname( __DIR__ ) . '/bike-rental-plugin/src/WaiverUI.php' ), $style );
  file_put_contents( $dir . '/waiver-roster.html', '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $style[1] . '</style><main class="brp-waiver-page"><h1>Rental riders</h1>' . WaiverUI::notice( $r ) . $html . '</main>' );
 }
 wp_set_current_user( 0 ); $post = array( 'id' => $r['id'], 'order_id' => $o->get_id(), 'key' => $o->get_order_key(), 'revision' => $r['revision'], '_wpnonce' => wp_create_nonce( 'brp_riders_' . $r['id'] ), 'riders' => array( 1 => wadult(), 2 => wminor() ) );
 wbad( WaiverUI::customer_dispatch( array_replace( $post, array( 'key' => 'invalid' ) ) ), 'forged order key blocks roster write' );
 $r = wok( WaiverUI::customer_dispatch( $post ), 'guest can save owned paid roster and send invitations' ); wp_set_current_user( 1 );
 $r = wr( $r['id'] ); $roster = Waivers::roster( $r ); ob_start(); WaiverUI::customer_progress( $r ); $html = ob_get_clean(); wcheck( str_contains( $html, 'Guardian waiver pending' ) && str_contains( $html, 'Adult One' ), 'adult/minor progress labels independently rendered' );
 preg_match( '/brp_waiver=([a-f0-9]{64})/', end( $mail )['message'], $match ); $token = $match[1]; $ctx = Waivers::context( $token ); $fields = wentry( $token, 500 );
 $wpf->process->errors = array(); WPFormsWaiverProvider::process( $fields, array( 'brp_waiver_token' => 'bad' ), $form ); wcheck( ! empty( $wpf->process->errors[77] ), 'direct forged provider form submission rejected before storage' );
 $wrong = $fields; $wrong[ $config['mapping']['rider_id'] ]['value'] = $roster[0]['id']; $wpf->process->errors = array(); WPFormsWaiverProvider::process( $wrong, array( 'brp_waiver_token' => $token ), $form ); wcheck( ! empty( $wpf->process->errors[77] ), 'rider token cannot submit another rider identity' );
 $wrong = $fields; $wrong[ $config['mapping']['signer_name'] ]['value'] = array( 'forged' ); $wpf->process->errors = array(); WPFormsWaiverProvider::process( $wrong, array( 'brp_waiver_token' => $token ), $form ); wcheck( ! empty( $wpf->process->errors[77] ), 'nonscalar provider payload rejected' );
 $wpf->entry->rows[500]->form_id = 999; wbad( Waivers::complete( $token, '500' ), 'wrong stored form ID rejected' ); $wpf->entry->rows[500]->form_id = 77;
 $wpf->entry->rows[500]->status = 'spam'; wbad( Waivers::complete( $token, '500' ), 'spam entry rejected' ); $wpf->entry->rows[500]->status = '';
 $settings['waivers']['version'] = 'future-v2'; $settings['waivers']['text'] = 'Changed future text'; update_option( Settings::OPTION, $settings );
 wcheck( Waivers::context( $token )['waiver']['waiver_text'] === $policy['text'] && Waivers::context( $token )['waiver']['waiver_version'] === 'test-v1', 'legal version/text frozen despite settings change' );
 $count = count( $mail ); $_GET['completed'] = 1; wcheck( Waivers::progress( wr( $r['id'] ) )['done'] === 0 && count( $mail ) === $count, 'browser completion flag has no authority' ); unset( $_GET['completed'] );
 list( $unpaid, $uo ) = wh( 1, false ); $ur = wsave( $unpaid, array( 1 => wadult() ) ); $urr = Waivers::roster( $ur )[0]; wbad( Waivers::invite( $urr['waiver_id'] ), 'unpaid roster cannot send invitations' ); wbad( Waivers::guard_state( array_replace( $ur, array( 'status' => 'pending_waivers' ) ) ), 'unpaid cannot enter paid waiting state' );
 list( $r, $o ) = wh(); $r = wsave( $r, array( 1 => wadult() ) ); $rr = Waivers::roster( $r )[0]; $GLOBALS['mail_fail'] = true; wbad( Waivers::invite( $rr['waiver_id'] ), 'mail transport failure reported' ); $GLOBALS['mail_fail'] = false;
 wcheck( Database::read( 'waivers', $rr['waiver_id'] )['status'] === 'invitation_pending', 'failed mail remains pending for staff resend' );
 $wpf->form->value['fields'][ $config['mapping']['signature'] ]['required'] = 0; wbad( $provider->configuration( $config ), 'optional signature is invalid provider configuration' ); $wpf->form->value = $form;
 $wpf->form->value['fields'][ $config['mapping']['consent'] ]['conditional_logic'] = array( 'enabled' => 1 ); wbad( $provider->configuration( $config ), 'conditional acceptance is rejected' ); $wpf->form->value = $form;
 $wpf->form->value['settings']['confirmations'] = array( array( 'type' => 'redirect' ) ); wbad( $provider->configuration( $config ), 'redirect cannot hide waiver result' ); $wpf->form->value = $form;
 foreach ( array( array( 'id' => array(), 'operation' => 'resend' ), array( 'id' => '1', 'operation' => array() ) ) as $bad ) { wbad( WaiverUI::admin_dispatch( $bad ), 'malformed action rejected without PHP warnings' ); }
 wbad( Waivers::save_roster( $r['id'], array(), array() ), 'nonscalar roster revision rejected' ); wbad( Waivers::complete( 'bad', array() ), 'nonscalar provider reference rejected' );
 wcheck( str_contains( WPFormsWaiverProvider::confirmation( 'Default', $form ), 'recorded' ), 'verified provider submission has precise completion message' );
 Waivers::register_provider( 'fixture', new class implements \BikeRentalPlugin\WaiverProvider {
  public function available() { return true; } public function sanitize_config( array $input ) { return array( 'key' => sanitize_text_field( $input['key'] ?? '' ) ); }
  public function settings( array $config ) { echo '<p>Fixture provider</p>'; } public function configuration( array $config ) { return true; }
  public function render( array $context, string $token ) { return '<p>Fixture</p>'; } public function verify_completion( array $context, string $submission ) { return Database::error( 'proof', 'No proof' ); }
 } );
 $generic = array_replace( $policy, array( 'provider' => 'fixture', 'fixture' => array( 'key' => '<b>configured</b>' ) ) ); $generic = wok( WaiverSettings::validate( $generic ), 'third-party provider config through generic interface' );
 wcheck( $generic['fixture']['key'] === 'configured' && true === WaiverSettings::ready( $generic ), 'new provider usable without reservation changes' );
 wcheck( 'full' === Settings::get()['payment_mode'] && '20.00' === wc_get_order( $o->get_id() )->get_total(), 'Full Payment selection and financial totals retained' );
 echo "$checks waiver integration checks passed. Provider and Square evidence are test doubles, not live signing/charges.\n";
} finally {
 wp_set_current_user( 1 ); update_option( Settings::OPTION, $saved ); WC()->cart->empty_cart();
 foreach ( $orders as $id ) { $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } } $product->delete( true );
}
ob_end_flush();
