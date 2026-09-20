<?php
/** Separate-process persistence and missing-WooCommerce checks in the disposable DB. */
if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'BRP_ALLOW_DISPOSABLE_TESTS' ) ) { throw new RuntimeException( "Disposable CLI test opt-in is required.\n" ); }
$root = getenv( 'BRP_TEST_WP_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) { throw new RuntimeException( "Set BRP_TEST_WP_ROOT.\n" ); }
require $root . '/wp-load.php';
if ( 'brp_m3_disposable' !== DB_NAME || '127.0.0.1:33316' !== DB_HOST || 'm3_' !== $wpdb->prefix || class_exists( 'WC_Product' ) ) { throw new RuntimeException( "Requires disposable DB with WooCommerce deactivated.\n" ); }
require dirname( __DIR__ ) . '/bike-rental-plugin/bike-rental-plugin.php';
use BikeRentalPlugin\Database;
use BikeRentalPlugin\DataAdmin;
use BikeRentalPlugin\Fleet;
use BikeRentalPlugin\Plugin;
use BikeRentalPlugin\Reservations;
use BikeRentalPlugin\Settings;
$checks = 0;
function verify_missing( $condition, $label ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$GLOBALS['checks']; echo 'PASS: ' . $label . PHP_EOL;
}
wp_set_current_user( 1 );
Plugin::boot(); Plugin::load_packages(); Database::install();
verify_missing( ! class_exists( 'BikeRentalPlugin\Packages' ), 'WooCommerce package class remains unloaded' );
verify_missing( 10 === Fleet::capacity(), 'fleet persists across PHP processes with WooCommerce absent' );
$id = get_option( 'brp_m3_verification_id' );
$row = Reservations::read( $id );
verify_missing( ! is_wp_error( $row ) && 'cancelled' === $row['status'], 'reservation persists and reads without WooCommerce' );
verify_missing( '83.27' === json_decode( $row['snapshot'], true )['price'], 'snapshot persists independently of WooCommerce' );
verify_missing( (int) get_option( 'brp_m3_verification_count' ) === Database::listing( 'reservations' )['total'], 'all reservation rows persist across processes' );
$result = Reservations::create( array( 'package_product_id' => $row['package_product_id'], 'quantity' => 1, 'start' => '2030-06-17T09:00', 'end' => '2030-06-17T12:00' ) );
verify_missing( is_wp_error( $result ) && 'brp_woocommerce' === $result->get_error_code(), 'manual creation fails gracefully without WooCommerce' );
verify_missing( 10 === Fleet::set_capacity( 10 ), 'fleet management continues without WooCommerce' );
$_GET = array(); $admin = new DataAdmin();
ob_start(); $admin->reservations(); $html = ob_get_clean();
verify_missing( str_contains( $html, 'activate WooCommerce' ) && str_contains( $html, $row['reference'] ), 'missing-WooCommerce list renders existing records and setup guidance' );
$_GET = array( 'id' => $id );
ob_start(); $admin->reservations(); $html = ob_get_clean();
verify_missing( str_contains( $html, '83.27' ), 'reservation detail renders without WooCommerce' );
verify_missing( 'Verification Cycle Shop' === Settings::get()['business_name'], 'settings remain available without WooCommerce' );
$calendar = \BikeRentalPlugin\AdminCalendar::load( substr( $row['occupied_start_utc'], 0, 10 ) );
verify_missing( ! is_wp_error( $calendar ) && ! $calendar['orders'], 'calendar loads gracefully without WooCommerce customer data' );
$_GET = array( 'week' => substr( $row['occupied_start_utc'], 0, 10 ) );
ob_start(); \BikeRentalPlugin\AdminCalendar::render(); $calendar_html = ob_get_clean();
verify_missing( str_contains( $calendar_html, $row['reference'] ), 'calendar displays stored reservations without WooCommerce' );
$_GET = array( 'rental' => 'missing-package' );
verify_missing( str_contains( \BikeRentalPlugin\PublicBooking::shortcode(), 'No rental packages' ), 'deep link degrades safely without WooCommerce' );
echo PHP_EOL . $checks . ' missing-WooCommerce and cross-process persistence checks passed.' . PHP_EOL;
