<?php
/**
 * Package checks using WordPress/WooCommerce doubles, including the foundation suite.
 * Run: php -n tests/packages.php. No live WordPress or WooCommerce is loaded.
 */
if ( 'cli' !== PHP_SAPI ) { exit; }

require __DIR__ . '/foundation.php';

use BikeRentalPlugin\Packages;
use BikeRentalPlugin\Plugin;
use BikeRentalPlugin\Settings;

$foundation_checks = $checks;
Plugin::load_packages();
check( ! class_exists( Packages::class, false ), 'WooCommerce absent: integration class is not loaded' );
$screen = (object) array( 'id' => 'toplevel_page_brp-settings' );
function get_current_screen() { return $GLOBALS['screen']; }
ob_start(); Plugin::packages_dependency_notice(); $notice = ob_get_clean();
check( str_contains( $notice, 'Install or activate WooCommerce' ), 'missing WooCommerce actionable notice' );

// Conditional declarations intentionally become available after the missing-Woo checks.
if ( true ) {
	class WC_Product {
		public $id;
		public $type = 'simple';
		public $meta = array();
		public $regular_price = '';
		public $sale_price = '';
		public $name = 'Sample Rental';
		public $status = 'publish';
		public $menu_order = 0;
		public $save_count = 0;
		public function __construct( $id ) { $this->id = $id; }
		public function get_id() { return $this->id; }
		public function is_type( $type ) { return $this->type === $type; }
		public function get_meta( $key, $single = true, $context = 'view' ) { return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
		public function get_regular_price( $context = 'view' ) { return $this->regular_price; }
		public function get_name( $context = 'view' ) { return $this->name; }
		public function get_status( $context = 'view' ) { return $this->status; }
		public function get_menu_order( $context = 'view' ) { return $this->menu_order; }
		public function get_tax_status( $context = 'view' ) { return 'taxable'; }
		public function get_tax_class( $context = 'view' ) { return ''; }
		public function save() { ++$this->save_count; $GLOBALS['products'][ $this->id ] = clone $this; }
	}
	class WC_Admin_Meta_Boxes {
		public static $errors = array();
		public static function add_error( $message ) { self::$errors[] = $message; }
	}
	function wc_get_product( $id ) { return isset( $GLOBALS['products'][ $id ] ) ? clone $GLOBALS['products'][ $id ] : false; }
	function wc_get_products( $args ) {
		$GLOBALS['last_query'] = $args;
		$filtered = array_filter( $GLOBALS['products'], static function ( $product ) use ( $args ) {
			return 'yes' === $product->get_meta( Packages::ENABLED )
				&& ( ! isset( $args['type'] ) || $product->is_type( $args['type'] ) )
				&& in_array( $product->status, (array) $args['status'], true );
		} );
		usort( $filtered, static fn( $a, $b ) => array( $a->menu_order, $a->name, $a->id ) <=> array( $b->menu_order, $b->name, $b->id ) );
		if ( ! empty( $args['paginate'] ) ) {
			return (object) array( 'products' => array_slice( $filtered, ( $args['page'] - 1 ) * $args['limit'], $args['limit'] ), 'max_num_pages' => (int) ceil( count( $filtered ) / $args['limit'] ) );
		}
		return $filtered;
	}
	function get_woocommerce_currency() { return 'USD'; }
	function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
	function wp_verify_nonce( $nonce, $action ) { $GLOBALS['nonce_action'] = $action; return 'valid-' . $action === $nonce; }
	function wp_nonce_field( $action, $name ) { echo '<input name="' . esc_attr( $name ) . '" value="valid-' . esc_attr( $action ) . '">'; }
	function checked( $value ) { if ( $value ) { echo 'checked="checked"'; } }
	function wp_kses_post( $html ) { return strip_tags( $html, '<span><bdi>' ); }
	function wc_price( $price ) { return '<span>' . esc_html( $price ) . '</span>'; }
	function get_edit_post_link( $id, $context = '' ) { return admin_url( 'post.php?post=' . $id . '&action=edit' ); }
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
	function plugins_url( $path, $file ) { return 'https://example.test/wp-content/plugins/bike-rental-plugin/' . $path; }
	function wp_enqueue_script( ...$args ) { $GLOBALS['enqueued'][] = $args; }
	function add_submenu_page( ...$args ) { $GLOBALS['submenus'][] = $args; }
}

$products = array();
$caps = array( 'manage_options', 'edit_products', 'edit_post', 'publish_products' );
Plugin::load_packages();
check( class_exists( Packages::class, false ), 'package class loads after WooCommerce is ready' );
check( isset( $hooks['woocommerce_admin_process_product_object'] ), 'save hook registered before WooCommerce CRUD save' );
check( isset( $hooks['woocommerce_product_data_store_cpt_get_products_query'] ), 'supported custom product query hook registered' );
$hook_count = count( $hooks['woocommerce_admin_process_product_object'] );
Plugin::load_packages();
check( $hook_count === count( $hooks['woocommerce_admin_process_product_object'] ), 'package hook registration is idempotent' );
$packages = new Packages();
ob_start(); Plugin::packages_dependency_notice(); $notice = ob_get_clean();
check( '' === $notice, 'dependency notice disappears when WooCommerce is ready' );

function save_rental( $product, $input ) {
	$_POST = array( 'brp_package' => $input, 'brp_package_nonce' => 'valid-brp_save_package_' . $product->id );
	$GLOBALS['packages']->save_product( $product );
	$product->save(); // Emulate WooCommerce's surrounding save, not a plugin write.
}

$input = array( 'enabled' => 'yes', 'duration_type' => 'hours', 'duration_amount' => '4', 'promotional_label' => '', 'active' => 'yes' );
$product = new WC_Product( 11 );
$product->regular_price = '73.25'; // Test fixture only, never a runtime default.
$product->meta['unrelated_extension'] = 'keep';
$product->save();
check( ! Packages::is_rental_package( 11 ) && null === Packages::get_package( 11 ), 'ordinary product is not a rental package' );
check( null === Packages::get_current_price( 11 ), 'normal product has no package price' );
save_rental( $product, $input );
check( Packages::is_rental_package( 11 ), 'product marked as rental package' );
$package = Packages::get_package( 11 );
check( 'hours' === $package['duration_type'] && 4 === $package['duration_amount'], 'hours duration persists through CRUD double' );
check( true === $package['active'] && 'publish' === $package['product_status'], 'rental active independent from publication status' );
check( '73.25' === $package['price'] && 'USD' === $package['currency'], 'regular price returned as decimal string' );
check( 'taxable' === $package['tax_status'], 'WooCommerce tax configuration exposed without calculation' );
$product->regular_price = '82.17';
$product->sale_price = '12.34';
$product->save();
check( '82.17' === Packages::get_package( 11 )['price'], 'current regular price reflects changes and ignores sale price' );
$days = $input;
$days['duration_type'] = 'calendar_days';
$days['duration_amount'] = '3';
$days['promotional_label'] = "  <b>Flexible Offer</b>\n";
save_rental( $product, $days );
$package = Packages::get_package( 11 );
check( 'calendar_days' === $package['duration_type'] && 3 === $package['duration_amount'], 'calendar-day duration persists' );
check( 'Flexible Offer' === $package['promotional_label'], 'promotional label normalized to plain text' );
check( '82.17' === $package['price'], 'promotional label has no pricing effect' );
$days['active'] = 'no';
save_rental( $product, $days );
check( ! Packages::get_package( 11 )['active'] && array() === Packages::get_active_packages(), 'inactive persists and is excluded from active list' );
$days['active'] = 'yes';
save_rental( $product, $days );
check( 1 === count( Packages::get_active_packages() ), 'valid active published rental included' );
check( 'menu_order title ID' === $last_query['orderby'], 'active list uses WooCommerce ordering' );

foreach ( array( 0, -1, '1.5', '1e2', true, array(), '', null, '9999999999999999999999', '8761' ) as $bad ) {
	$invalid = $input;
	$invalid['duration_amount'] = $bad;
	$before = $product->meta;
	$error_count = count( WC_Admin_Meta_Boxes::$errors );
	save_rental( $product, $invalid );
	check( $before === $product->meta && count( WC_Admin_Meta_Boxes::$errors ) > $error_count, 'invalid duration rejected: ' . json_encode( $bad ) );
}
foreach ( array( 'duration_type' => 'weeks', 'active' => 'true', 'enabled' => '1', 'promotional_label' => str_repeat( 'x', 121 ) ) as $field => $bad ) {
	$invalid = $input;
	$invalid[ $field ] = $bad;
	$before = $product->meta;
	save_rental( $product, $invalid );
	check( $before === $product->meta, 'invalid ' . $field . ' rejected without partial metadata changes' );
}
foreach ( array( 'duration_type', 'active', 'promotional_label', 'enabled' ) as $field ) {
	$invalid = $input;
	$invalid[ $field ] = array( 'attack' );
	check( ! empty( Packages::validate( $invalid )['errors'] ), 'array rejected for ' . $field );
}
check( ! empty( Packages::validate( 'malformed' )['errors'] ), 'malformed form rejected safely' );
foreach ( array( array( 'hours', 1 ), array( 'hours', 8760 ), array( 'calendar_days', 1 ), array( 'calendar_days', 365 ) ) as $boundary ) {
	$valid_boundary = $input;
	$valid_boundary['duration_type'] = $boundary[0];
	$valid_boundary['duration_amount'] = $boundary[1];
	check( empty( Packages::validate( $valid_boundary )['errors'] ), 'duration boundary accepted ' . implode( ' ', $boundary ) );
}
$invalid = $days;
$invalid['duration_amount'] = 366;
check( ! empty( Packages::validate( $invalid )['errors'] ), 'calendar-day upper limit enforced' );
$unicode = $input;
$unicode['promotional_label'] = str_repeat( 'é', 120 );
check( empty( Packages::validate( $unicode )['errors'] ), 'promotional limit counts characters not bytes' );

foreach ( array( 0, -11, '11abc', 11.0, array(), true, '999999999999999999999999', '999' ) as $bad_id ) {
	check( null === Packages::get_package( $bad_id ), 'invalid/missing product ID handled: ' . json_encode( $bad_id ) );
}
$before = $product->meta;
foreach ( array( null, 'invalid', array( 'token' ), 'valid-brp_save_package_12' ) as $nonce ) {
	$_POST = array( 'brp_package' => array( 'enabled' => 'no' ) );
	if ( null !== $nonce ) { $_POST['brp_package_nonce'] = $nonce; }
	$packages->save_product( $product );
	check( $before === $product->meta, 'nonce gate rejects ' . json_encode( $nonce ) );
}
check( 'brp_save_package_11' === $nonce_action, 'nonce bound to actual product ID' );
$caps = array( 'manage_woocommerce' );
save_rental( $product, array( 'enabled' => 'no' ) );
check( $before === $product->meta, 'management capability alone does not grant product editing' );
$caps = array( 'edit_products' );
save_rental( $product, array( 'enabled' => 'no' ) );
check( $before === $product->meta, 'object-level edit permission also required' );
$caps = array( 'edit_products', 'edit_post' );
$denied_posts = array( 11 );
save_rental( $product, array( 'enabled' => 'no' ) );
check( $before === $product->meta, 'another product edit permission does not authorize this product' );
$denied_posts = array();
$_POST = array();
$packages->save_product( $product );
check( $before === $product->meta, 'missing panel payload leaves quick/bulk/other saves untouched' );
$old_save_count = $product->save_count;
$_POST = array( 'brp_package' => $days, 'brp_package_nonce' => 'valid-brp_save_package_11' );
$packages->save_product( $product );
check( $old_save_count === $product->save_count, 'plugin stages metadata without recursively calling product save' );

$product->type = 'variable';
$product->save();
check( ! Packages::is_rental_package( 11 ) && null === Packages::get_package( 11 ), 'non-simple product excluded even with old marker' );
$before = $product->meta;
save_rental( $product, $input );
check( $before === $product->meta, 'enabling unsupported product type rejected' );
$product->type = 'simple';
$product->save();
save_rental( $product, array( 'enabled' => 'no' ) );
check( ! Packages::is_rental_package( 11 ), 'rental identification can be removed' );
check( array( 'unrelated_extension' => 'keep' ) === $product->meta, 'removal clears only five owned metadata keys' );
check( '82.17' === $product->regular_price && '12.34' === $product->sale_price, 'removal leaves commerce prices unchanged' );
save_rental( $product, $input );
$before = $product->meta;
Plugin::activate();
Plugin::record_version();
require dirname( __DIR__ ) . '/bike-rental-plugin/uninstall.php';
check( $before === $product->meta, 'activation/version/uninstall never modify package metadata' );

foreach ( array( '', '-1.00', 'broken' ) as $price ) {
	$product->regular_price = $price;
	$product->save();
	check( null === Packages::get_current_price( 11 ) && array() === Packages::get_active_packages(), 'unset/invalid price excluded: ' . $price );
}
$product->regular_price = '0';
$product->save();
check( '0' === Packages::get_current_price( 11 ) && 1 === count( Packages::get_active_packages() ), 'explicit zero price distinguishable from missing price' );
$product->status = 'draft';
$product->save();
check( Packages::get_package( 11 )['active'] && array() === Packages::get_active_packages(), 'draft excluded despite enabled rental active flag' );
$product->status = 'publish';
$product->regular_price = '82.17';
$product->save();
$second = clone $product;
$second->id = 12;
$second->menu_order = -5;
$second->save();
check( array( 12, 11 ) === array_column( Packages::get_active_packages(), 'product_id' ), 'package order follows existing menu order' );
$second->meta[ Packages::AMOUNT ] = 0;
$second->save();
check( 1 === count( Packages::get_active_packages() ), 'corrupt duration excluded from active list' );

$existing_query = array( 'meta_query' => array( 'relation' => 'OR', array( 'key' => 'other', 'value' => 'x' ) ) );
check( $existing_query === $packages->filter_query( $existing_query, array() ), 'ordinary WooCommerce query untouched' );
$filtered = $packages->filter_query( $existing_query, array( 'brp_rental_packages' => true ) );
check( 'AND' === $filtered['meta_query']['relation'] && $existing_query['meta_query'] === $filtered['meta_query'][0], 'rental constraint ANDs existing query without weakening OR groups' );
check( Packages::ENABLED === $filtered['meta_query'][1]['key'], 'query only requests owned marker through WC extension point' );

$caps = array( 'edit_products', 'edit_post', 'publish_products' );
$tabs = $packages->add_tab( array( 'general' => array( 'label' => 'General' ) ) );
check( isset( $tabs['general'], $tabs['brp_rental'] ) && array( 'show_if_simple' ) === $tabs['brp_rental']['class'], 'native rental tab preserves existing tabs and limits simple products' );
$product_object = $product;
$product_object->meta[ Packages::PROMO ] = 'Offer " onfocus="alert(1)';
ob_start(); $packages->render_panel(); $html = ob_get_clean();
check( str_contains( $html, 'brp_package_nonce' ) && str_contains( $html, 'valid-brp_save_package_11' ), 'panel renders product-bound nonce' );
check( str_contains( $html, 'Offer &quot; onfocus=&quot;alert(1)' ), 'promotional label escaped in editor attributes' );
check( str_contains( $html, 'brp_package[enabled]' ) && str_contains( $html, 'brp_package[active]' ), 'panel includes separate identification and active controls' );
$panel_before = $product_object->meta;
$product_object->meta[ Packages::TYPE ] = array( 'corrupt' );
$product_object->meta[ Packages::AMOUNT ] = array( 'corrupt' );
$product_object->meta[ Packages::PROMO ] = array( 'corrupt' );
ob_start(); $packages->render_panel(); $html = ob_get_clean();
check( str_contains( $html, 'brp-duration-type' ), 'corrupt rental fields render safely for repair' );
$product_object->meta = $panel_before;
$screen = (object) array( 'id' => 'product' );
$packages->enqueue();
check( 'brp-package-admin' === $enqueued[0][0] && str_contains( $enqueued[0][1], '/assets/js/package-admin.js' ), 'admin asset loaded on product editor' );
$screen->id = 'dashboard';
$packages->enqueue();
check( 1 === count( $enqueued ), 'no package script on unrelated admin pages' );
$packages->add_menu();
check( 'edit_products' === $submenus[0][3], 'overview requires product-edit capability' );
$product->name = '<script>bad()</script>';
$product->save();
$_GET = array();
ob_start(); $packages->render_overview(); $html = ob_get_clean();
check( str_contains( $html, '&lt;script&gt;bad()&lt;/script&gt;' ) && ! str_contains( $html, '<script>' ), 'overview escapes product title' );
check( str_contains( $html, 'Needs review' ), 'corrupt rental metadata remains discoverable for repair' );
$caps = array();
try { $packages->render_overview(); check( false, 'overview denied' ); } catch ( RuntimeException $error ) {
	check( str_contains( $error->getMessage(), 'permission' ), 'unauthorized overview rejected' );
}
check( array() === $packages->add_tab( array() ), 'product tab hidden from unauthorized user' );

$runtime = dirname( __DIR__ ) . '/bike-rental-plugin';
$source = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $runtime, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( in_array( $file->getExtension(), array( 'php', 'js' ), true ) ) { $source .= file_get_contents( $file->getPathname() ); }
}
check( ! preg_match( '/manatee|\bmbr\b|anna maria island/i', $source ), 'generic runtime branding' );
check( ! preg_match( '/set_(?:regular_price|sale_price|price|stock_quantity)\s*\(|\b(?:49\.00|79\.00|158\.00|229\.00)\b/', $source ), 'no hard-coded selling price or stock mutations' );
check( ! preg_match( '/add_shortcode\s*\(|register_rest_route\s*\(|\$wpdb|woocommerce_checkout|wp_remote_/', $source ), 'no public booking, checkout, raw SQL, or remote integration' );
echo PHP_EOL . ( $checks - $foundation_checks ) . ' package checks + ' . $foundation_checks . ' foundation checks = ' . $checks . ' passed. API doubles only; test-site verification remains required.' . PHP_EOL;
