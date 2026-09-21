<?php
/** A separate disposable WordPress installation, no WooCommerce or rental plugin. */
if ( PHP_SAPI !== 'cli' || getenv( 'BRP_ALLOW_DISPOSABLE_TESTS' ) !== '1' ) { throw new RuntimeException( 'Disposable CLI opt-in required.' ); }
$root = getenv( 'BRP_TEST_CONTROLLER_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) { throw new RuntimeException( 'Separate disposable controller installation required.' ); }
$_SERVER['HTTPS'] = 'on';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
if ( DB_NAME !== 'brp_m3_disposable' || DB_HOST !== '127.0.0.1:33316' || $wpdb->prefix !== 'lc_' ) { throw new RuntimeException( 'Refusing non-disposable controller database.' ); }
require_once dirname( __DIR__ ) . '/nt-license-controller/nt-license-controller.php';
\NTLicenseController\Store::install();
wp_set_current_user( 1 );
