<?php
/** Isolated PHP processes test immutable configuration constants. No WordPress or database needed. */
if ( PHP_SAPI !== 'cli' ) { exit; }
if ( isset( $argv[1] ) ) {
 define( 'ABSPATH', __DIR__ );
 define( 'DAY_IN_SECONDS', 86400 );
 define( 'BRP_LICENSE_DEV_MODE', true );
 if ( 'default' !== $argv[1] ) { define( 'BRP_LICENSE_ENFORCE', 'true' === $argv[1] ); }
 // These legacy controls would reverse the intended policy if still consulted.
 function apply_filters( $name, $value ) { return ! $value; }
 function wp_get_environment_type() { return $GLOBALS['argv'][2]; }
 function get_option( $name, $default = false ) { return $default; }
 require dirname( __DIR__ ) . '/bike-rental-plugin/src/License.php';
 $expected = 'false' !== $argv[1];
 if ( \BikeRentalPlugin\License::enforced() !== $expected || \BikeRentalPlugin\License::allows_new() !== ! $expected ) {
  fwrite( STDERR, 'Unexpected unlicensed booking policy.' ); exit( 1 );
 }
 exit;
}
$checks = 0;
foreach ( array( 'default', 'true', 'false' ) as $mode ) {
 foreach ( array( 'local', 'development', 'staging', 'production' ) as $environment ) {
  $process = proc_open( array( PHP_BINARY, '-n', __FILE__, $mode, $environment ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
  if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start isolated policy check.' ); }
  $out = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
  $error = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
  if ( 0 !== proc_close( $process ) ) { throw new RuntimeException( "$mode / $environment: $out$error" ); }
  ++$checks; echo "PASS: $mode / $environment ignores legacy filter and development bypass\n";
 }
}
echo "$checks enforcement configuration checks passed.\n";
