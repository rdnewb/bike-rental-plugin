<?php
/** Disposable-only real WordPress bootstrap shared by concurrency processes. */
if ( PHP_SAPI !== 'cli' || getenv( 'BRP_ALLOW_DISPOSABLE_TESTS' ) !== '1' ) { throw new RuntimeException( 'Disposable CLI opt-in required.' ); }
$test_root = getenv( 'BRP_TEST_WP_ROOT' );
if ( ! $test_root || ! is_file( $test_root . '/wp-load.php' ) ) { throw new RuntimeException( 'Disposable WordPress root required.' ); }
require $test_root . '/wp-load.php';
if ( DB_NAME !== 'brp_m3_disposable' || DB_HOST !== '127.0.0.1:33316' || $wpdb->prefix !== 'm3_' ) { throw new RuntimeException( 'Refusing non-disposable database.' ); }
require_once dirname( __DIR__ ) . '/bike-rental-plugin/bike-rental-plugin.php';
wp_set_current_user( 1 );
\BikeRentalPlugin\Plugin::boot();
\BikeRentalPlugin\Plugin::load_packages();
