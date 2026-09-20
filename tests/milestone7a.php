<?php
/** Real WP/Woo/MariaDB coverage. Never run against a customer database. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
use BikeRentalPlugin\{AdminCalendar, Availability, Database, DataAdmin, Packages, PublicBooking, Reservations, Settings};
$checks = 0; $products = array(); $order = null;
$saved_get = $_GET; $saved_zone = get_option( 'timezone_string' ); $saved_week = get_option( 'start_of_week' );
function m7check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo "PASS: $label\n"; }
function m7dom( $html ) { $doc = new DOMDocument(); @$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html ); return new DOMXPath( $doc ); }
function m7html( $value ) { $_GET['rental'] = $value; return PublicBooking::shortcode(); }
function m7row( $reference, $status, $start, $end, $qty = 1, $extra = array() ) {
	global $wpdb;
	$row = array_merge( array( 'reference' => $reference, 'package_product_id' => $GLOBALS['products'][0]->get_id(), 'quantity' => $qty, 'start_utc' => $start, 'end_utc' => $end, 'occupied_start_utc' => $start, 'occupied_end_utc' => $end, 'timezone' => 'America/New_York', 'status' => $status, 'snapshot' => wp_json_encode( array( 'name' => 'Calendar <ride>', 'duration_type' => 'hours', 'duration_amount' => 4 ) ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), $extra );
	if ( ! $wpdb->insert( Database::table( 'reservations' ), $row ) ) { throw new RuntimeException( $wpdb->last_error ); } return $wpdb->insert_id;
}
try {
	update_option( 'timezone_string', 'America/New_York' ); update_option( 'start_of_week', 1 );
	foreach ( array( 'hourly-tour', 'three-day-tour', 'seven-day-tour', 'inactive-tour', 'ordinary-tour', 'invalid-metadata-tour', 'draft-tour', 'unsupported-tour' ) as $i => $slug ) {
		$p = 7 === $i ? new WC_Product_Variable() : new WC_Product_Simple(); $p->set_name( ucwords( str_replace( '-', ' ', $slug ) ) ); $p->set_slug( $slug ); $p->set_status( 6 === $i ? 'draft' : 'publish' ); $p->set_regular_price( '55' );
		foreach ( array( Packages::ENABLED => 4 === $i ? 'no' : 'yes', Packages::ACTIVE => 3 === $i ? 'no' : 'yes', Packages::TYPE => 0 === $i ? 'hours' : 'calendar_days', Packages::AMOUNT => 5 === $i ? 0 : ( 2 === $i ? 7 : 3 ) ) as $key => $value ) { $p->update_meta_data( $key, $value ); }
		$p->save(); $products[] = $p;
	}
	foreach ( array( $products[1]->get_slug(), (string) $products[1]->get_id() ) as $value ) {
		$html = m7html( $value ); $dom = m7dom( $html );
		m7check( $dom->query( '//input[@name="package_id"]' )->item( 0 )->getAttribute( 'value' ) === (string) $products[1]->get_id(), 'valid slug/ID preselection ' . $value );
		m7check( 1 === $dom->query( '//article[not(@hidden)]' )->length, 'only selected card visible on server' );
		m7check( 1 === $dom->query( '//button[@aria-pressed="true"]' )->length, 'accessible selected state rendered' );
		m7check( 1 === $dom->query( '//div[@class="brp-details" and not(@hidden)]' )->length, 'booking controls revealed on server' );
		m7check( 1 === $dom->query( '//button[@class="brp-change-rental" and not(@hidden)]' )->length, 'change action rendered' );
		m7check( 0 === $dom->query( '//h1|//link[@rel="canonical"]|//title' )->length, 'shortcode adds no H1/title/canonical variants' );
	}
	foreach ( array( '', 'does-not-exist', '<script>alert(1)</script>', '../three-day-tour', 'three-day-tour ', array( 'three-day-tour' ), '0', '-1', str_repeat( 'a', 201 ), ...array_map( static fn( $p ) => $p->get_slug(), array_slice( $products, 3 ) ), (string) $products[3]->get_id() ) as $value ) {
		$dom = m7dom( m7html( $value ) );
		m7check( '' === $dom->query( '//input[@name="package_id"]' )->item( 0 )->getAttribute( 'value' ) && $dom->query( '//article[not(@hidden)]' )->length >= 3, 'invalid/ineligible input falls back: ' . wp_json_encode( $value ) );
	}
	$numeric = (string) $products[0]->get_id(); $products[2]->set_slug( $numeric ); $products[2]->save();
	m7check( m7dom( m7html( $numeric ) )->query( '//input[@name="package_id"]' )->item( 0 )->getAttribute( 'value' ) === (string) $products[2]->get_id(), 'numeric slug wins over ID fallback' );
	$products[2]->set_slug( 'seven-day-tour' ); $products[2]->save();
	if ( $dir = getenv( 'BRP_M7_FIXTURE_DIR' ) ) {
		if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
		file_put_contents( $dir . '/deep-link.html', m7html( $products[1]->get_slug() ) );
		file_put_contents( $dir . '/invalid-link.html', m7html( 'does-not-exist' ) );
		file_put_contents( $dir . '/packages.json', wp_json_encode( array_map( static fn( $p ) => array( 'product_id' => $p->get_id(), 'slug' => $p->get_slug(), 'name' => $p->get_name(), 'price_html' => $p->get_price_html() ), array_slice( $products, 0, 3 ) ) ) );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'reservations' ) ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <> 1', Database::table( 'availability' ) ) );
	$wpdb->update( Database::table( 'availability' ), array( 'quantity' => 17 ), array( 'id' => 1 ) );
	$days = AdminCalendar::week( '2032-03-08' );
	m7check( '2032-03-08' === $days[0]->format( 'Y-m-d' ) && '2032-03-15' === $days[7]->format( 'Y-m-d' ), 'weekly Monday range' );
	m7check( AdminCalendar::week()[0] <= new DateTimeImmutable( 'today', wp_timezone() ) && AdminCalendar::week()[7] > new DateTimeImmutable( 'today', wp_timezone() ), 'default week includes today' );
	m7check( 167 * 3600 === $days[7]->getTimestamp() - $days[0]->getTimestamp(), 'spring DST week uses 167 elapsed hours' );
	m7check( '2032-03-14 05:00:00' === AdminCalendar::utc( $days[6] ) && '2032-03-15 04:00:00' === AdminCalendar::utc( $days[7] ), 'DST local midnights convert separately' );
	$fall = AdminCalendar::week( '2032-11-01' ); m7check( 169 * 3600 === $fall[7]->getTimestamp() - $fall[0]->getTimestamp(), 'fall DST week uses 169 hours' );
	m7check( abs( AdminCalendar::position( '2032-03-14 16:00:00', $days ) - ( 6 + 11 / 23 ) / 7 * 100 ) < .00001, 'bar position respects shortened local day' );
	update_option( 'start_of_week', 0 ); m7check( '2032-03-07' === AdminCalendar::week( '2032-03-08' )[0]->format( 'Y-m-d' ), 'configured Sunday first day respected' ); update_option( 'start_of_week', 1 );
	foreach ( array( 'bad', '2032-02-31', array( '2032-03-08' ) ) as $value ) { m7check( AdminCalendar::week( $value ) == AdminCalendar::week(), 'malformed week falls back to today' ); }
	// Fixture records directly model all lifecycle states; normal writes have their own regression suites.
	$hour = m7row( 'BRP-HOURLY', 'confirmed', '2032-03-08 14:00:00', '2032-03-08 18:00:00', 3, array( 'occupied_start_utc' => '2032-03-08 13:30:00', 'occupied_end_utc' => '2032-03-08 18:30:00' ) );
	$multi = m7row( 'BRP-MULTI', 'confirmed', '2032-03-08 15:00:00', '2032-03-10 22:00:00', 2 );
	m7row( 'BRP-WAIVERS', 'pending_waivers', '2032-03-12 14:00:00', '2032-03-12 18:00:00', 1 );
	m7row( 'BRP-NONOVERLAP', 'confirmed', '2032-03-08 23:00:00', '2032-03-09 01:00:00', 4 );
	$expiry = gmdate( 'Y-m-d H:i:s', time() + 3600 );
	m7row( 'BRP-HOLD', 'hold', '2032-03-08 14:00:00', '2032-03-08 16:00:00', 1, array( 'hold_expires_at' => $expiry ) );
	m7row( 'BRP-STALE-HOLD', 'hold', '2032-03-08 14:00:00', '2032-03-08 15:00:00', 10, array( 'hold_expires_at' => '2020-01-01 00:00:00' ) );
	foreach ( array( 'completed', 'cancelled', 'expired' ) as $state ) { m7row( 'BRP-' . strtoupper( $state ), $state, '2032-03-08 14:00:00', '2032-03-08 18:00:00', 10 ); }
	$active = m7row( 'BRP-OVERDUE', 'active', '2020-01-01 14:00:00', '2020-01-01 18:00:00', 1 );
	m7row( 'BRP-OLD', 'confirmed', '2020-01-01 14:00:00', '2020-01-01 18:00:00' );
	m7row( 'BRP-LATER', 'confirmed', '2040-01-01 14:00:00', '2040-01-01 18:00:00' );
	$order = wc_create_order(); $order->set_billing_first_name( 'Test' ); $order->set_billing_last_name( 'Rider <safe>' ); $order->save();
	$wpdb->update( Database::table( 'reservations' ), array( 'order_id' => $order->get_id(), 'issue_code' => 'inventory_conflict' ), array( 'id' => $hour ) );
	$wpdb->update( Database::table( 'reservations' ), array( 'order_id' => 2147483647 ), array( 'id' => $multi ) );
	foreach ( array( array( 'Maintenance <safe>', '2032-03-08 14:00:00', '2032-03-08 16:00:00', 1 ), array( 'Ongoing repair', '2032-03-10 00:00:00', null, 1 ), array( 'Disabled', '2032-03-08 14:00:00', '2032-03-08 16:00:00', 0 ), array( 'Outside week', '2040-03-08 14:00:00', '2040-03-08 16:00:00', 1 ) ) as $b ) {
		$wpdb->insert( Database::table( 'availability' ), array( 'record_type' => 'block', 'quantity' => 2, 'start_utc' => $b[1], 'end_utc' => $b[2], 'reason' => $b[0], 'active' => $b[3], 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}
	$data = AdminCalendar::load( '2032-03-08' ); m7check( ! is_wp_error( $data ), 'authorized administrator loads calendar' );
	m7check( 10 === count( $data['rows'] ), 'only intersecting records and overdue active rows loaded' );
	m7check( 2 === count( $data['blocks'] ), 'only active intersecting / indefinite blocks loaded' );
	m7check( 17 === $data['capacity'], 'fleet capacity is configured, not hard-coded' );
	m7check( 9 === $data['usage'][0]['peak_existing_usage'], 'overlapping peak includes buffers, hold, active and block; excludes completed/cancelled/expired/stale holds' );
	foreach ( $data['usage'] as $i => $usage ) { m7check( $usage === Availability::check( AdminCalendar::utc( $days[ $i ] ), AdminCalendar::utc( $days[ $i + 1 ] ) ), 'day ' . $i . ' capacity equals authoritative engine' ); }
	m7check( 4 === Availability::check( '2032-03-08 13:45:00', '2032-03-08 14:00:00' )['peak_existing_usage'], 'preparation occupies inventory before displayed rental start' );
	m7check( 6 === Availability::check( '2032-03-08 18:00:00', '2032-03-08 18:30:00' )['peak_existing_usage'], 'turnaround occupies inventory after rental end' );
	m7check( $data['orders'][ $order->get_id() ]['customer'] === 'Test Rider <safe>', 'Woo CRUD customer loaded' );
	m7check( ! isset( $data['orders'][2147483647] ), 'missing Woo order ignored gracefully' );
	foreach ( Reservations::STATUSES as $state ) {
		$filtered = AdminCalendar::load( '2032-03-08', $state );
		m7check( count( $filtered['rows'] ) > 0 && array_unique( array_column( $filtered['rows'], 'status' ) ) === array( $state ), 'status filter ' . $state );
		m7check( $filtered['usage'] === $data['usage'], 'filter never reduces capacity totals: ' . $state );
	}
	m7check( AdminCalendar::load( '2032-03-08', array( 'active' ) )['status'] === 'all', 'malformed status uses all' );
	$_GET = array( 'week' => '2032-03-08', 'status' => 'all' ); ob_start(); AdminCalendar::render(); $html = ob_get_clean(); $dom = m7dom( $html );
	foreach ( Reservations::STATUSES as $state ) { m7check( str_contains( $html, 'brp-calendar-' . $state ), 'text / style for ' . $state ); }
	m7check( str_contains( $html, '9:00 AM EST' ) && str_contains( $html, '1:00 PM EST' ), 'hourly interval displayed in 12-hour local time' );
	m7check( 1 === $dom->query( '//*[@data-event="reservation-' . $multi . '"]' )->length, 'multi-day rental is one spanning event' );
	m7check( str_contains( $html, '3 bikes' ) && str_contains( $html, '2 bikes' ), 'pooled quantities shown' );
	m7check( str_contains( $html, 'Maintenance &lt;safe&gt;' ) && str_contains( $html, 'Test Rider &lt;safe&gt;' ), 'customer and block reason escaped' );
	m7check( str_contains( $html, 'Calendar &lt;ride&gt;' ) && str_contains( $html, 'Exception: inventory_conflict' ), 'snapshot escaped and exception visible' );
	m7check( str_contains( $html, 'Hold — expires' ) && str_contains( $html, 'Hold — expired; no inventory claim' ), 'hold expiration distinguished from live hold' );
	m7check( str_contains( $html, 'overdue; blocking until returned' ), 'overdue active explicitly marked' );
	m7check( str_contains( $html, esc_url( DataAdmin::url( DataAdmin::RESERVATIONS, $hour ) ) ), 'reservation links to edit / view page' );
	foreach ( array( 'Previous Week' => '2032-03-01', 'Today' => '', 'Next Week' => '2032-03-15' ) as $label => $date ) { m7check( str_contains( $html, esc_url( AdminCalendar::url( $date ) ) ) && str_contains( $html, $label ), 'navigation: ' . $label ); }
	if ( $dir ) { file_put_contents( $dir . '/calendar.html', $html ); }
	$manager = wp_insert_user( array( 'user_login' => 'brp_m7_manager_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
	wp_set_current_user( $manager ); m7check( ! is_wp_error( AdminCalendar::load( '2032-03-08' ) ), 'shop manager allowed' );
	wp_set_current_user( 0 ); m7check( is_wp_error( AdminCalendar::load( '2032-03-08' ) ), 'guest denied' );
	$die = static fn() => static function () { throw new RuntimeException( 'calendar_permission_denied' ); };
	add_filter( 'wp_die_handler', $die );
	try { AdminCalendar::render(); m7check( false, 'guest rendering must stop' ); }
	catch ( RuntimeException $error ) { m7check( 'calendar_permission_denied' === $error->getMessage(), 'calendar renderer separately denies unauthorized access' ); }
	finally { remove_filter( 'wp_die_handler', $die ); }
	wp_set_current_user( $manager ); $user = new WP_User( $manager ); $user->set_role( 'subscriber' ); wp_set_current_user( 0 ); wp_set_current_user( $manager );
	m7check( is_wp_error( AdminCalendar::load( '2032-03-08' ) ), 'subscriber denied' );
	wp_set_current_user( 1 ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $manager );
	update_option( 'timezone_string', 'Europe/London' ); m7check( str_contains( AdminCalendar::time( '2032-03-08 14:00:00' ), '2:00 PM GMT' ), 'timezone is configurable, not hard-coded' );
	m7check( Database::VERSION === '2', 'schema is 2' );
} finally {
	wp_set_current_user( 1 ); $_GET = $saved_get; update_option( 'timezone_string', $saved_zone ); update_option( 'start_of_week', $saved_week );
	foreach ( $products as $p ) { $p->delete( true ); } if ( $order ) { $order->delete( true ); }
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'reservations' ) ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <> 1', Database::table( 'availability' ) ) );
}
echo "Milestone 7A: $checks checks passed.\n";
ob_end_flush();
