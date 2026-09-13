<?php
/**
 * Real WordPress/WooCommerce/MariaDB integration checks. Creates persistent fixtures.
 * Run ONLY against the disposable localhost database described in docs/reservations-verification.md.
 * BRP_TEST_WP_ROOT points to its WordPress directory; BRP_ALLOW_DISPOSABLE_TESTS=1 opts in.
 * This is deliberately separate from the dependency-free foundation/package suites.
 */
if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'BRP_ALLOW_DISPOSABLE_TESTS' ) ) { throw new RuntimeException( "Disposable CLI test opt-in is required.\n" ); }
$wp_root = getenv( 'BRP_TEST_WP_ROOT' );
if ( ! $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) { throw new RuntimeException( "Set BRP_TEST_WP_ROOT to disposable WordPress.\n" ); }
require $wp_root . '/wp-load.php';
if ( 'brp_m3_disposable' !== DB_NAME || '127.0.0.1:33316' !== DB_HOST || 'm3_' !== $wpdb->prefix ) { throw new RuntimeException( "Refusing non-disposable database.\n" ); }
require_once dirname( __DIR__ ) . '/bike-rental-plugin/bike-rental-plugin.php';

use BikeRentalPlugin\Database;
use BikeRentalPlugin\DataAdmin;
use BikeRentalPlugin\Fleet;
use BikeRentalPlugin\Packages;
use BikeRentalPlugin\Plugin;
use BikeRentalPlugin\RentalTime;
use BikeRentalPlugin\Reservations;
use BikeRentalPlugin\Settings;

$checks = 0;
function verify( $condition, $label ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$GLOBALS['checks'];
	echo 'PASS: ' . $label . PHP_EOL;
}
function good( $result, $label ) {
	verify( ! is_wp_error( $result ), $label . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '' ) );
	return $result;
}
function bad( $result, $label ) { verify( is_wp_error( $result ), $label ); }

wp_set_current_user( 1 );
Plugin::boot();
Plugin::load_packages();
verify( has_action( 'init', array( Database::class, 'install' ) ) === 20, 'schema installation registered on normal init' );
verify( class_exists( 'WC_Product_Simple' ), 'real WooCommerce loaded' );
// Reset disposable plugin fixtures between runs; the strict DB/host/prefix guard above is mandatory.
foreach ( array( 'reservations', 'availability' ) as $kind ) {
	$table = Database::table( $kind );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <> %d', $table, 'availability' === $kind ? 1 : 0 ) );
	}
}
$settings = Settings::defaults();
$settings['business_name'] = 'Verification Cycle Shop';
$settings['preparation_buffer'] = 15;
$settings['turnaround_buffer'] = 30;
update_option( Settings::OPTION, $settings );
update_option( 'timezone_string', 'America/New_York' );
$product = new WC_Product_Simple();
$product->set_name( 'Integration Rental ' . gmdate( 'His' ) );
$product->set_status( 'publish' );
$product->set_regular_price( '83.27' );
$product->update_meta_data( Packages::ENABLED, 'yes' );
$product->update_meta_data( Packages::ACTIVE, 'yes' );
$product->update_meta_data( Packages::TYPE, 'hours' );
$product->update_meta_data( Packages::AMOUNT, 4 );
$product->update_meta_data( Packages::PROMO, 'Sample offer' );
$product->update_meta_data( 'unrelated_extension', 'keep' );
$product_id = $product->save();
delete_option( Database::OPTION );
Database::install();
verify( Database::VERSION === get_option( Database::OPTION ), 'schema version recorded after verified installation' );
verify( ! get_option( Database::ERROR ), 'no schema installation error' );
$r = Database::table( 'reservations' );
$a = Database::table( 'availability' );
verify( 'm3_brp_reservations' === $r && 'm3_brp_availability' === $a, 'configured non-default prefix used' );
foreach ( array( $r, $a ) as $table ) {
	verify( 'InnoDB' === $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) ), $table . ' exists using InnoDB' );
}
verify( 2 === count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'brp_' ) . '%' ) ) ), 'exactly two plugin tables created' );
verify( $settings === Settings::get(), 'existing settings survive schema installation' );
verify( 'keep' === wc_get_product( $product_id )->get_meta( 'unrelated_extension' ) && 4 === Packages::get_package( $product_id )['duration_amount'], 'existing rental and unrelated product metadata survive installation' );
good( Fleet::set_capacity( 10 ), 'fleet quantity saved' );
verify( 10 === Fleet::capacity(), 'fleet quantity read from persistent row' );
Database::install();
update_option( Database::OPTION, '0' );
Database::install();
verify( 10 === Fleet::capacity() && '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE record_type=%s', $a, 'capacity' ) ), 'repeat installation and upgrade retain exactly one capacity row' );
good( Fleet::set_capacity( 12 ), 'capacity updated away from setup default' );
update_option( Database::OPTION, '0' );
Database::install();
verify( 12 === Fleet::capacity(), 'upgrade never resets saved fleet to default' );

$block_input = array( 'quantity' => '2', 'start' => '2030-06-15T09:00', 'end' => '2030-06-15T12:00', 'reason' => 'Test Maintenance', 'active' => '1' );
$block = good( Fleet::save_block( $block_input ), 'block created' );
verify( '2030-06-15 13:00:00' === $block['start_utc'] && '2030-06-15 16:00:00' === $block['end_utc'], 'block local interval persisted as UTC' );
verify( 1 === (int) $block['created_by'], 'block creator retained' );
$block = good( Fleet::save_block( array_replace( $block_input, array( 'quantity' => 3, 'reason' => 'Edited maintenance' ) ), $block['id'] ), 'block edited' );
verify( 3 === (int) $block['quantity'] && 'Edited maintenance' === Fleet::block( $block['id'] )['reason'], 'block edit persisted' );
bad( Fleet::set_capacity( 2 ), 'capacity cannot fall below an individual current/upcoming active block' );
$block = good( Fleet::disable_block( $block['id'] ), 'block disabled' );
verify( 0 === (int) Fleet::block( $block['id'] )['active'], 'disabled block retained' );
good( Fleet::set_capacity( 10 ), 'disabled block does not restrict capacity update' );
$indefinite = good( Fleet::save_block( array_replace( $block_input, array( 'end' => '' ) ) ), 'indefinite block created' );
verify( null === $indefinite['end_utc'], 'optional end stored as SQL NULL' );
good( Fleet::disable_block( $indefinite['id'] ), 'indefinite block can be disabled' );
foreach ( array( 0, -1, 11, '1.5', '1e2', '01', true, array(), '9999999999999' ) as $quantity ) {
	bad( Fleet::save_block( array_replace( $block_input, array( 'quantity' => $quantity ) ) ), 'invalid block quantity rejected: ' . wp_json_encode( $quantity ) );
	bad( Fleet::set_capacity( in_array( $quantity, array( 11 ), true ) ? 0 : $quantity ), 'invalid fleet quantity rejected: ' . wp_json_encode( $quantity ) );
}
foreach ( array( array( 'end' => '2030-06-15T08:00' ), array( 'end' => $block_input['start'] ), array( 'start' => '2030-02-30T10:00' ), array( 'start' => array() ), array( 'reason' => '' ), array( 'reason' => str_repeat( 'x', 241 ) ), array( 'active' => 'yes' ) ) as $i => $changes ) {
	bad( Fleet::save_block( array_replace( $block_input, $changes ) ), 'invalid block fields rejected ' . $i );
}
bad( Fleet::save_block( $block_input, 1 ), 'block update cannot overwrite capacity row' );
bad( Fleet::disable_block( 1 ), 'block disable cannot disable capacity row' );
bad( Fleet::block( '2 OR 1=1' ), 'query-string ID injection rejected' );

$input = array( 'package_product_id' => $product_id, 'quantity' => 2, 'start' => '2030-06-16T09:00', 'end' => '2030-06-16T13:00', 'status' => 'hold' );
$reservation = good( Reservations::create( $input ), 'manual reservation created' );
$id = $reservation['id'];
verify( preg_match( '/^BRP-[0-9]{8}-[A-F0-9]{16}$/D', $reservation['reference'] ), 'human-readable collision-resistant reference format' );
$another = good( Reservations::create( array_replace( $input, array( 'start' => '2020-06-16T09:00', 'end' => '2020-06-16T13:00' ) ) ), 'second independent started test reservation created' );
verify( $another['reference'] !== $reservation['reference'], 'references unique across reservations' );
verify( '2030-06-16 13:00:00' === $reservation['start_utc'] && '2030-06-16 17:00:00' === $reservation['end_utc'], 'reservation UTC storage' );
verify( '2030-06-16 12:45:00' === $reservation['occupied_start_utc'] && '2030-06-16 17:30:00' === $reservation['occupied_end_utc'], 'occupied interval incorporates snapshotted preparation and turnaround buffers' );
verify( null === $reservation['order_id'] && null === $reservation['order_item_id'] && $reservation['hold_expires_at'] > gmdate( 'Y-m-d H:i:s' ) && ! empty( $reservation['request_key'] ), 'order fields remain NULL while manual holds now have expiry and request identity' );
$snapshot = json_decode( $reservation['snapshot'], true );
verify( '83.27' === $snapshot['price'] && 4 === $snapshot['duration_amount'] && 'hours' === $snapshot['duration_type'], 'regular price and duration snapshotted' );
verify( $product_id === $snapshot['product_id'] && 'USD' === $snapshot['currency'] && 'Sample offer' === $snapshot['promotional_label'] && 'America/New_York' === $snapshot['timezone'] && $input['start'] === $snapshot['local_start'] && $input['end'] === $snapshot['local_end'], 'snapshot contains required package and local-time evidence' );
$product->set_regular_price( '97.43' );
$product->update_meta_data( Packages::AMOUNT, 6 );
$product->save();
verify( '97.43' === Packages::get_package( $product_id )['price'] && 6 === Packages::get_package( $product_id )['duration_amount'], 'real WooCommerce product price and duration changed' );
verify( $reservation['snapshot'] === Reservations::read( $id )['snapshot'], 'later product edits never rewrite original snapshot' );
foreach ( array( 0, -1, '1 OR 1=1', array(), 2147483647 ) as $package_id ) { bad( Reservations::create( array_replace( $input, array( 'package_product_id' => $package_id ) ) ), 'invalid package rejected: ' . wp_json_encode( $package_id ) ); }
$product->update_meta_data( Packages::ACTIVE, 'no' ); $product->save();
bad( Reservations::create( $input ), 'inactive package rejected for new reservation' );
$product->update_meta_data( Packages::ACTIVE, 'yes' ); $product->set_status( 'draft' ); $product->save();
bad( Reservations::create( $input ), 'unpublished package rejected' );
$product->set_status( 'publish' ); $product->set_regular_price( '' ); $product->save();
bad( Reservations::create( $input ), 'unset regular price rejected' );
$product->set_regular_price( '97.43' ); $product->save();
foreach ( array( 0, -1, 11, '2.5', true, array() ) as $quantity ) { bad( Reservations::create( array_replace( $input, array( 'quantity' => $quantity ) ) ), 'invalid reservation quantity rejected: ' . wp_json_encode( $quantity ) ); }
foreach ( array( '', '2030-06-16T08:00', $input['start'], '2030-06-16T25:00', array() ) as $end ) { bad( Reservations::create( array_replace( $input, array( 'end' => $end ) ) ), 'invalid reservation interval rejected: ' . wp_json_encode( $end ) ); }
bad( Reservations::create( array_replace( $input, array( 'status' => 'paid' ) ) ), 'payment status not introduced as reservation status' );
$updated = good( Reservations::update( $id, array_replace( $input, array( 'quantity' => 3, 'end' => '2030-06-16T14:00' ) ), 1 ), 'reservation schedule and quantity updated' );
verify( 2 === (int) $updated['revision'] && 3 === (int) $updated['quantity'] && '2030-06-16 18:00:00' === $updated['end_utc'], 'schedule edit persists and increments revision' );
verify( '83.27' === json_decode( $updated['snapshot'], true )['price'] && '2030-06-16T14:00' === json_decode( $updated['snapshot'], true )['local_end'] && 3 === json_decode( $updated['snapshot'], true )['quantity'], 'schedule edit refreshes current snapshot while preserving agreed package price' );
bad( Reservations::update( $id, $input, 1 ), 'stale schedule revision rejected' );
$confirmed = good( Reservations::change_status( $id, 'confirmed', 2 ), 'hold changes to confirmed' );
verify( 3 === (int) $confirmed['revision'], 'status change increments revision' );
$noop = good( Reservations::change_status( $id, 'confirmed', 3 ), 'same-status retry is a no-op' );
verify( 3 === (int) $noop['revision'], 'no-op does not increment revision' );
bad( Reservations::change_status( $id, 'confirmed', 2 ), 'stale duplicate status request rejected' );
$cancelled = good( Reservations::cancel( $id, 3 ), 'confirmed reservation cancelled' );
verify( 'cancelled' === Reservations::read( $id )['status'] && 4 === (int) $cancelled['revision'], 'cancelled reservation retained with incremented revision' );
bad( Reservations::mark_active( $id, 4 ), 'cancelled reservation cannot reactivate' );
good( Reservations::update( $id, array_replace( $input, array( 'status' => 'cancelled' ) ), 4 ), 'administrative correction can reschedule a retained cancelled reservation' );
$active = good( Reservations::change_status( $another['id'], 'confirmed', 1 ), 'second reservation confirmed' );
$active = good( Reservations::mark_active( $another['id'], 2 ), 'confirmed reservation marked active' );
bad( Reservations::cancel( $another['id'], 3 ), 'active rental requires completion rather than cancellation' );
$completed = good( Reservations::mark_completed( $another['id'], 3 ), 'active rental completed' );
verify( 'completed' === Reservations::read( $another['id'] )['status'], 'completed reservation retained' );
$expiring = good( Reservations::create( $input ), 'test hold created for expiry transition' );
good( Reservations::change_status( $expiring['id'], 'expired', 1 ), 'hold can be explicitly marked expired' );
verify( Reservations::STATUSES === array( 'hold', 'confirmed', 'active', 'completed', 'cancelled', 'expired' ), 'exact six lifecycle statuses' );

verify( '2030-06-16T09:00' === RentalTime::display( $reservation['start_utc'], true ), 'UTC converts back to WordPress local input' );
verify( '2030-01-16 14:00:00' === RentalTime::from_local( '2030-01-16T09:00' ), 'winter offset differs from summer' );
bad( RentalTime::from_local( '2030-03-10T02:30' ), 'DST spring gap rejected' );
bad( RentalTime::from_local( '2030-11-03T01:30' ), 'DST autumn ambiguous hour rejected' );
update_option( 'timezone_string', 'Asia/Kathmandu' );
verify( '2030-06-15 03:15:00' === RentalTime::from_local( '2030-06-15T09:00' ), 'quarter-hour timezone offset supported' );
update_option( 'timezone_string', '' ); update_option( 'gmt_offset', 5.5 );
verify( '2030-06-15 03:30:00' === RentalTime::from_local( '2030-06-15T09:00' ), 'WordPress fixed offset supported' );
$invalid_timezone = static function () { return 'Invalid/Timezone'; };
add_filter( 'pre_option_timezone_string', $invalid_timezone );
bad( RentalTime::from_local( $input['start'] ), 'invalid WordPress timezone rejected without crash' );
remove_filter( 'pre_option_timezone_string', $invalid_timezone );
update_option( 'timezone_string', 'America/New_York' );

$admin = new DataAdmin();
function form_data( $operation, $id = 0, $extra = array() ) { return array_merge( array( 'operation' => $operation, 'id' => (string) $id, '_wpnonce' => wp_create_nonce( 'brp_' . $operation . '_' . $id ), 'timezone' => wp_timezone_string() ), $extra ); }
$before_capacity = Fleet::capacity();
bad( $admin->dispatch( array( 'operation' => 'capacity', 'id' => '0', 'quantity' => 8 ) ), 'missing nonce rejected' );
bad( $admin->dispatch( form_data( 'capacity', 0, array( 'quantity' => 8, '_wpnonce' => 'invalid' ) ) ), 'invalid nonce rejected by real WordPress verifier' );
bad( $admin->dispatch( form_data( 'block_disable', $block['id'], array( '_wpnonce' => wp_create_nonce( 'brp_block_disable_1' ) ) ) ), 'nonce bound to operation and record' );
bad( $admin->dispatch( form_data( 'reservation_create', 0, array_merge( $input, array( 'timezone' => 'UTC' ) ) ) ), 'timezone changed while form open rejected' );
verify( $before_capacity === Fleet::capacity(), 'invalid forms do not mutate capacity' );
good( $admin->dispatch( form_data( 'capacity', 0, array( 'quantity' => 10 ) ) ), 'valid real nonce authorizes capacity mutation' );
bad( $admin->dispatch( form_data( 'block_disable', '2 OR 1=1' ) ), 'admin ID injection rejected' );
bad( $admin->dispatch( array( 'operation' => array() ) ), 'malformed admin operation rejected' );
$nonce_before_logout = form_data( 'capacity', 0, array( 'quantity' => 8 ) );
wp_set_current_user( 0 );
bad( $admin->dispatch( $nonce_before_logout ), 'unauthorized user cannot use otherwise valid form' );
bad( Fleet::set_capacity( 8 ), 'unauthorized internal capacity write denied' );
bad( Fleet::save_block( $block_input ), 'unauthorized internal block write denied' );
bad( Reservations::create( $input ), 'unauthorized internal reservation create denied' );
bad( Reservations::cancel( $id, 4 ), 'unauthorized status mutation denied' );
bad( Reservations::read( $id ), 'unauthorized reservation read denied' );
wp_set_current_user( 1 );
$manager_id = wp_insert_user( array( 'user_login' => 'brp_manager_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'shop_manager' ) );
wp_set_current_user( $manager_id );
good( Fleet::set_capacity( 10 ), 'real WooCommerce shop manager can manage fleet' );
good( Reservations::read( $id ), 'shop manager can read reservations' );
wp_set_current_user( 1 );

$_GET = array();
ob_start(); $admin->fleet(); $html = ob_get_clean();
verify( str_contains( $html, 'Total rentable bikes' ) && str_contains( $html, 'Disabled' ) && str_contains( $html, 'name="_wpnonce"' ), 'Fleet page renders persisted records and real nonce forms' );
ob_start(); $admin->reservations(); $html = ob_get_clean();
verify( str_contains( $html, 'Create manual test reservation' ) && str_contains( $html, $reservation['reference'] ), 'reservation list and active package create form render' );
$_GET = array( 'id' => $id );
ob_start(); $admin->reservations(); $html = ob_get_clean();
verify( str_contains( $html, '83.27' ) && str_contains( $html, 'Occupied start (local)' ) && str_contains( $html, 'Revision' ), 'detail renders original snapshot, occupied interval, and revision' );
$wpdb->update( $r, array( 'issue_code' => '<script>alert(1)</script>' ), array( 'id' => $id ) );
ob_start(); $admin->reservations(); $html = ob_get_clean();
verify( ! str_contains( $html, '<script>alert(1)</script>' ) && str_contains( $html, '&lt;script&gt;' ), 'stored detail values escaped' );
$_GET = array( 'id' => array( 'bad' ), 'paged' => '1 OR 1=1' );
ob_start(); $admin->reservations(); $html = ob_get_clean();
verify( str_contains( $html, 'Invalid record ID' ) && str_contains( $html, 'Invalid page number' ), 'malformed admin GET values handled safely' );
$_GET = array();
verify( count( Database::listing( 'reservations' )['rows'] ) <= 25, 'reservation list bounded to 25 rows' );
bad( Database::listing( 'reservations', 0 ), 'invalid pagination rejected' );

// Real constraints, migration repair, and failure recovery in this disposable DB only.
$suppression = $wpdb->suppress_errors( true );
$duplicate = $reservation; unset( $duplicate['id'] );
verify( false === $wpdb->insert( $r, $duplicate ), 'database unique reference constraint enforced' );
$duplicate['reference'] = Reservations::reference(); $duplicate['request_key'] = 'test-' . wp_generate_uuid4();
verify( false !== $wpdb->insert( $r, $duplicate ), 'nullable future request key accepts explicit distinct key' );
$duplicate['reference'] = Reservations::reference();
verify( false === $wpdb->insert( $r, $duplicate ), 'database unique non-null request key constraint enforced' );
$wpdb->suppress_errors( $suppression );
$stored = Reservations::read( $id );
$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX occupied_end', $r ) );
update_option( Database::OPTION, '0' );
Database::install();
verify( Database::VERSION === get_option( Database::OPTION ) && ! get_option( Database::ERROR ), 'dbDelta restores missing index on versioned upgrade' );
verify( $stored === Reservations::read( $id ) && 10 === Fleet::capacity(), 'schema repair preserves reservation and fleet rows' );
verify( $settings === Settings::get() && 'keep' === wc_get_product( $product_id )->get_meta( 'unrelated_extension' ), 'settings and package metadata remain intact after repair' );
update_option( Database::OPTION, '999' );
Database::install();
verify( '999' === get_option( Database::OPTION ) && get_option( Database::ERROR ), 'newer schema rejected without downgrade' );
bad( Fleet::set_capacity( 9 ), 'schema error blocks data writes' );
ob_start(); Database::notice(); $html = ob_get_clean();
verify( str_contains( $html, 'Restore a compatible plugin version' ), 'actionable schema error displayed to administrator' );
update_option( Database::OPTION, '0' ); Database::install();
verify( ! get_option( Database::ERROR ), 'repair retry clears schema error' );

class BRP_Failing_Wpdb extends wpdb {
	public $failure = '';
	public $reservation_inserts = 0;
	public function query( $query ) {
		if ( $this->failure && str_contains( $query, $this->failure ) ) { $this->last_error = 'Injected verification failure'; return false; }
		$result = parent::query( $query );
		if ( 1 === $result && str_starts_with( $query, 'INSERT INTO `m3_brp_reservations`' ) ) { ++$this->reservation_inserts; }
		return $result;
	}
}
$real_db = $wpdb;
$wpdb = new BRP_Failing_Wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$wpdb->set_prefix( 'm3_' );
$wpdb->failure = 'COMMIT';
$before_rows = $real_db->get_var( $real_db->prepare( 'SELECT COUNT(*) FROM %i', $r ) );
bad( Reservations::create( array_replace( $input, array( 'status' => 'confirmed' ) ) ), 'commit failure reported to caller' );
verify( 1 === $wpdb->reservation_inserts && $before_rows === $real_db->get_var( $real_db->prepare( 'SELECT COUNT(*) FROM %i', $r ) ), 'failed commit rolls back a verified successful reservation insert' );
$wpdb->failure = 'GET_LOCK'; update_option( Database::OPTION, '0' ); Database::install();
verify( '0' === get_option( Database::OPTION ) && get_option( Database::ERROR ), 'lock failure never records successful schema version' );
$wpdb->failure = 'information_schema.TABLES'; Database::install();
verify( '0' === get_option( Database::OPTION ) && get_option( Database::ERROR ), 'table verification failure never advances schema' );
$wpdb->failure = ''; Database::install();
verify( Database::VERSION === get_option( Database::OPTION ) && ! get_option( Database::ERROR ), 'subsequent initialization recovers from transient database failure' );
$wpdb->close(); $wpdb = $real_db;

// A second connection proves that capacity/block writers share the same row lock.
$other_db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
$other_db->query( 'START TRANSACTION' );
$other_db->get_row( $other_db->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $a, 1 ) );
$suppression = $wpdb->suppress_errors( true );
bad( Fleet::set_capacity( 9 ), 'concurrent capacity writer cannot bypass the shared row lock' );
bad( Fleet::save_block( $block_input ), 'concurrent block writer cannot bypass the shared row lock' );
$wpdb->suppress_errors( $suppression );
$other_db->query( 'ROLLBACK' );
$other_db->close();
verify( 10 === Fleet::capacity(), 'timed-out concurrent writes preserve capacity' );

$before_rows = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $r ) );
Plugin::activate(); Database::install();
define( 'WP_UNINSTALL_PLUGIN', 'bike-rental-plugin/bike-rental-plugin.php' );
require dirname( __DIR__ ) . '/bike-rental-plugin/uninstall.php';
verify( $before_rows === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $r ) ) && 10 === Fleet::capacity() && $settings === Settings::get(), 'reactivation and uninstall preserve all stored business data' );
update_option( 'brp_m3_verification_id', $id );
update_option( 'brp_m3_verification_count', $before_rows );

$source = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/bike-rental-plugin', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( in_array( $file->getExtension(), array( 'php', 'js' ), true ) ) { $source .= file_get_contents( $file->getPathname() ); }
}
verify( ! preg_match( '/woocommerce_checkout|wp_remote_|wc_create_order|add_to_cart\s*\(|set_stock_quantity\s*\(/', $source ), 'no checkout, orders, remote integration, or stock sync introduced' );
verify( ! preg_match( '/manatee|anna maria island|\bmbr\b/i', $source ), 'generic runtime branding' );
verify( ! str_contains( file_get_contents( dirname( __DIR__ ) . '/bike-rental-plugin/src/Packages.php' ), '$wpdb' ), 'existing package service still uses WooCommerce APIs only' );
echo PHP_EOL . $checks . ' real WordPress/WooCommerce/MariaDB integration checks passed.' . PHP_EOL;
