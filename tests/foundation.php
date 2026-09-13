<?php
/**
 * Dependency-free behavioral checks using small WordPress API doubles.
 * This does not simulate options.php, a database, or a WordPress installation.
 * Run: php -n tests/foundation.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', __DIR__ . '/not-a-wordpress-site/' );
error_reporting( E_ALL );
set_error_handler( static function ( $severity, $message, $file, $line ) {
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );

$options = array();
$caps = array( 'manage_options' );
$errors = array();
$hooks = array();
$registered = array();
$installed = array();
$active = array();
$network_active = array();
$is_admin = true;

function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $text ) { return esc_html( $text ); }
function esc_html_e( $text, $domain = '' ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = '' ) { echo esc_attr( $text ); }
// Only a test double. Real WordPress sanitization still needs integration testing.
function sanitize_text_field( $text ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $text ) ) ); }
function current_user_can( $cap, ...$args ) {
	if ( 'edit_post' === $cap && in_array( $args[0] ?? 0, $GLOBALS['denied_posts'] ?? array(), true ) ) { return false; }
	return in_array( $cap, $GLOBALS['caps'], true );
}
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $key, $GLOBALS['options'] ) ) { return false; }
	$GLOBALS['options'][ $key ] = $value;
	return true;
}
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; return true; }
function add_settings_error( $setting, $code, $message, $type = 'error' ) { $GLOBALS['errors'][] = $message; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function add_filter( $hook, $callback ) { add_action( $hook, $callback ); }
function register_activation_hook( $file, $callback ) { $GLOBALS['activation'] = $callback; }
function register_deactivation_hook( $file, $callback ) { $GLOBALS['deactivation'] = $callback; }
function register_setting( $group, $option, $args ) { $GLOBALS['registered'][ $option ] = array( $group, $args ); }
function is_admin() { return $GLOBALS['is_admin']; }
function get_plugins() { return $GLOBALS['installed']; }
function is_plugin_active( $file ) { return in_array( $file, $GLOBALS['active'], true ); }
function is_plugin_active_for_network( $file ) { return in_array( $file, $GLOBALS['network_active'], true ); }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function wp_timezone_string() { return get_option( 'timezone_string', '' ) ?: '+00:00'; }
function settings_errors() { echo '<div>Test settings errors placeholder</div>'; }
function settings_fields( $group ) { echo '<input type="hidden" name="test-only-group" value="' . esc_attr( $group ) . '">'; }
function selected( $a, $b ) { if ( (string) $a === (string) $b ) { echo 'selected="selected"'; } }
function submit_button( $label ) { echo '<button>' . esc_html( $label ) . '</button>'; }
function wp_die( $message ) { throw new RuntimeException( $message ); }

require dirname( __DIR__ ) . '/bike-rental-plugin/bike-rental-plugin.php';

use BikeRentalPlugin\Plugin;
use BikeRentalPlugin\Settings;

$checks = 0;
function check( $condition, $label ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$GLOBALS['checks'];
	echo 'PASS: ' . $label . PHP_EOL;
}

$settings = new Settings();
$defaults = Settings::defaults();
check( '0.4.0' === Plugin::VERSION, 'version constant' );
check( isset( $hooks['plugins_loaded'] ) && ! isset( $hooks['admin_init'] ), 'bootstrap defers initialization' );
check( 90 === $defaults['booking_horizon'] && 30 === $defaults['time_increment'], 'neutral scheduling defaults' );
check( 0 === array_sum( array_column( $defaults['weekly_hours'], 'open' ) ), 'all seven days default closed' );
check( 'Not Configured' === Settings::configuration_status( $defaults ), 'default readiness' );
check( array() === Settings::validate( $defaults )['errors'], 'defaults validate' );

Plugin::activate();
check( $defaults === $options[ Settings::OPTION ], 'activation initializes missing settings' );
$valid = $defaults;
$valid['business_name'] = 'Coastal Cycles';
$valid['booking_horizon'] = 120;
$valid['weekly_hours']['monday']['open'] = 1;
$options[ Settings::OPTION ] = $valid;
Plugin::activate();
Plugin::record_version();
check( $valid === $options[ Settings::OPTION ], 'reactivation and version handling preserve saved settings' );
check( '0.4.0' === $options['brp_plugin_version'], 'version recorded independently of settings' );
check( 'Ready for Package Setup' === Settings::configuration_status( $valid ), 'valid business and open day ready for package setup' );
$partial = $defaults;
$partial['business_name'] = 'Coastal Cycles';
check( 'Partially Configured' === Settings::configuration_status( $partial ), 'closed business remains partial' );

$bad_values = array( -1, '-1', '1.5', '1e2', true, array(), null, '999999999999999999999999999' );
foreach ( array( 'booking_horizon', 'minimum_notice', 'preparation_buffer', 'turnaround_buffer' ) as $field ) {
	foreach ( $bad_values as $bad ) {
		$input = $valid;
		$input[ $field ] = $bad;
		check( ! empty( Settings::validate( $input )['errors'] ), $field . ' rejects ' . json_encode( $bad ) );
	}
}
foreach ( array( 'booking_horizon' => array( 0, 366 ), 'minimum_notice' => array( 10081 ), 'preparation_buffer' => array( 1441 ), 'turnaround_buffer' => array( 1441 ) ) as $field => $bad ) {
	foreach ( $bad as $value ) {
		$input = $valid;
		$input[ $field ] = $value;
		check( ! empty( Settings::validate( $input )['errors'] ), $field . ' bounds enforced' );
	}
}
foreach ( array( 'booking_horizon' => array( 1, 365 ), 'minimum_notice' => array( 0, 10080 ), 'preparation_buffer' => array( 0, 1440 ), 'turnaround_buffer' => array( 0, 1440 ) ) as $field => $bounds ) {
	foreach ( $bounds as $value ) {
		$input = $valid;
		$input[ $field ] = (string) $value;
		check( empty( Settings::validate( $input )['errors'] ), $field . ' accepts boundary ' . $value );
	}
}
foreach ( array( 5, 10, 15, 20, 30, 60 ) as $increment ) {
	$input = $valid;
	$input['time_increment'] = (string) $increment;
	check( empty( Settings::validate( $input )['errors'] ), 'supported increment ' . $increment );
}
foreach ( array( 0, 7, 90, '30.0', array() ) as $bad ) {
	$input = $valid;
	$input['time_increment'] = $bad;
	check( ! empty( Settings::validate( $input )['errors'] ), 'invalid increment ' . json_encode( $bad ) );
}
foreach ( array( '', '24:00', '12:60', '9:00', '09:00:00', "09:00\n", array() ) as $bad ) {
	$input = $valid;
	$input['pickup_time'] = $bad;
	check( ! empty( Settings::validate( $input )['errors'] ), 'invalid pickup time ' . json_encode( $bad ) );
}
foreach ( array( '08:59', '09:00' ) as $end ) {
	$input = $valid;
	$input['weekly_hours']['monday']['end'] = $end;
	check( ! empty( Settings::validate( $input )['errors'] ), 'open day closing must follow opening' );
}
$input = $valid;
unset( $input['weekly_hours']['sunday'] );
check( ! empty( Settings::validate( $input )['errors'] ), 'missing weekday rejected' );
$input = $valid;
$input['weekly_hours']['monday']['open'] = 'yes';
check( ! empty( Settings::validate( $input )['errors'] ), 'unknown open flag rejected' );
foreach ( array( null, true, 'string', 12 ) as $bad ) {
	check( ! empty( Settings::validate( $bad )['errors'] ), 'malformed option rejected without warning' );
}
foreach ( array( array( 'name' ), str_repeat( 'x', 241 ) ) as $bad ) {
	$input = $valid;
	$input['business_name'] = $bad;
	check( ! empty( Settings::validate( $input )['errors'] ), 'invalid business name rejected' );
}
$input = $valid;
$input['business_name'] = "  <b>Coastal Cycles</b>\n";
$input['booking_horizon'] = '120';
$input['injected_option'] = 'not allowed';
$clean = $settings->sanitize( $input );
check( 'Coastal Cycles' === $clean['business_name'] && 120 === $clean['booking_horizon'], 'plain text and integer normalization' );
check( ! isset( $clean['injected_option'] ), 'unknown keys cannot be persisted' );
$input['minimum_notice'] = '-1';
check( $valid === $settings->sanitize( $input ) && count( $errors ) >= 2, 'invalid form preserves entire old value and explains error' );

$caps = array( 'manage_woocommerce' );
check( Settings::can_manage() && 'manage_woocommerce' === Settings::capability(), 'WooCommerce management capability accepted without role mutation' );
check( 'Coastal Cycles' === $settings->sanitize( $valid )['business_name'], 'shop manager validation allowed' );
$caps = array();
check( ! Settings::can_manage(), 'ordinary customer denied' );
check( $valid === $settings->sanitize( $defaults ), 'unauthorized sanitizer preserves old option' );
try { $settings->render(); check( false, 'unauthorized render' ); } catch ( RuntimeException $e ) {
	check( str_contains( $e->getMessage(), 'permission' ), 'unauthorized page denied' );
}
$caps = array( 'manage_options' );
check( 'manage_options' === Settings::capability(), 'administrator access without WooCommerce' );
$settings->register();
check( false === $registered[ Settings::OPTION ][1]['show_in_rest'], 'settings not exposed through REST' );
Plugin::boot();
check( isset( $hooks['option_page_capability_' . Settings::GROUP] ), 'core settings save uses correct capability filter' );
$hook_count = count( $hooks['admin_init'] );
Plugin::boot();
check( $hook_count === count( $hooks['admin_init'] ), 'duplicate bootstrap does not register twice' );

$dependencies = Plugin::dependencies();
check( 0 === count( array_filter( $dependencies, static fn( $row ) => $row['available'] ) ), 'missing dependencies handled gracefully' );
$installed = array(
	'renamed-commerce/entry.php' => array( 'Name' => 'WooCommerce', 'TextDomain' => 'woocommerce' ),
	'custom-square/start.php' => array( 'Name' => 'WooCommerce Square', 'TextDomain' => 'woocommerce-square' ),
	'forms/main.php' => array( 'Name' => 'WPForms Lite', 'TextDomain' => 'wpforms-lite' ),
	'sign-addon/boot.php' => array( 'Name' => 'WPForms Signatures' ),
	'payment-plugin/main.php' => array( 'Name' => 'Deposits for WooCommerce' ),
	'unrelated/sign.php' => array( 'Name' => 'Other Signature Plugin' ),
);
$active = array( 'custom-square/start.php', 'forms/main.php', 'sign-addon/boot.php', 'payment-plugin/main.php' );
$dependencies = Plugin::dependencies();
check( ! $dependencies['woocommerce']['available'] && count( $dependencies['woocommerce']['installed'] ) === 1, 'installed inactive dependency not available' );
check( $dependencies['square']['available'], 'renamed Square directory detected by headers' );
check( $dependencies['wpforms']['available'], 'WPForms Lite detected without claiming Elite licensing' );
check( $dependencies['signature']['available'], 'signature addon identified by product name' );
check( $dependencies['deposits']['available'], 'other deposit candidate visible' );
$network_active = array( 'renamed-commerce/entry.php' );
check( Plugin::dependencies()['woocommerce']['available'], 'network-active dependency detected' );

$options['timezone_string'] = '';
ob_start(); $settings->render(); $html = ob_get_clean();
check( str_contains( $html, 'fixed offset' ), 'fixed-offset timezone warning rendered' );
check( 5 === substr_count( $html, 'Not yet integration tested' ), 'dependency presence never implies tested integration' );
check( str_contains( $html, 'action="https://example.test/wp-admin/options.php"' ), 'settings post to WordPress core handler' );
check( str_contains( $html, 'value="brp_settings_group"' ), 'view requests Settings API nonce group' );
check( 7 === substr_count( $html, 'name="brp_settings[weekly_hours][' ) / 3, 'all seven weekdays have three controls' );
$options['timezone_string'] = 'America/New_York';
ob_start(); $settings->render(); $html = ob_get_clean();
check( ! str_contains( $html, 'fixed offset' ), 'named timezone avoids fixed-offset warning' );
$options[ Settings::OPTION ] = 'corrupt';
ob_start(); $settings->render(); $html = ob_get_clean();
check( str_contains( $html, 'Stored settings need repair' ) && 'corrupt' === $options[ Settings::OPTION ], 'malformed persisted settings repairable without implicit writes' );
$options[ Settings::OPTION ] = $valid;
$options[ Settings::OPTION ]['business_name'] = 'Shop " onfocus="alert(1)';
ob_start(); $settings->render(); $html = ob_get_clean();
check( str_contains( $html, 'Shop &quot; onfocus=&quot;alert(1)' ), 'business name escaped in attribute output' );

define( 'WP_UNINSTALL_PLUGIN', 'bike-rental-plugin/bike-rental-plugin.php' );
$before_uninstall = $options;
require dirname( __DIR__ ) . '/bike-rental-plugin/uninstall.php';
check( $before_uninstall === $options, 'uninstall preserves all settings' );
$header = file_get_contents( dirname( __DIR__ ) . '/bike-rental-plugin/bike-rental-plugin.php' );
check( str_contains( $header, 'Version: ' . Plugin::VERSION ), 'plugin header and internal version agree' );

echo PHP_EOL . $checks . ' checks passed. WordPress API doubles only; no live WordPress tests performed.' . PHP_EOL;
