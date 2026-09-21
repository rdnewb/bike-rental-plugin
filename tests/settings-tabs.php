<?php
/** Real WordPress settings-tab isolation and native form contract checks. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
use BikeRentalPlugin\{Branding, Database, Settings};
$checks = 0; $saved = Settings::get(); $post = $_POST; $get = $_GET; $request = $_REQUEST; $uri = $_SERVER['REQUEST_URI']; $manager = 0;
function tabs_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo "PASS: $label\n"; }
function tabs_dom( $html ) { $doc = new DOMDocument(); @$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html ); return new DOMXPath( $doc ); }
function tabs_render( $tab = null ) {
	$_GET = array( 'page' => Settings::PAGE ); if ( null !== $tab ) { $_GET['tab'] = $tab; }
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?' . http_build_query( $_GET );
	ob_start(); ( new Settings() )->render(); return ob_get_clean();
}
function tabs_save( $tab, $input ) {
	$_POST = array( 'brp_settings_tab' => $tab ); $GLOBALS['wp_settings_errors'] = array();
	$result = ( new Settings() )->sanitize( $input ); update_option( Settings::OPTION, $result ); return $result;
}
try {
	$_POST = array(); $base = Settings::defaults(); $base['business_name'] = 'Existing 0.7.1 business'; $base['booking_horizon'] = 123;
	$base['minimum_notice'] = 120; $base['preparation_buffer'] = 15; $base['turnaround_buffer'] = 45;
	$base['weekly_hours']['monday'] = array( 'open' => 1, 'start' => '08:00', 'end' => '19:00' );
	$base['branding'] = Branding::defaults(); $base['branding']['heading'] = 'Existing heading'; $base['branding']['accent'] = '#abcdef';
	$base['branding']['custom_css'] = '.brp-booking { color: #123456; }'; $base['extension_fixture'] = array( 'retained' => true );
	update_option( Settings::OPTION, $base ); $general = tabs_render(); $branding = tabs_render( 'branding' );
	tabs_check( Settings::get() === $base, 'existing values survive rendering upgrade without migration' );
	foreach ( array( 'general' => $general, 'branding' => $branding ) as $tab => $html ) {
		$dom = tabs_dom( $html );
		tabs_check( 1 === $dom->query( '//h1' )->length && 'Bike Rentals Settings' === $dom->query( '//h1' )->item( 0 )->textContent, "$tab has one page heading" );
		tabs_check( 1 === $dom->query( '//h1/following-sibling::*[1][self::nav]' )->length, "$tab navigation immediately follows heading" );
		tabs_check( 4 === $dom->query( '//nav/a[contains(@class,"nav-tab")]' )->length, "$tab has four normal tab links" );
		foreach ( Settings::tabs() as $key => $label ) { tabs_check( $label === $dom->query( '//nav/a[@href="' . Settings::tab_url( $key ) . '"]' )->item( 0 )->textContent, "$tab links to $key" ); }
		tabs_check( str_contains( $dom->query( '//nav/a[@aria-current="page"]' )->item( 0 )->getAttribute( 'href' ), 'tab=' . $tab ), "$tab is active" );
		tabs_check( 1 === $dom->query( '//form[@method="post"]' )->length && str_ends_with( $dom->query( '//form' )->item( 0 )->getAttribute( 'action' ), '/wp-admin/options.php' ), "$tab uses core Settings API POST endpoint" );
		tabs_check( $tab === $dom->query( '//input[@name="brp_settings_tab"]' )->item( 0 )->getAttribute( 'value' ), "$tab form context is outside option array" );
		$nonce = $dom->query( '//input[@name="_wpnonce"]' )->item( 0 )->getAttribute( 'value' );
		tabs_check( (bool) wp_verify_nonce( $nonce, Settings::GROUP . '-options' ), "$tab has valid core settings nonce" );
		tabs_check( false === wp_verify_nonce( 'invalid', Settings::GROUP . '-options' ), "$tab rejects invalid nonce" );
		$_REQUEST['_wp_http_referer'] = $dom->query( '//input[@name="_wp_http_referer"]' )->item( 0 )->getAttribute( 'value' );
		$_SERVER['REQUEST_URI'] = '/wp-admin/options.php';
		$redirect = add_query_arg( 'settings-updated', 'true', wp_get_referer() ); // Core options.php return contract.
		tabs_check( str_contains( $redirect, 'page=brp-settings' ) && ( 'general' === $tab || str_contains( $redirect, 'tab=branding' ) ), "$tab save returns to its panel" );
		foreach ( array( 'success', 'error' ) as $type ) {
			$GLOBALS['wp_settings_errors'] = array(); add_settings_error( Settings::OPTION, 'tabs_fixture', 'Fixture ' . $type, $type );
			tabs_check( str_contains( tabs_render( $tab ), 'Fixture ' . $type ), "$tab renders $type notice" );
		}
	}
	foreach ( array( 'bogus', '<script>', array( 'branding' ) ) as $bad ) { tabs_check( str_contains( tabs_render( $bad ), 'name="brp_settings_tab" value="general"' ), 'invalid query tab falls back to General' ); }
	$explicit = tabs_dom( tabs_render( 'general' ) );
	tabs_check( str_contains( $explicit->query( '//input[@name="_wp_http_referer"]' )->item( 0 )->getAttribute( 'value' ), 'tab=general' ), 'explicit General URL retained in return field' );
	$_REQUEST['_wpnonce'] = $explicit->query( '//input[@name="_wpnonce"]' )->item( 0 )->getAttribute( 'value' );
	tabs_check( (bool) check_admin_referer( Settings::GROUP . '-options' ), 'core save nonce guard accepts valid form' );
	$die_handler = static function () { return static function () { throw new RuntimeException( 'tabs_core_denied' ); }; };
	add_filter( 'wp_die_handler', $die_handler );
	try {
		$_REQUEST['_wpnonce'] = 'invalid'; $blocked = false;
		try { check_admin_referer( Settings::GROUP . '-options' ); update_option( Settings::OPTION, array( 'bad' => true ) ); } catch ( RuntimeException $e ) { if ( 'tabs_core_denied' !== $e->getMessage() ) { throw $e; } $blocked = true; }
		tabs_check( $blocked && Settings::get() === $base, 'core nonce guard aborts before option mutation' );
		wp_set_current_user( 0 );
		foreach ( array( 'general', 'branding' ) as $tab ) {
			$blocked = false; $level = ob_get_level();
			try { tabs_render( $tab ); } catch ( RuntimeException $e ) { if ( 'tabs_core_denied' !== $e->getMessage() ) { throw $e; } $blocked = true; } finally { while ( ob_get_level() > $level ) { ob_end_clean(); } }
			tabs_check( $blocked, "unauthorized $tab page access blocked" );
		}
	} finally { remove_filter( 'wp_die_handler', $die_handler ); wp_set_current_user( 1 ); }
	tabs_check( ! str_contains( $general, 'name="brp_settings[branding]' ), 'General contains no branding controls' );
	foreach ( array_keys( Settings::defaults() ) as $key ) {
		tabs_check( str_contains( $general, 'name="brp_settings[' . $key . ']' ) && ! str_contains( $branding, 'name="brp_settings[' . $key . ']' ), "operational control belongs only to General: $key" );
	}
	foreach ( array_keys( Branding::defaults() ) as $key ) { tabs_check( str_contains( $branding, 'name="brp_settings[branding][' . $key . ']' ), "branding control retained: $key" ); }
	tabs_check( str_contains( $general, 'Dependencies and integrations' ) && ! str_contains( $branding, 'Dependencies and integrations' ), 'dependencies are General only' );
	tabs_check( ! str_contains( $general, '[restore_defaults]' ) && str_contains( $branding, '[restore_defaults]' ), 'restore is Branding only' );
	$input = $base; unset( $input['branding'] ); $input['business_name'] = 'Updated business';
	$result = tabs_save( 'general', $input ); tabs_check( 'Updated business' === $result['business_name'], 'General change persists' );
	tabs_check( $base['branding'] === $result['branding'], 'General preserves branding exactly' );
	tabs_check( array_keys( $base ) === array_keys( $result ), 'existing option keys unchanged, unknown keys retained' );
	$input['branding'] = array( 'restore_defaults' => '1' ); tabs_check( $result === tabs_save( 'general', $input ), 'General ignores forged branding reset' );
	$before = $result; $new_branding = $base['branding']; $new_branding['heading'] = 'Updated heading';
	$result = tabs_save( 'branding', array( 'branding' => $new_branding, 'payment_mode' => 'deposit', 'business_name' => 'forged' ) );
	tabs_check( 'Updated heading' === $result['branding']['heading'], 'Branding change persists' );
	$operational = $result; unset( $operational['branding'] ); $expected = $before; unset( $expected['branding'] );
	tabs_check( $operational === $expected, 'Branding preserves all General values and ignores forged operational fields' );
	tabs_check( 'full' === $result['payment_mode'], 'Full Payment remains selected' );
	tabs_check( $result === tabs_save( 'branding', array() ), 'incomplete branding form rejected without mutation' );
	tabs_check( $result === tabs_save( 'unknown', array() ), 'invalid submitted panel rejected without mutation' );
	$invalid = $new_branding; $invalid['accent'] = 'bad'; tabs_check( $result === tabs_save( 'branding', array( 'branding' => $invalid ) ), 'invalid Branding preserves entire option' );
	tabs_check( str_contains( tabs_render( 'branding' ), 'Nothing was saved' ), 'branding validation message visible on Branding' );
	$invalid = $input; $invalid['booking_horizon'] = -1; tabs_check( $result === tabs_save( 'general', $invalid ), 'invalid General preserves entire option' );
	tabs_check( str_contains( tabs_render( 'general' ), 'Nothing was saved' ), 'general validation message visible on General' );
	$result = tabs_save( 'branding', array( 'branding' => array( 'restore_defaults' => '1' ) ) );
	tabs_check( Branding::defaults() === $result['branding'], 'restore resets all branding defaults' );
	unset( $result['branding'] ); tabs_check( $result === $expected, 'restore leaves all General values untouched' );
	$manager = wp_insert_user( array( 'user_login' => 'brp_tabs_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
	wp_set_current_user( $manager ); tabs_check( Settings::can_manage() && 'manage_woocommerce' === Settings::capability(), 'shop manager retains settings access' );
	tabs_check( 'Updated heading' === tabs_save( 'branding', array( 'branding' => $new_branding ) )['branding']['heading'], 'shop manager can save branding tab' );
	wp_set_current_user( 0 ); $before = Settings::get(); tabs_check( $before === tabs_save( 'branding', array( 'branding' => $new_branding ) ), 'unauthorized Branding save blocked' );
	tabs_check( $before === tabs_save( 'general', $input ), 'unauthorized General save blocked' );
	wp_set_current_user( 1 ); $_GET = array(); Branding::assets( 'toplevel_page_' . Settings::PAGE );
	tabs_check( ! wp_script_is( 'brp-branding-admin', 'enqueued' ), 'General does not enqueue branding controls' );
	$_GET['tab'] = 'branding'; Branding::assets( 'toplevel_page_' . Settings::PAGE );
	tabs_check( wp_script_is( 'brp-branding-admin', 'enqueued' ), 'Branding enqueues native control helpers' );
	$native_before = Settings::get(); ( new Settings() )->register();
	try {
		$_POST = array( 'brp_settings_tab' => 'general' ); $native_input = $expected; $native_input['business_name'] = 'Settings API save';
		update_option( Settings::OPTION, $native_input ); $native_after = Settings::get();
		tabs_check( 'Settings API save' === $native_after['business_name'] && $native_before['branding'] === $native_after['branding'], 'registered Settings API callback saves General without losing Branding' );
		$_POST = array( 'brp_settings_tab' => 'branding' ); update_option( Settings::OPTION, array( 'branding' => array( 'restore_defaults' => '1' ) ) );
		$native_expected = $native_after; $native_expected['branding'] = Branding::defaults();
		tabs_check( $native_expected === Settings::get(), 'registered Settings API callback resets Branding without losing General' );
	} finally { unregister_setting( Settings::GROUP, Settings::OPTION ); }
	tabs_check( '2' === Database::VERSION, 'schema is 2' );
	if ( $dir = getenv( 'BRP_TABS_FIXTURE_DIR' ) ) { if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); } file_put_contents( $dir . '/tabs-general.html', $general ); file_put_contents( $dir . '/tabs-branding.html', $branding ); }
} finally {
	wp_set_current_user( 1 ); $_POST = array(); update_option( Settings::OPTION, $saved ); $_POST = $post; $_GET = $get; $_REQUEST = $request; $_SERVER['REQUEST_URI'] = $uri;
	if ( $manager ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $manager ); }
}
echo "Settings tabs: $checks checks passed.\n";
ob_end_flush();
