<?php
/** Server-rendered card integration, using real WordPress and WooCommerce. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
use BikeRentalPlugin\{Packages, PublicBooking, Database};
$checks = 0; $products = array(); $attachment = 0; $saved_get = $_GET;
function grid_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo 'PASS: ' . $label . PHP_EOL; }
function grid_product( $name, $type, $amount, $active = 'yes' ) {
	$p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_slug( sanitize_title( $name ) ); $p->set_status( 'publish' ); $p->set_regular_price( '79.99' ); $p->set_sale_price( '64.50' );
	foreach ( array( Packages::ENABLED => 'yes', Packages::ACTIVE => $active, Packages::TYPE => $type, Packages::AMOUNT => $amount ) as $key => $value ) { $p->update_meta_data( $key, $value ); }
	$p->save(); $GLOBALS['products'][] = $p; return $p;
}
try {
	$hour = grid_product( 'Coastal <ride> & tour', 'hours', 4 );
	$_GET = array();
	$hour->set_short_description( '<p onclick="alert(1)">Easy <strong>coastal riding</strong> with <em>delivery</em>.<br>Helmet included.</p><ul><li>Adult riders</li></ul><ol><li>Choose a start</li></ol><img src="x" onerror="alert(1)"><script>alert(2)</script><style>.bad{color:red}</style><iframe>Hidden embed</iframe>' );
	$hour->set_description( 'Long product description must not be duplicated on cards.' );
	$hour->update_meta_data( Packages::PROMO, 'Most Popular' );
	$upload = wp_upload_bits( 'brp-card-fixture.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAAE0lEQVR4nGO8/ugeAwwwwVnoHABgaAKd72wTXQAAAABJRU5ErkJggg==' ) );
	if ( $upload['error'] ) { throw new RuntimeException( $upload['error'] ); }
	$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Rental photo fixture', 'post_status' => 'inherit' ), $upload['file'] );
	update_post_meta( $attachment, '_wp_attachment_image_alt', 'Bicycle by the coast' );
	$thumb_file = str_replace( '.png', '-400x300.png', $upload['file'] ); copy( $upload['file'], $thumb_file );
	wp_update_attachment_metadata( $attachment, array( 'width' => 800, 'height' => 600, 'file' => _wp_relative_upload_path( $upload['file'] ), 'sizes' => array( 'woocommerce_thumbnail' => array( 'file' => basename( $thumb_file ), 'width' => 400, 'height' => 300, 'mime-type' => 'image/png' ) ) ) );
	$hour->set_image_id( $attachment ); $hour->save();
	$day = grid_product( 'One day escape', 'calendar_days', 1 );
	$three = grid_product( 'Three day explorer (3 Days)', 'calendar_days', 3 ); $three->set_short_description( "Delivery and pickup included.\n\n" . str_repeat( 'A comfortable ride for your next adventure. ', 12 ) ); $three->set_image_id( $attachment ); $three->save();
	$seven = grid_product( 'Seven day explorer', 'calendar_days', 7 ); $seven->set_short_description( '<script>alert(99)</script><style>body{display:none}</style>' ); $seven->save();
	$inactive = grid_product( 'Inactive rental', 'hours', 8, 'no' );
	$ordinary = grid_product( 'Ordinary product', 'hours', 4 ); $ordinary->update_meta_data( Packages::ENABLED, 'no' ); $ordinary->save();
	$html = PublicBooking::shortcode();
	$doc = new DOMDocument(); @$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html ); $xpath = new DOMXPath( $doc );
	$card = static fn( $p ) => $xpath->query( '//article[@data-package-id="' . $p->get_id() . '"]' );
	foreach ( array( $hour, $day, $three, $seven ) as $p ) { grid_check( $card( $p )->length === 1, 'active package card: ' . $p->get_name() ); }
	grid_check( $card( $inactive )->length === 0, 'inactive package absent' );
	grid_check( $card( $ordinary )->length === 0, 'ordinary product absent from rental grid' );
	$hour_html = $doc->saveHTML( $card( $hour )->item( 0 ) );
	$day_html = $doc->saveHTML( $card( $day )->item( 0 ) );
	grid_check( str_contains( $hour_html, 'attachment-woocommerce_thumbnail' ) && str_contains( $hour_html, 'srcset=' ), 'responsive Woo thumbnail' );
	grid_check( str_contains( $hour_html, 'alt="Bicycle by the coast"' ), 'attachment alt text preserved' );
	grid_check( str_contains( $day_html, 'brp-image-fallback' ) && ! str_contains( $day_html, '<img' ), 'missing image has safe aligned fallback' );
	grid_check( str_contains( $hour_html, 'Coastal &lt;ride&gt; &amp; tour' ), 'product name escaped' );
	grid_check( str_contains( $hour_html, '<strong>coastal riding</strong>' ) && ! str_contains( $hour_html, 'onerror' ) && ! str_contains( $hour_html, '<script' ), 'short description preserves safe formatting only' );
	grid_check( str_contains( $html, wp_kses_post( $hour->get_price_html() ) ), 'unmodified Woo formatted selling price' );
	grid_check( ! str_contains( $hour_html, 'brp-duration' ) && ! str_contains( $hour_html, '4 Hours' ), 'no standalone hourly duration' );
	grid_check( ! str_contains( $day_html, 'brp-duration' ) && ! str_contains( $day_html, '1 Day' ), 'no standalone calendar duration' );
	grid_check( 0 === $xpath->query( '//*[contains(@class,"brp-duration")]' )->length && str_contains( $html, 'Three day explorer (3 Days)' ), 'duration stays in administrator title only' );
	grid_check( ! str_contains( $day_html, 'brp-description' ), 'empty excerpt omits description wrapper' );
	grid_check( ! str_contains( $doc->saveHTML( $card( $seven )->item( 0 ) ), 'brp-description' ), 'unsafe-only excerpt omits empty wrapper' );
	grid_check( ! str_contains( $html, 'Long product description must not' ), 'no fallback or duplication of long Woo description' );
	grid_check( str_contains( $hour_html, '<em>delivery</em>' ) && str_contains( $hour_html, '<br>' ) && str_contains( $hour_html, '<ul>' ) && str_contains( $hour_html, '<ol>' ) && str_contains( $hour_html, '<li>Adult riders</li>' ), 'emphasis, breaks and both list types remain' );
	grid_check( ! str_contains( $hour_html, 'onclick' ) && ! str_contains( $hour_html, 'alert(' ) && ! str_contains( $hour_html, 'Hidden embed' ) && ! str_contains( $hour_html, '.bad' ), 'unsafe attributes and script/style/embed contents removed' );
	grid_check( 1 === $xpath->query( '//article[@data-package-id="' . $hour->get_id() . '"]//h3/following-sibling::*[1][@class="brp-description"]/following-sibling::*[1][@class="brp-card-price"]' )->length, 'description appears once between title and price in server HTML' );
	grid_check( 2 === $xpath->query( '//article[@data-package-id="' . $three->get_id() . '"]//div[@class="brp-description"]/p' )->length, 'plain excerpt paragraphs are formatted' );
	grid_check( ! str_contains( $hour_html, 'role="region"' ) && ! str_contains( $hour_html, 'tabindex="0"' ), 'natural description needs no separate scrolling focus region' );
	foreach ( array( array( $hour, 'hours', 4 ), array( $three, 'calendar_days', 3 ), array( $seven, 'calendar_days', 7 ) ) as $case ) { $metadata = Packages::get_package( $case[0]->get_id() ); grid_check( $case[1] === $metadata['duration_type'] && $case[2] === $metadata['duration_amount'], 'duration metadata unchanged: ' . $case[0]->get_name() ); }
	grid_check( 1 === $xpath->query( '//article[@data-package-id="' . $hour->get_id() . '"]/div[@class="brp-card-image"]/img' )->length, 'responsive image in shared full-width wrapper' );
	$_GET['rental'] = $three->get_slug(); $deep_html = PublicBooking::shortcode(); $deep_doc = new DOMDocument(); @$deep_doc->loadHTML( '<?xml encoding="utf-8" ?>' . $deep_html ); $deep_xpath = new DOMXPath( $deep_doc );
	grid_check( 1 === $deep_xpath->query( '//article[not(@hidden)]//div[@class="brp-description"]' )->length && str_contains( $deep_html, 'Delivery and pickup included.' ), 'deep-linked card retains server-rendered short description' );
	grid_check( 1 === $deep_xpath->query( '//article[not(@hidden)]/div[@class="brp-card-image"]/img' )->length && ! str_contains( $deep_html, 'brp-duration' ), 'deep-linked card shares image markup with no duration line' );
	$_GET = array();
	grid_check( str_contains( $hour_html, 'brp-promo' ) && ! str_contains( $day_html, 'brp-promo' ), 'promotion appears only when present' );
	grid_check( $xpath->query( '//input[@name="package_id" and @type="hidden"]' )->length === 1 && $xpath->query( '//select[@name="package_id"]' )->length === 0, 'hidden compatibility input replaces dropdown' );
	grid_check( $xpath->query( '//button[@class="brp-select" and @type="button" and @aria-pressed="false" and @disabled]' )->length >= 4, 'accessible real buttons disabled until catalog validation' );
	grid_check( $xpath->query( '//div[@class="brp-details" and @hidden]' )->length === 1, 'later steps initially hidden' );
	grid_check( $xpath->query( '//h1' )->length === 0 && $xpath->query( '//article/h1' )->length === 0, 'no page-level H1 added' );
	grid_check( str_contains( $hour_html, '<h3' ) && ! str_contains( $hour_html, 'add-to-cart' ), 'semantic cards without direct purchase links' );
	grid_check( '2' === Database::VERSION, 'schema is 2' );
	// Export presentation fixtures only when explicitly requested by the local browser suite.
	if ( getenv( 'BRP_GRID_FIXTURE_DIR' ) ) {
		$dir = getenv( 'BRP_GRID_FIXTURE_DIR' ); if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
		file_put_contents( $dir . '/cards.html', $html );
		file_put_contents( $dir . '/cards-deep.html', $deep_html );
		file_put_contents( $dir . '/cards.json', wp_json_encode( array( 'ids' => array_map( static fn( $p ) => $p->get_id(), array( $hour, $day, $three, $seven ) ), 'packages' => array_map( static fn( $p ) => array( 'product_id' => $p->get_id(), 'name' => $p->get_name(), 'price_html' => $p->get_price_html() ), array( $hour, $day, $three, $seven ) ) ) ) );
	}
	// Filter the public product query to an empty catalog without mutating other products.
	$empty = static function ( $args ) { $args['post__in'] = array( 0 ); return $args; };
	add_filter( 'woocommerce_product_data_store_cpt_get_products_query', $empty );
	$empty_html = PublicBooking::shortcode();
	remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', $empty );
	grid_check( ! str_contains( $empty_html, '<article' ) && str_contains( $empty_html, '<p class="brp-empty">No rental packages' ), 'empty catalog message is server rendered' );
} finally {
	$_GET = $saved_get;
	foreach ( $products as $p ) { $p->delete( true ); }
	if ( $attachment ) { wp_delete_attachment( $attachment, true ); }
}
echo "Product grid: $checks checks passed.\n";
ob_end_flush();
