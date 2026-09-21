<?php
/** JSON stdin/stdout bridge to the separate controller WordPress runtime; no network emulation claimed. */
ob_start();
require __DIR__ . '/license-controller-bootstrap.php';
use NTLicenseController\{Licenses, Store};
$input = json_decode( stream_get_contents( STDIN ), true );
try {
 if ( 'reset' === $input['operation'] ) {
  foreach ( array( 'events', 'activations', 'licenses' ) as $kind ) { $wpdb->query( 'TRUNCATE TABLE ' . Store::table( $kind ) ); }
  $result = true;
 } elseif ( 'save' === $input['operation'] ) { $result = Licenses::save( $input['input'], $input['id'] ?? 0 ); }
 elseif ( 'read' === $input['operation'] ) { $result = Licenses::read( $input['id'] ); }
 elseif ( 'used' === $input['operation'] ) { $result = Licenses::used( $input['id'] ); }
 else {
  wp_set_current_user( 0 );
  $request = new WP_REST_Request( 'POST', '/nt-license/v1/' . $input['operation'] );
  $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( $input['input'] ) );
  $_SERVER['REMOTE_ADDR'] = $input['ip'] ?? '192.0.2.55';
  $response = rest_get_server()->dispatch( $request ); $result = array( 'status' => $response->get_status(), 'body' => $response->get_data() );
 }
 if ( is_wp_error( $result ) ) { $result = array( 'error' => $result->get_error_code() ); }
 ob_end_clean(); echo wp_json_encode( $result );
} catch ( Throwable $e ) { ob_end_clean(); echo '{"error":"worker_failure"}'; exit( 1 ); }
