<?php
/** Real REST dispatch, scheduling and guest ownership; guarded disposable fixture only. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
// Keep advisory rate counters independent across repeated disposable test runs.
$_SERVER['REMOTE_ADDR'] = 'fd00:' . implode( ':', str_split( bin2hex( random_bytes( 14 ) ), 4 ) );
use BikeRentalPlugin\{Database, Settings, Packages, Reservations, BookingSchedule, GuestSession, PublicBooking};
$checks = 0;
function public_check( $value, $label ) { if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo 'PASS: ' . $label . PHP_EOL; }
function public_reset() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'reservations' ) ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <> 1', Database::table( 'availability' ) ) );
	$wpdb->update( Database::table( 'availability' ), array( 'quantity' => 7 ), array( 'id' => 1 ) );
}
function public_request( $route, $input = array(), $token = null, $origin = null ) {
	$request = new WP_REST_Request( in_array( $route, array( 'packages', 'times', 'availability' ), true ) ? 'GET' : 'POST', '/' . PublicBooking::API . '/' . $route );
	$request->set_header( 'origin', $origin ?? home_url() ); $request->set_header( 'x-brp-request', '1' );
	if ( null !== $token ) { $request->set_header( 'x-brp-token', $token ); }
	foreach ( $input as $key => $value ) { $request->set_param( $key, $value ); }
	return rest_get_server()->dispatch( $request );
}
public_reset();
$settings = Settings::defaults();
foreach ( $settings['weekly_hours'] as &$hours ) { $hours = array( 'open' => 1, 'start' => '08:00', 'end' => '18:00' ); } unset( $hours );
update_option( Settings::OPTION, $settings ); update_option( 'timezone_string', 'America/New_York' );
$product = new WC_Product_Simple(); $product->set_name( 'Public booking fixture' ); $product->set_status( 'publish' ); $product->set_regular_price( '64.00' ); $product->set_sale_price( '51.25' );
foreach ( array( Packages::ENABLED => 'yes', Packages::ACTIVE => 'yes', Packages::TYPE => 'hours', Packages::AMOUNT => 4, Packages::PROMO => 'Fixture offer' ) as $key => $value ) { $product->update_meta_data( $key, $value ); }
$package_id = $product->save();
$date = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+7 days' )->format( 'Y-m-d' );
$input = array( 'package_id' => $package_id, 'date' => $date, 'time' => '09:00' );
wp_set_current_user( 0 );
// Plugin is loaded by the fixture after WordPress init; explicitly register its shortcode hook.
do_action( 'init' );
public_check( shortcode_exists( 'bike_rental_booking' ), 'shortcode registered' );
public_check( ! wp_script_is( 'brp-booking', 'enqueued' ), 'public assets absent before shortcode rendering' );
$html = do_shortcode( '[bike_rental_booking]' );
public_check( str_contains( $html, 'type="date"' ) && str_contains( $html, 'aria-live="polite"' ) && str_contains( $html, '<label for=' ), 'shortcode renders native labeled accessible controls' );
public_check( wp_script_is( 'brp-booking', 'enqueued' ) && wp_style_is( 'brp-booking', 'done' ), 'shortcode loads its assets including late-rendered stylesheet' );
$response = public_request( 'packages' ); $catalog = $response->get_data();
$item = array_values( array_filter( $catalog['packages'], static fn( $p ) => $p['product_id'] === $package_id ) )[0];
public_check( '51.25' === $item['price'] && str_contains( $item['price_html'], '51.25' ), 'current selling price uses WooCommerce formatting' );
public_check( 4 === $item['duration_amount'] && 'hours' === $item['duration_type'] && 'Fixture offer' === $item['promotional_label'], 'public package metadata correct' );
foreach ( array( array( Packages::ACTIVE, 'no' ), array( Packages::ENABLED, 'no' ) ) as $invalid ) {
	$product->update_meta_data( $invalid[0], $invalid[1] ); $product->save();
	public_check( is_wp_error( BookingSchedule::prepare( $input ) ), 'inactive/non-rental package rejected' );
	$ids = array_column( public_request( 'packages' )->get_data()['packages'], 'product_id' );
	public_check( ! in_array( $package_id, $ids, true ), 'inactive/non-rental package absent from public catalog' );
	$product->update_meta_data( $invalid[0], 'yes' ); $product->save();
}
$product->set_status( 'draft' ); $product->save();
public_check( is_wp_error( BookingSchedule::prepare( $input ) ), 'unpublished package rejected' );
$product->set_status( 'publish' ); $product->save();
foreach ( array( array( 'package_id' => '1 OR 1=1' ), array( 'package_id' => array( 1 ) ), array( 'date' => '2030-02-30' ), array( 'time' => '25:00' ), array( 'time' => '09:17' ), array( 'date' => '2000-01-01' ), array( 'date' => '9998-01-01' ) ) as $change ) {
	public_check( public_request( 'availability', array_replace( $input, $change ) )->get_status() === 400, 'invalid public package/date/time rejected' );
}
$package = BookingSchedule::package( $package_id );
$clock = '2030-06-17 12:00:00'; // Monday 08:00 local.
$notice = $settings; $notice['minimum_notice'] = 61;
public_check( is_wp_error( BookingSchedule::calculate( $package, '2030-06-17', '09:00', $notice, $clock ) ), 'minimum elapsed notice enforced' );
$valid = BookingSchedule::calculate( $package, '2030-06-17', '09:00', $settings, $clock );
public_check( ! is_wp_error( $valid ) && '2030-06-17T13:00' === $valid['local_end'], 'valid hourly start and elapsed endpoint accepted' );
public_check( is_wp_error( BookingSchedule::calculate( $package, '2030-06-17', '15:00', $settings, $clock ) ), 'hourly pickup outside open period rejected' );
$closed = $settings; $closed['weekly_hours']['monday']['open'] = 0;
public_check( is_wp_error( BookingSchedule::calculate( $package, '2030-06-17', '09:00', $closed, $clock ) ), 'closed start day rejected' );
$days = $package; $days['duration_type'] = 'calendar_days'; $days['duration_amount'] = 3;
$closed = $settings; $closed['weekly_hours']['tuesday']['open'] = 0;
$calendar = BookingSchedule::calculate( $days, '2030-06-17', '09:00', $closed, $clock );
public_check( ! is_wp_error( $calendar ) && '2030-06-19T17:00' === $calendar['local_end'], 'three calendar days end on day three and may pass through closed days' );
$closed['weekly_hours']['wednesday']['open'] = 0;
public_check( is_wp_error( BookingSchedule::calculate( $days, '2030-06-17', '09:00', $closed, $clock ) ), 'closed calendar endpoint rejected without extension' );
$days['duration_amount'] = 1;
public_check( is_wp_error( BookingSchedule::calculate( $days, '2030-06-17', '17:00', $settings, $clock ) ), 'one-day package requires positive interval before pickup' );
$dst_settings = $settings; foreach ( $dst_settings['weekly_hours'] as &$h ) { $h = array( 'open' => 1, 'start' => '00:00', 'end' => '23:59' ); } unset( $h );
$dst = BookingSchedule::calculate( $package, '2030-03-10', '00:00', $dst_settings, '2030-03-01 00:00:00' );
public_check( ! is_wp_error( $dst ) && '2030-03-10T05:00' === $dst['local_end'], 'hourly spring DST duration uses four elapsed hours' );
$dst = BookingSchedule::calculate( $package, '2030-11-03', '00:00', $dst_settings, '2030-11-01 00:00:00' );
public_check( ! is_wp_error( $dst ) && '2030-11-03T03:00' === $dst['local_end'], 'hourly autumn DST duration uses four elapsed hours' );
public_check( is_wp_error( BookingSchedule::calculate( $package, '2030-11-03', '01:30', $dst_settings, '2030-11-01 00:00:00' ) ), 'ambiguous start rejected' );
$days['duration_amount'] = 3;
$dst = BookingSchedule::calculate( $days, '2030-03-09', '09:00', $settings, '2030-03-01 00:00:00' );
public_check( ! is_wp_error( $dst ) && '2030-03-11T17:00' === $dst['local_end'], 'calendar day endpoint remains local pickup across DST' );
$availability = public_request( 'availability', $input )->get_data();
public_check( $availability['valid'] && 7 === $availability['available_quantity'], 'public availability uses configured fleet rather than fixed ten' );
public_check( '13:00' === substr( $availability['rental_end'], 11 ), 'public endpoint returns calculated actual end' );
$times = public_request( 'times', array_intersect_key( $input, array_flip( array( 'package_id', 'date' ) ) ) )->get_data()['times'];
public_check( in_array( '14:00', array_column( $times, 'time' ), true ) && ! in_array( '14:30', array_column( $times, 'time' ), true ), 'offered times honor closing endpoint and increment' );
$private = array( 'snapshot', 'occupied_start_utc', 'request_hash', 'session_hash', 'issue_code', 'reference', 'id' );
public_check( ! array_intersect( $private, array_keys( $availability ) ), 'availability response does not expose reservation or occupied details' );
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'reservations' ) );
public_request( 'availability', $input );
public_check( $before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'reservations' ) ), 'public GET lookup never creates a reservation' );
$response = public_request( 'availability', $input + array( 'session_hash' => 'secret' ) );
public_check( 400 === $response->get_status(), 'extra private fields rejected' );
public_check( str_contains( public_request( 'packages' )->get_headers()['Cache-Control'], 'no-store' ), 'REST payloads explicitly bypass caches' );
$hold_input = $input + array( 'quantity' => 3, 'request_key' => 'public-first' );
public_check( 403 === public_request( 'holds', $hold_input )->get_status(), 'mutation requires guest cookie and CSRF token' );
public_check( 403 === public_request( 'session', array(), null, 'https://other.invalid' )->get_status(), 'cross-origin session bootstrap rejected' );
$session = public_request( 'session' )->get_data(); $cookie = $_COOKIE[ GuestSession::cookie_name() ];
public_check( isset( $session['token'] ) && ! isset( $session['hash'] ), 'session token returned without raw identity/hash' );
public_check( 403 === public_request( 'holds', $hold_input, 'wrong-token' )->get_status(), 'wrong mutation token rejected' );
foreach ( array_keys( $hold_input ) as $required ) { $missing = $hold_input; unset( $missing[ $required ] ); public_check( 400 === public_request( 'holds', $missing, $session['token'] )->get_status(), 'missing required hold field rejected' ); }
foreach ( array( 0, -1, '2.5', array( 1 ), 99 ) as $quantity ) { public_check( 400 === public_request( 'holds', array_replace( $hold_input, array( 'quantity' => $quantity ) ), $session['token'] )->get_status(), 'invalid hold quantity rejected' ); }
$response = public_request( 'holds', $hold_input, $session['token'] ); $hold = $response->get_data();
public_check( 200 === $response->get_status() && $hold['reserved'], 'guest can create a valid temporary hold' );
public_check( 4 === public_request( 'availability', $input )->get_data()['available_quantity'], 'guest hold consumes availability' );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_key = %s', Database::table( 'reservations' ), 'public-first' ), ARRAY_A );
$snapshot = json_decode( $row['snapshot'], true );
public_check( '51.25' === $snapshot['price'] && 3 === $snapshot['quantity'] && $snapshot['local_end'] === $hold['rental_end'] && 'Fixture offer' === $snapshot['promotional_label'], 'hold captures selling price and agreed package/schedule snapshot' );
public_check( ! array_intersect( array( 'id', 'snapshot', 'session_hash', 'request_hash', 'issue_code' ), array_keys( $hold ) ), 'hold response exposes only customer summary' );
public_check( 900 === strtotime( $row['hold_expires_at'] ) - strtotime( $row['created_at'] ) && strtotime( $hold['expires_at'] ) - strtotime( $hold['server_time'] ) <= 900 && $hold['reserved'], 'hold expires fifteen minutes from authoritative creation time' );
public_check( $hold['reference'] === public_request( 'holds', $hold_input, $session['token'] )->get_data()['reference'], 'same request returns original hold' );
public_check( 400 === public_request( 'holds', array_replace( $hold_input, array( 'quantity' => 2 ) ), $session['token'] )->get_status(), 'same request key with different intent rejected' );
public_check( 400 === public_request( 'holds', array_replace( $hold_input, array( 'request_key' => 'second-live' ) ), $session['token'] )->get_status(), 'one live public hold per guest session' );
unset( $_COOKIE[ GuestSession::cookie_name() ] );
$other_session = public_request( 'session' )->get_data();
public_check( 400 === public_request( 'hold-status', array( 'request_key' => 'public-first' ), $other_session['token'] )->get_status(), 'another guest cannot read first guest hold' );
public_check( 400 === public_request( 'holds', $hold_input, $other_session['token'] )->get_status(), 'another guest cannot reuse first guest request key' );
public_check( 409 === public_request( 'holds', array_replace( $hold_input, array( 'quantity' => 5, 'request_key' => 'too-many' ) ), $other_session['token'] )->get_status(), 'final server check rejects quantity above available' );
$full = public_request( 'holds', array_replace( $hold_input, array( 'quantity' => 4, 'request_key' => 'remaining' ) ), $other_session['token'] );
public_check( 200 === $full->get_status() && 0 === public_request( 'availability', $input )->get_data()['available_quantity'], 'exact remaining quantity succeeds and zero availability reported' );
$_COOKIE[ GuestSession::cookie_name() ] = $cookie;
$wpdb->update( Database::table( 'reservations' ), array( 'hold_expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $row['id'] ) );
$expired = public_request( 'hold-status', array( 'request_key' => 'public-first' ), $session['token'] )->get_data();
public_check( ! $expired['reserved'] && 'hold' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', Database::table( 'reservations' ), $row['id'] ) ), 'public expiry state is correct before housekeeping' );
public_check( 3 === public_request( 'availability', $input )->get_data()['available_quantity'], 'expired timestamp releases public availability before cleanup' );
public_reset();
$settings['preparation_buffer'] = 30; $settings['turnaround_buffer'] = 30; update_option( Settings::OPTION, $settings );
$buffer_hold = public_request( 'holds', array_replace( $hold_input, array( 'quantity' => 7, 'request_key' => 'buffered' ) ), $session['token'] )->get_data();
$adjacent = array_replace( $input, array( 'time' => '13:00' ) );
public_check( 0 === public_request( 'availability', $adjacent )->get_data()['available_quantity'], 'occupied buffers affect adjacent availability' );
public_check( '09:00' === substr( $buffer_hold['rental_start'], 11 ) && '13:00' === substr( $buffer_hold['rental_end'], 11 ), 'buffers never change customer displayed rental period' );
$product->update_meta_data( Packages::ACTIVE, 'no' ); $product->save();
public_check( 400 === public_request( 'holds', array_replace( $hold_input, array( 'request_key' => 'removed-package' ) ), $session['token'] )->get_status(), 'hold revalidates package after earlier availability lookup' );
$product->update_meta_data( Packages::ACTIVE, 'yes' ); $product->save();
public_check( is_wp_error( Reservations::create_hold( array(), 'direct-anonymous', hash( 'sha256', 'bad' ) ) ), 'public scope never leaks authorization into ordinary service calls' );
$css = file_get_contents( dirname( __DIR__ ) . '/bike-rental-plugin/assets/css/booking.css' );
public_check( ! preg_match( '/^\s*(?!\.brp-booking)[^\s}][^{]*\{/m', $css ) && ! preg_match( '/#[a-f0-9]{3,8}\b/i', $css ), 'CSS is scoped with no brand colors' );
public_check( ! str_contains( $html, 'request_hash' ) && ! str_contains( $html, 'session_hash' ), 'shortcode contains no session secrets' );
$_COOKIE[ GuestSession::cookie_name() ] = substr( $cookie, 0, -1 ) . ( str_ends_with( $cookie, 'a' ) ? 'b' : 'a' );
public_check( null === GuestSession::identity(), 'tampered signed cookie rejected' );
$_COOKIE[ GuestSession::cookie_name() ] = $cookie;
$get_hold = new WP_REST_Request( 'GET', '/' . PublicBooking::API . '/holds' );
public_check( 404 === rest_get_server()->dispatch( $get_hold )->get_status(), 'GET cannot invoke the hold mutation route' );
$real_db = $wpdb;
$wpdb = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
	public function query( $query ) {
		if ( str_contains( $query, 'FOR UPDATE' ) ) { $this->last_error = 'PRIVATE_SQL_DETAILS'; $this->print_error( $this->last_error ); return false; }
		return parent::query( $query );
	}
};
$wpdb->set_prefix( 'm3_' );
ob_start(); $failure = public_request( 'availability', $input ); $leaked = ob_get_clean();
public_check( 503 === $failure->get_status() && ! str_contains( wp_json_encode( $failure->get_data() ), 'PRIVATE_SQL_DETAILS' ), 'public SQL failure returns generic retry message' );
public_check( '' === $leaked, 'database diagnostics are not printed into public response' );
$wpdb->close(); $wpdb = $real_db;
echo PHP_EOL . $checks . ' public booking integration checks passed.' . PHP_EOL;
ob_end_flush();
