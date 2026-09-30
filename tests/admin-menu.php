<?php
/** Menu registration contract with API doubles, including core's documented collision rule. */
require __DIR__ . '/foundation.php';
require_once dirname( __DIR__ ) . '/bike-rental-plugin/src/Packages.php';

function add_menu_page( $title, $label, $cap, $slug, $callback = '', $icon = '', $position = null ) {
	$GLOBALS['top_calls'][] = func_get_args();
	$key = (string) $position;
	if ( isset( $GLOBALS['menu'][ $key ] ) ) {
		$key = (string) ( $position + (int) base_convert( substr( md5( $slug . $label ), -4 ), 16, 10 ) * 0.00001 );
	}
	$GLOBALS['menu'][ $key ] = array( $label, $cap, $slug );
	return 'toplevel_page_' . $slug;
}
function add_submenu_page( ...$args ) { $GLOBALS['sub_calls'][] = $args; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

$baseline = $checks;
foreach ( array( 'manage_options', 'manage_woocommerce' ) as $cap ) {
	$caps = array( $cap );
	foreach ( array( false, true ) as $collision ) {
		$menu = array( 2 => array( 'Dashboard', 'read', 'index.php' ), '55.5' => array( 'WooCommerce', 'edit_others_shop_orders', 'woocommerce' ), '56.1' => array( 'Nearby', 'read', 'nearby' ) );
		if ( $collision ) { $menu[56] = array( 'Other plugin', 'read', 'other-plugin' ); }
		$before = $menu; $top_calls = $sub_calls = array();
		$settings->add_menu();
		(new BikeRentalPlugin\DataAdmin())->menus();
		(new BikeRentalPlugin\Packages())->add_menu();
		check( count( $top_calls ) === 1 && $top_calls[0][3] === 'brp-settings' && $top_calls[0][1] === 'Bike Rentals', 'one top-level menu with original slug' );
		check( $top_calls[0][6] === 56 && $top_calls[0][5] === 'dashicons-location-alt', 'explicit position 56 and original icon' );
		check( $top_calls[0][2] === $cap && $top_calls[0][4] === array( $settings, 'render' ), 'original capability and callback retained' );
		check( count( array_filter( $menu, static fn( $item ) => $item[2] === 'brp-settings' ) ) === 1 && array_intersect_key( $menu, $before ) === $before, 'nearby and colliding menus preserved without duplicate Bike Rentals' );
		check( array_column( $sub_calls, 4 ) === array( 'brp-settings', 'brp-fleet', 'brp-reservations', 'brp-calendar', 'brp-availability', 'brp-packages' ), 'all submenu slugs and registration order unchanged' );
		check( array_unique( array_column( $sub_calls, 0 ) ) === array( 'brp-settings' ) && array_column( $sub_calls, 3 ) === array( $cap, $cap, $cap, $cap, $cap, 'edit_products' ), 'submenu parents and capabilities unchanged' );
		foreach ( $sub_calls as $item ) { check( is_callable( $item[5] ), 'submenu callback remains callable: ' . $item[4] ); }
	}
}
check( BikeRentalPlugin\DataAdmin::url( 'brp-reservations', 7 ) === 'https://example.test/wp-admin/admin.php?page=brp-reservations&id=7&paged=1', 'direct reservation URL unchanged' );
check( BikeRentalPlugin\Settings::tab_url( 'license' ) === 'https://example.test/wp-admin/admin.php?page=brp-settings&tab=license' && BikeRentalPlugin\Settings::tab_url( 'waivers' ) === 'https://example.test/wp-admin/admin.php?page=brp-settings&tab=waivers', 'direct license and waiver tab URLs unchanged' );
$caps = array( 'read' );
check( ! BikeRentalPlugin\Settings::can_manage(), 'unauthorized users still denied' );
check( BikeRentalPlugin\Database::VERSION === '2', 'schema remains 2' );
echo ( $checks - $baseline ) . " menu checks passed, plus $baseline foundation checks. API doubles; live sidebar QA remains required.\n";
