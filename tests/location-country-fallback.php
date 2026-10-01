<?php
/** No WooCommerce dependency: use only explicit existing store configuration. */
define( 'ABSPATH', __DIR__ );
function get_option( $key, $default = false ) { return $GLOBALS['store_country'] ?? $default; }
require dirname( __DIR__ ) . '/bike-rental-plugin/src/RentalLocation.php';
$checks = 0;
foreach ( array( 'CA:ON' => 'CA', 'GB' => 'GB', '' => '', 'USA' => '', 'us' => '' ) as $configured => $expected ) {
 $GLOBALS['store_country'] = $configured;
 if ( \BikeRentalPlugin\RentalLocation::base_country() !== $expected ) { throw new RuntimeException( 'Incorrect country fallback.' ); }
 ++$checks;
}
unset( $GLOBALS['store_country'] );
if ( '' !== \BikeRentalPlugin\RentalLocation::base_country() ) { throw new RuntimeException( 'Missing setting invented a country.' ); }
++ $checks;
$legacy = array( 'address_1' => '123 Example Street', 'country' => 'CA', 'state' => 'ON' );
if ( ! str_contains( \BikeRentalPlugin\RentalLocation::text( $legacy ), 'CA' ) ) { throw new RuntimeException( 'Legacy country lost without Woo.' ); }
++ $checks;
echo "$checks country fallback checks passed.\n";
