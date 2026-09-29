<?php
/** Presentation regressions with explicit API doubles; no database/integration claim. */
namespace BikeRentalPlugin {
 if ( PHP_SAPI !== 'cli' ) { exit; }
 define( 'ABSPATH', __DIR__ );
 class Settings { const PAGE = 'brp-settings'; public static function can_manage() { return $GLOBALS['allowed']; } public static function capability() { return 'manage_options'; } }
 class Database {
  public static $row;
  public static function gate() { return true; }
  public static function read( $table, $id ) { return self::$row; }
  public static function listing( $table, $page ) { return array( 'rows' => array( self::$row ), 'page' => 1, 'total' => 1 ); }
  public static function error( $code, $message ) { return new \WP_Error( $code, $message ); }
 }
 class Packages {
  public static function get_active_packages() { return array( self::get_package( 42 ) ); }
  public static function get_package( $id ) { return array( 'product_id' => 42, 'name' => 'Weekend rental', 'price' => '99.00', 'currency' => 'USD' ); }
 }
 class Payments { public static function reservation_admin( $row ) {} }
 class WaiverUI { public static function reservation_admin( $row ) {} }
 class Waivers { public static function progress( $row ) { return array( 'label' => 'Not Required' ); } }
 class ReservationCleanup { public static function render( $row ) {} }
 class RentalTime { public static function display( $value, $input = false ) { return $value ?? ''; } }
 class AdminCalendar { const PAGE = 'brp-calendar'; }
 require dirname( __DIR__ ) . '/bike-rental-plugin/src/Reservations.php';
 require dirname( __DIR__ ) . '/bike-rental-plugin/src/DataAdmin.php';
}
namespace {
 class WP_Error { public function __construct( public $code, public $message ) {} }
 function __( $v, $domain = '' ) { return $v; }
 function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
 function esc_attr( $v ) { return esc_html( $v ); }
 function esc_url( $v ) { return esc_html( $v ); }
 function esc_html__( $v, $domain = '' ) { return esc_html( $v ); }
 function esc_html_e( $v, $domain = '' ) { echo esc_html( $v ); }
 function is_wp_error( $v ) { return $v instanceof WP_Error; }
 function wp_die( $v ) { throw new RuntimeException( $v ); }
 function get_transient( $key ) { return false; }
 function get_current_user_id() { return 1; }
 function wp_timezone_string() { return 'UTC'; }
 function wc_get_product( $id ) { return true; }
 function admin_url( $path ) { return '/wp-admin/' . $path; }
 function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
 function selected( $a, $b ) { if ( (string) $a === (string) $b ) { echo 'selected'; } }
 function wp_nonce_field( $key ) { echo '<input type="hidden" name="_wpnonce" value="fixture-nonce">'; }
 function wp_generate_uuid4() { return 'fixture-idempotency-key'; }
 function submit_button( $label ) { echo '<button>' . esc_html( $label ) . '</button>'; }
 function add_submenu_page( ...$args ) { $GLOBALS['menus'][] = $args; }
 function add_action( ...$args ) { $GLOBALS['hooks'][] = $args; }
 function pc_check( $condition, $label ) { if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo "PASS: $label\n"; }
 $checks = 0; $allowed = true;
 $row = array( 'id' => 7, 'reference' => 'BRP-EXISTING', 'package_product_id' => 42, 'quantity' => 2, 'status' => 'pending_waivers', 'revision' => 9, 'snapshot' => '{"name":"Weekend rental","private_marker":"snapshot-secret"}', 'request_key' => 'private-request', 'request_hash' => str_repeat( 'a', 64 ), 'session_hash' => str_repeat( 'b', 64 ), 'token_hash' => str_repeat( 'c', 64 ), 'timezone' => 'UTC', 'order_id' => null, 'start_utc' => '2030-01-01 09:00:00', 'end_utc' => '2030-01-02 09:00:00', 'occupied_start_utc' => '2030-01-01 08:00:00', 'occupied_end_utc' => '2030-01-02 10:00:00', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00', 'hold_expires_at' => null );
 \BikeRentalPlugin\Database::$row = $row;
 $admin = new \BikeRentalPlugin\DataAdmin();
 $_GET = array( 'id' => 7 );
 ob_start(); $admin->reservations(); $html = ob_get_clean();
 foreach ( array( 'Current reservation snapshot', '<pre', 'snapshot-secret', 'private-request', $row['request_hash'], $row['session_hash'], $row['token_hash'], 'Request association:', 'Session association:' ) as $forbidden ) { pc_check( ! str_contains( $html, $forbidden ), 'detail does not disclose ' . substr( $forbidden, 0, 28 ) ); }
 pc_check( \BikeRentalPlugin\Database::$row === $row, 'render leaves stored snapshot and reservation untouched (API double)' );
 foreach ( array( 'Save reservation', 'BRP-EXISTING', 'Pending Waivers', 'name="revision" value="9"', 'name="_wpnonce"', 'name="quantity"', 'name="start"', 'name="end"', 'name="status"' ) as $expected ) { pc_check( str_contains( $html, $expected ), 'existing record retains ' . $expected ); }
 $_GET = array(); ob_start(); $admin->reservations(); $html = ob_get_clean();
 pc_check( str_contains( $html, 'Create Reservation' ) && str_contains( $html, 'value="reservation_create"' ), 'manual reservation form uses existing production operation' );
 pc_check( str_contains( $html, 'name="start" type="datetime-local" value="" required' ) && str_contains( $html, 'name="end" type="datetime-local" value="" required' ), 'manual booking requires explicit dates without fake defaults' );
 pc_check( ! preg_match( '/test reservation|milestone/i', $html ), 'manual booking has production wording' );
 $admin->menus(); $admin->register_hooks();
 pc_check( in_array( 'Availability', array_column( $menus, 2 ), true ), 'operational availability diagnostic retained' );
 pc_check( ! preg_match( '/test|demo|sample/i', implode( ' ', array_column( $menus, 2 ) ) ), 'menus contain operational labels' );
 foreach ( array( 'test_reservation', 'reservation_test', 'create_test_reservation', 'sample_reservation', 'demo_reservation' ) as $operation ) {
  $result = $admin->dispatch( array( 'operation' => $operation ) );
  pc_check( is_wp_error( $result ) && $result->code === 'operation' && \BikeRentalPlugin\Database::$row === $row, 'unsupported generator operation rejected: ' . $operation );
 }
 $allowed = false;
 pc_check( is_wp_error( $admin->dispatch( array( 'operation' => 'reservation_create' ) ) ), 'manual creation still requires authorization' );
 try { ob_start(); $admin->reservations(); ob_end_clean(); pc_check( false, 'unauthorized page rejected' ); } catch ( RuntimeException $e ) { ob_end_clean(); pc_check( str_contains( $e->getMessage(), 'permission' ), 'unauthorized detail rejected' ); }
 $runtime = dirname( __DIR__ ) . '/bike-rental-plugin'; $source = '';
 foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $runtime, FilesystemIterator::SKIP_DOTS ) ) as $file ) { $source .= file_get_contents( $file->getPathname() ); }
 pc_check( ! preg_match( '/\bmilestone\b|\btest reservation\b|development placeholder|future phase|not implemented yet|booking is available yet|photo coming soon|Temporary runtime diagnostics/i', $source ), 'runtime and distributed readme exclude development panels and wording' );
 pc_check( str_contains( file_get_contents( $runtime . '/src/Database.php' ), "const VERSION = '2'" ), 'schema remains 2' );
 pc_check( \BikeRentalPlugin\Reservations::status_label( 'pending_waivers' ) === 'Pending Waivers', 'status presentation preserves readable words' );
 echo "$checks production cleanup checks passed. API doubles; real integration suites remain required.\n";
}
