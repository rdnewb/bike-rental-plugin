<?php
/** Real WordPress presentation/security checks; opt-in disposable database only. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
use BikeRentalPlugin\{Branding, BookingSchedule, Database, Packages, PublicBooking, Settings};
$checks = 0; $saved = Settings::get(); $get = $_GET; $products = array(); $attachment = 0; $manager = 0;
function brand_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo "PASS: $label\n"; }
function brand_dom( $html ) { $doc = new DOMDocument(); @$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html ); return new DOMXPath( $doc ); }
function brand_save( $branding ) { $input = Settings::get(); $input['branding'] = $branding; $values = ( new Settings() )->sanitize( $input ); update_option( Settings::OPTION, $values ); return $values; }
try {
	$base = Settings::defaults(); $base['business_name'] = 'Generic fixture'; update_option( Settings::OPTION, $base ); $_GET = array();
	foreach ( array( array( 'Four hour ride', 'hours', 4 ), array( 'Three day ride', 'calendar_days', 3 ), array( 'Seven day ride', 'calendar_days', 7 ) ) as $definition ) {
		$p = new WC_Product_Simple(); $p->set_name( $definition[0] ); $p->set_slug( sanitize_title( $definition[0] ) ); $p->set_status( 'publish' ); $p->set_regular_price( '69' );
		foreach ( array( Packages::ENABLED => 'yes', Packages::ACTIVE => 'yes', Packages::TYPE => $definition[1], Packages::AMOUNT => $definition[2] ) as $key => $value ) { $p->update_meta_data( $key, $value ); }
		$p->save(); $products[] = $p;
	}
	$default_html = PublicBooking::shortcode(); $dom = brand_dom( $default_html );
	brand_check( str_contains( $default_html, '1. Choose Your Rental' ), 'default heading preserved' );
	brand_check( str_contains( $default_html, '>Select Rental</button>' ) && str_contains( $default_html, '>Change Rental</button>' ), 'default button labels preserved' );
	brand_check( $dom->query( '//section[@class="brp-booking"]' )->item( 0 )->getAttribute( 'style' ) === '', 'untouched branding emits no CSS overrides' );
	brand_check( ! str_contains( $default_html, 'class="brp-logo"' ), 'missing logo emits no image' );
	brand_check( ! array_key_exists( 'branding', Settings::get() ), 'render does not migrate or persist defaults' );
	$branding = Branding::defaults(); $branding['heading'] = ''; $branding['intro'] = ''; brand_save( $branding );
	$html = PublicBooking::shortcode(); $dom = brand_dom( $html );
	brand_check( 0 === $dom->query( '//h2[contains(@id,"-choose")]' )->length && 1 === $dom->query( '//section[@aria-label="Rental packages"]' )->length, 'empty heading omitted with accessible section name' );
	brand_check( ! str_contains( $html, 'class="brp-intro"' ), 'empty intro omitted' );
	$branding['heading'] = '<b>Explore</b> & ride'; $branding['intro'] = '<p>Hello <strong>riders</strong> <em>today</em><br><a href="https://example.test/terms" onclick="alert(1)">Terms</a></p><script>alert(2)</script><a href="javascript:alert(3)">Bad</a><iframe src="https://example.test"></iframe>';
	$branding['select_text'] = '<i>Book this</i>'; $branding['selected_text'] = 'Your choice'; $branding['change_text'] = 'Choose another';
	$branding += array(); foreach ( array( 'accent' => '#AbC', 'button_bg' => '#123456', 'button_text' => '#ffffff', 'selected_border' => '#345678', 'card_bg' => '#fefefe', 'card_text' => '#234567', 'card_border' => '#567890' ) as $key => $value ) { $branding[ $key ] = $value; }
	$branding['radius'] = 'rounded'; $branding['custom_css'] = '.brp-booking .brp-promo { color: #123456; } .brp-booking .brp-card:hover, .brp-booking .brp-intro { letter-spacing: 1px; }';
	$stored = brand_save( $branding ); $branding = $stored['branding'];
	brand_check( 'Explore & ride' === $branding['heading'] && 'Book this' === $branding['select_text'], 'plain text headings / labels sanitized' );
	brand_check( '#aabbcc' === $branding['accent'], 'three-digit mixed-case color normalized to six digits' );
	brand_check( false === Branding::color( 'rgb(1,2,3)' ), 'color settings reject CSS expressions' );
	foreach ( array( 'bad', '#12345', '#12345678', '#gggggg', '#123;display:none', array( '#123' ) ) as $color ) { $test = $branding; $test['accent'] = $color; brand_check( count( Branding::validate( $test )['errors'] ) > 0, 'malformed color rejected: ' . wp_json_encode( $color ) ); }
	$bad = $branding; $bad['accent'] = 'red'; brand_check( brand_save( $bad ) === $stored, 'invalid branding save preserves all previous settings' );
	$html = PublicBooking::shortcode(); $dom = brand_dom( $html );
	brand_check( str_contains( $html, '<strong>riders</strong>' ) && str_contains( $html, '<em>today</em>' ) && str_contains( $html, 'href="https://example.test/terms"' ), 'intro allows safe formatting and links' );
	brand_check( ! str_contains( $html, '<script' ) && ! str_contains( $html, 'alert(2)' ) && ! str_contains( $html, 'onclick=' ) && ! str_contains( $html, 'javascript:' ) && ! str_contains( $html, '<iframe' ), 'unsafe intro HTML cannot execute' );
	brand_check( str_contains( $html, 'Explore &amp; ride' ) && str_contains( $html, '>Book this</button>' ), 'custom heading and selection label escaped/rendered' );
	foreach ( array( 'accent' => '#aabbcc', 'button-bg' => '#123456', 'button-text' => '#ffffff', 'selected-border' => '#345678', 'card-bg' => '#fefefe', 'card-text' => '#234567', 'card-border' => '#567890', 'radius' => '.75rem' ) as $name => $value ) { brand_check( str_contains( $html, '--brp-' . $name . ':' . $value ), 'wrapper variable ' . $name ); }
	foreach ( array( 'default' => '', 'square' => '--brp-radius:0', 'slight' => '--brp-radius:.25rem', 'rounded' => '--brp-radius:.75rem', 'very' => '--brp-radius:1.5rem' ) as $key => $expected ) { brand_check( $expected === Branding::variables( array( 'radius' => $key ) ), 'radius preset: ' . $key ); }
	$bad = $branding; $bad['radius'] = 'calc(1px)'; brand_check( (bool) Branding::validate( $bad )['errors'], 'arbitrary radius units rejected' );
	$_GET['rental'] = $products[1]->get_slug(); $deep_html = PublicBooking::shortcode();
	brand_check( str_contains( $deep_html, '>✓ Your choice</button>' ) && str_contains( $deep_html, '>Choose another</button>' ), 'deep link uses custom selected / change labels' );
	brand_check( 1 === brand_dom( $deep_html )->query( '//article[not(@hidden)]' )->length, 'deep link keeps one selected card' );
	brand_check( str_contains( $html, '.brp-booking .brp-intro { letter-spacing: 1px;' ), 'safe CSS retained' );
	$first_scope = $dom->query( '//section[@class="brp-booking"]' )->item( 0 )->getAttribute( 'id' );
	brand_check( str_contains( $html, '#' . $first_scope . '.brp-booking .brp-promo' ), 'custom CSS bound to unique wrapper ID' );
	brand_check( ! str_contains( $deep_html, '#' . $first_scope . '.brp-booking' ), 'separate shortcode instances receive distinct CSS scopes' );
	foreach ( array( '</style><script>alert(1)</script>', '<?php echo 1; ?>', '.brp-booking{color:expression(alert(1))}', '@import "https://example.test/x.css";', 'body{color:red}', '.brp-booking, body{color:red}', '.brp-booking + body{color:red}', '.brp-booking~div{color:red}', '.brp-booking:has(body){color:red}', '.brp-booking{background-color:url(https://example.test)}', '.brp-booking{position:fixed}', '.brp-booking{color:r\\65 d}', '@media (min-width:1px){.brp-booking{color:red}}', '.brp-booking{.brp-card{color:red}}', '.brp-booking{--rogue:red}', '.brp-booking { color: red; } body', '.brp-booking{color:var(--rogue)}', str_repeat( 'x', 8001 ) ) as $css ) { brand_check( false === Branding::css( $css ), 'unsafe/unsupported CSS rejected: ' . substr( $css, 0, 80 ) ); }
	brand_check( false !== Branding::css( '/* note */ .brp-booking > form { color: rgba(1, 2, 3, 0.5); padding: 1rem 2%; }' ), 'safe CSS comments, descendant selector and color function supported' );
	$upload = wp_upload_bits( 'brp-branding-logo.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAAE0lEQVR4nGO8/ugeAwwwwVnoHABgaAKd72wTXQAAAABJRU5ErkJggg==' ) );
	if ( $upload['error'] ) { throw new RuntimeException( $upload['error'] ); }
	$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Generic logo fixture', 'post_status' => 'inherit' ), $upload['file'] );
	update_post_meta( $attachment, '_wp_attachment_image_alt', 'Generic rental mark' );
	$small = str_replace( '.png', '-300x225.png', $upload['file'] ); copy( $upload['file'], $small );
	wp_update_attachment_metadata( $attachment, array( 'width' => 800, 'height' => 600, 'file' => _wp_relative_upload_path( $upload['file'] ), 'sizes' => array( 'medium' => array( 'file' => basename( $small ), 'width' => 300, 'height' => 225, 'mime-type' => 'image/png' ) ) ) );
	$products[1]->set_image_id( $attachment ); $products[1]->set_short_description( 'Delivery and pickup included.' ); $products[1]->save();
	$branding['logo_id'] = $attachment; brand_save( $branding ); $deep_html = PublicBooking::shortcode();
	brand_check( str_contains( $deep_html, 'class="brp-logo"' ) && str_contains( $deep_html, 'alt="Generic rental mark"' ), 'Media Library logo and existing alt text rendered' );
	brand_check( str_contains( $deep_html, 'srcset=' ) && str_contains( $deep_html, 'sizes=' ), 'logo uses responsive image metadata' );
	brand_check( strpos( $deep_html, 'class="brp-logo"' ) < strpos( $deep_html, '1. Explore' ), 'logo precedes booking heading' );
	$manager = wp_insert_user( array( 'user_login' => 'brp_brand_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
	wp_set_current_user( $manager ); $attempt = $branding; $attempt['custom_css'] = '.brp-booking { color: red; }'; $attempt['heading'] = 'Shop manager heading';
	$result = brand_save( $attempt ); brand_check( 'Shop manager heading' === $result['branding']['heading'], 'shop manager can save normal branding' );
	brand_check( $branding['custom_css'] === $result['branding']['custom_css'], 'forged shop manager CSS update blocked' );
	$attempt['restore_defaults'] = '1'; $result = brand_save( $attempt ); brand_check( $branding['custom_css'] === $result['branding']['custom_css'], 'shop manager reset cannot erase administrator CSS' );
	wp_set_current_user( 0 ); $before = Settings::get(); brand_check( $before === brand_save( $branding ), 'unauthorized branding save blocked' );
	wp_set_current_user( 1 ); $current = brand_save( $branding );
	$without = $current; unset( $without['branding'] ); brand_check( ( new Settings() )->sanitize( $without )['branding'] === $current['branding'], 'legacy settings submission retains saved branding' );
	$operational = $current; unset( $operational['branding'] ); brand_check( $base == $operational, 'branding save leaves operational settings unchanged' );
	brand_check( $base == BookingSchedule::settings(), 'branding never enters scheduling values' );
	$damaged = $current; $damaged['branding']['accent'] = 'url(unsafe)'; $damaged['branding']['custom_css'] = '</style><script>bad</script>'; update_option( Settings::OPTION, $damaged );
	brand_check( '' === Branding::get()['accent'] && '' === Branding::get()['custom_css'], 'malformed persisted presentation falls back safely' );
	brand_check( $base == BookingSchedule::settings(), 'malformed branding never blocks scheduling' );
	brand_save( $branding ); $_GET = array(); $normal_html = PublicBooking::shortcode();
	$_GET['tab'] = 'branding'; ob_start(); ( new Settings() )->render(); $admin_html = ob_get_clean();
	brand_check( str_contains( $admin_html, 'Booking Form Branding' ) && str_contains( $admin_html, 'options.php' ), 'branding uses existing Settings API form' );
	brand_check( str_contains( $admin_html, 'brp_settings_group-options' ) || str_contains( $admin_html, 'name="_wpnonce"' ), 'existing settings nonce rendered' );
	brand_check( 7 === brand_dom( $admin_html )->query( '//input[@class="brp-color"]' )->length, 'seven native color-picker fields' );
	brand_check( str_contains( $admin_html, 'Advanced Custom CSS' ) && str_contains( $admin_html, 'sufficient contrast' ), 'advanced and accessibility guidance rendered' );
	Branding::assets( 'dashboard' ); brand_check( ! wp_script_is( 'brp-branding-admin', 'enqueued' ), 'branding controls not loaded on unrelated admin pages' );
	Branding::assets( 'toplevel_page_' . Settings::PAGE );
	brand_check( wp_script_is( 'brp-branding-admin', 'enqueued' ) && wp_style_is( 'wp-color-picker', 'enqueued' ) && wp_script_is( 'media-views', 'enqueued' ), 'native color picker and media dependencies enqueued on settings page' );
	if ( $dir = getenv( 'BRP_BRANDING_FIXTURE_DIR' ) ) {
		if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
		foreach ( array( 'brand-default.html' => $default_html, 'brand-normal.html' => $normal_html, 'brand-deep.html' => $deep_html, 'brand-admin.html' => $admin_html ) as $file => $contents ) { file_put_contents( $dir . '/' . $file, $contents ); }
		file_put_contents( $dir . '/brand-packages.json', wp_json_encode( array_map( static fn( $p ) => array( 'product_id' => $p->get_id(), 'slug' => $p->get_slug(), 'name' => $p->get_name(), 'price_html' => $p->get_price_html() ), $products ) ) );
	}
	wp_delete_attachment( $attachment, true ); $attachment = 0;
	brand_check( ! str_contains( PublicBooking::shortcode(), 'class="brp-logo"' ), 'deleted attachment fails gracefully' );
	$branding['logo_id'] = 'https://example.test/logo.png'; brand_check( (bool) Branding::validate( $branding )['errors'], 'external URL cannot replace attachment ID' );
	$result = brand_save( array( 'restore_defaults' => '1' ) );
	brand_check( Branding::defaults() === $result['branding'], 'administrator restore resets all branding only' );
	unset( $result['branding'] ); brand_check( $base == $result, 'branding reset leaves scheduling and payment mode intact' );
	brand_check( Database::VERSION === '2', 'schema is 2' );
} finally {
	wp_set_current_user( 1 ); update_option( Settings::OPTION, $saved ); $_GET = $get;
	foreach ( $products as $p ) { $p->delete( true ); } if ( $attachment ) { wp_delete_attachment( $attachment, true ); }
	if ( $manager ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $manager ); }
}
echo "Booking branding: $checks checks passed.\n";
ob_end_flush();
