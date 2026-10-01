<?php
/** Real input, Fleet, and availability services with explicit in-memory database/API doubles. */
namespace BikeRentalPlugin {
 if ( PHP_SAPI !== 'cli' ) { exit; }
 define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
 class Settings {
  const PAGE = 'brp-settings';
  public static function get() { return array( 'time_increment' => $GLOBALS['increment'] ); }
  public static function can_manage() { return $GLOBALS['allowed']; }
 }
 class Plugin { const VERSION = '1.1.2'; }
 class Database {
  public static $capacity = 10; public static $blocks = array(); public static $locked = false; public static $locks = 0;
  public static function error( $code, $text ) { return new \WP_Error( $code, $text ); }
  public static function gate() { return true; }
  public static function positive( $v ) { return ( is_int( $v ) || is_string( $v ) ) && preg_match( '/\A[1-9][0-9]*\z/', (string) $v ) && $v <= 2147483647; }
  public static function table( $name ) { return $name; }
  public static function now() { return '2026-01-01 00:00:00'; }
  public static function in_transaction() { return self::$locked; }
  public static function locked( $callback ) {
   if ( ! Settings::can_manage() ) { return self::error( 'permission', 'Denied' ); }
   ++self::$locks; self::$locked = true; try { return $callback( self::$capacity ); } finally { self::$locked = false; }
  }
  public static function read( $table, $id ) { return $id == 1 ? array( 'record_type' => 'capacity', 'quantity' => self::$capacity, 'active' => 1 ) : ( self::$blocks[$id] ?? self::error( 'missing', 'Missing block' ) ); }
  public static function insert( $table, $data ) { $id = ++$GLOBALS['wpdb']->insert_id; self::$blocks[$id] = $data + array( 'id' => $id ); return 1; }
  public static function update( $table, $data, $where ) { if ( $where['id'] == 1 ) { self::$capacity = $data['quantity']; return 1; } self::$blocks[$where['id']] = array_replace( self::$blocks[$where['id']], $data ); return 1; }
  public static function listing( $table, $page ) { return array( 'rows' => array_values( self::$blocks ), 'page' => 1, 'total' => count( self::$blocks ) ); }
 }
 foreach ( array( 'RentalTime', 'Availability', 'Fleet', 'BlockInput', 'DataAdmin', 'Reservations', 'AdminCalendar' ) as $class ) { require dirname( __DIR__ ) . '/bike-rental-plugin/src/' . $class . '.php'; }
}
namespace {
 use BikeRentalPlugin\{BlockInput, Database, Fleet, Availability, DataAdmin, RentalTime};
 class WP_Error { public function __construct( public $code, public $message, public $data = null ) {} public function get_error_message() { return $this->message; } }
 $wpdb = new class {
  public $last_error = ''; public $insert_id = 1;
  public function prepare( $query, ...$args ) { return $args; }
  public function get_results( $args, $format ) {
   if ( $args[0] === 'reservations' ) { return array(); }
   return array_values( array_filter( Database::$blocks, static fn( $b ) => $b['active'] && $b['id'] != $args[3] && $b['start_utc'] < $args[4] && ( ! $b['end_utc'] || $b['end_utc'] > $args[5] ) ) );
  }
 };
 function is_wp_error( $v ) { return $v instanceof WP_Error; }
 function wp_timezone() { return new DateTimeZone( $GLOBALS['zone'] ); }
 function wp_timezone_string() { return $GLOBALS['zone']; }
 function sanitize_text_field( $v ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( $v ) ) ); }
 function get_current_user_id() { return 1; }
 function __( $v, $domain = '' ) { return $v; }
 function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
 function esc_attr( $v ) { return esc_html( $v ); }
 function esc_url( $v ) { return esc_html( $v ); }
 function esc_html__( $v, $domain = '' ) { return esc_html( $v ); }
 function esc_html_e( $v, $domain = '' ) { echo esc_html( $v ); }
 function selected( $a, $b ) { if ( (string) $a === (string) $b ) { echo ' selected'; } }
 function wp_enqueue_script( ...$args ) {}
 function plugins_url( $path, $file ) { return '/' . $path; }
 function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid-' . $action; }
 function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="valid-' . esc_attr( $action ) . '">'; }
 function get_transient( $key ) { return false; }
 function admin_url( $path ) { return '/wp-admin/' . $path; }
 function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
 function submit_button( $text ) { echo '<button>' . esc_html( $text ) . '</button>'; }
 function wp_die( $text ) { throw new RuntimeException( $text ); }
 function au_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo "PASS: $label\n"; }
 function au_form( $changes = array(), $id = 0 ) {
  $op = $id ? 'block_update' : 'block_create';
  return array_replace( array( 'operation' => $op, 'id' => $id, '_wpnonce' => 'valid-brp_' . $op . '_' . $id, 'timezone' => wp_timezone_string(), 'start_date' => '2030-10-10', 'start_time' => '09:00', 'end_date' => '2030-10-10', 'end_time' => '14:00', 'quantity' => '2', 'reason' => 'Maintenance', 'active' => '1' ), $changes );
 }
 function au_save( $changes, $label, $id = 0 ) { $r = $GLOBALS['admin']->dispatch( au_form( $changes, $id ) ); au_check( ! is_wp_error( $r ), $label . ( is_wp_error( $r ) ? ': ' . $r->message : '' ) ); return $r; }
 $zone = 'America/New_York'; $increment = 30; $allowed = true; $checks = 0; $admin = new DataAdmin();
 foreach ( array( 5, 10, 15, 20, 30, 60 ) as $increment ) { $times = BlockInput::times(); au_check( count( $times ) === 1440 / $increment, "increment $increment generates a full day" ); }
 $increment = 30; $times = BlockInput::times();
 au_check( $times['00:00'] === '12:00 AM' && $times['12:00'] === '12:00 PM' && $times['17:30'] === '5:30 PM', '12-hour labels include midnight/noon/afternoon' );
 au_check( ! isset( $times['09:17'] ) && isset( BlockInput::times( '09:17' )['09:17'] ), 'only stored off-grid values supplement current options' );
 $timed = au_save( array( 'reason' => " <b>Maintenance</b>\n today " ), 'same-day timed block saves through actual Fleet service' );
 au_check( $timed['start_utc'] === '2030-10-10 13:00:00' && $timed['end_utc'] === '2030-10-10 18:00:00', 'timed block converted to UTC' );
 au_check( $timed['reason'] === 'Maintenance today' && Database::$locks > 0, 'existing sanitizer and locked write retained' );
 $multi = au_save( array( 'start_date' => '2030-11-01', 'end_date' => '2030-11-03' ), 'multi-day timed block saves' );
 au_check( $multi['end_utc'] === '2030-11-03 19:00:00', 'multi-day timed endpoint uses changed DST offset' );
 $single = au_save( array( 'start_date' => '2030-12-20', 'end_date' => '2030-12-20', 'all_day' => '1' ), 'single all-day block saves' );
 au_check( $single['start_utc'] === '2030-12-20 05:00:00' && $single['end_utc'] === '2030-12-21 05:00:00', 'single all-day spans local midnight to next midnight' );
 $all = au_save( array( 'start_date' => '2030-12-24', 'end_date' => '2030-12-26', 'all_day' => '1', 'quantity' => '10' ), 'multi-day all-day closure saves' );
 au_check( $all['end_utc'] === '2030-12-27 05:00:00' && BlockInput::values( $all )['end_date'] === '2030-12-26', 'inclusive end date round-trips without adding another day' );
 au_check( BlockInput::interval_label( $all ) === '2030-12-24 - 2030-12-26 (All Day)', 'all-day label replaces midnight range' );
 au_check( str_contains( BlockInput::interval_label( $timed ), '9:00 AM' ) && str_contains( BlockInput::interval_label( $timed ), '2:00 PM' ), 'timed list/calendar labels use AM/PM' );
 foreach ( array( array( 'end_time' => '08:30' ), array( 'end_time' => '09:00' ), array( 'end_date' => '2030-10-09' ), array( 'all_day' => '1', 'end_date' => '2030-10-09' ), array( 'start_date' => '2030-02-30' ), array( 'end_date' => '' ), array( 'start_date' => array() ), array( 'start_time' => '09:17' ), array( 'end_time' => '24:00' ), array( 'end_time' => array() ), array( 'all_day' => array() ), array( 'all_day' => '1', 'no_end' => '1' ), array( 'quantity' => '0' ), array( 'quantity' => '11' ), array( 'quantity' => '1.5' ), array( 'quantity' => array() ), array( 'reason' => '' ), array( 'reason' => array() ), array( 'timezone' => 'UTC' ), array( '_wpnonce' => 'bad' ) ) as $index => $change ) {
  $before = Database::$blocks; $r = $admin->dispatch( au_form( $change ) ); au_check( is_wp_error( $r ) && Database::$blocks === $before, 'invalid input rejected without write: ' . $index );
 }
 $forged = au_save( array( 'start' => '1900-01-01T00:00', 'end' => '1900-01-02T00:00' ), 'legacy canonical input cannot bypass date controls' );
 au_check( $forged['start_utc'] === $timed['start_utc'], 'server derives canonical endpoints' );
 au_check( is_wp_error( $admin->dispatch( au_form( array( 'quantity' => '10' ) ) ) ), 'overlapping blocks still enforce available capacity' );
 $edge = au_save( array( 'start_date' => '2030-12-27', 'end_date' => '2030-12-27', 'all_day' => '1', 'quantity' => '10' ), 'half-open next-day boundary permits adjacent closure' );
 $old = BlockInput::values( $timed );
 $edited = au_save( array_replace( $old, array( 'all_day' => '0', 'no_end' => '0', 'reason' => 'Updated' ) ), 'edit uses the same date/time converter', $timed['id'] );
 au_check( $edited['start_utc'] === $timed['start_utc'] && $edited['end_utc'] === $timed['end_utc'], 'unchanged schedule preserved during edit' );
 $increment = 60;
 $offgrid = Fleet::save_block( array( 'start' => '2031-01-01T09:17', 'end' => '2031-01-01T10:43', 'quantity' => 1, 'reason' => 'Existing record' ) );
 $v = BlockInput::values( $offgrid );
 $saved = au_save( array_replace( $v, array( 'all_day' => '0', 'no_end' => '0' ) ), 'off-grid existing times survive increment changes', $offgrid['id'] );
 au_check( $saved['start_utc'] === $offgrid['start_utc'] && $saved['end_utc'] === $offgrid['end_utc'], 'no off-grid schedule rounding on edit' );
 $indefinite = au_save( array( 'start_date' => '2032-01-01', 'no_end' => '1', 'end_date' => '', 'end_time' => '' ), 'indefinite blocks remain supported' );
 au_check( $indefinite['end_utc'] === null && BlockInput::values( $indefinite )['no_end'], 'indefinite block loads without a fabricated endpoint' );
 au_check( ! is_wp_error( Fleet::disable_block( $indefinite['id'] ) ) && Database::$blocks[$indefinite['id']]['active'] === 0, 'existing disable operation retained' );
 $increment = 30;
 foreach ( array( '2030-03-10' => 23, '2030-11-03' => 25 ) as $date => $hours ) {
  $v = BlockInput::normalize( au_form( array( 'start_date' => $date, 'end_date' => $date, 'all_day' => '1' ) ) );
  $i = RentalTime::interval( $v['start'], $v['end'] );
  au_check( ( strtotime( $i['end_utc'] . ' UTC' ) - strtotime( $i['start_utc'] . ' UTC' ) ) / 3600 === $hours, "all-day DST interval is $hours hours" );
 }
 foreach ( array( array( '2030-03-10', '02:30' ), array( '2030-11-03', '01:30' ) ) as [$date, $time] ) { au_check( is_wp_error( BlockInput::normalize( au_form( array( 'start_date' => $date, 'end_date' => $date, 'start_time' => $time ) ) ) ), 'DST gap/repetition rejected by existing converter' ); }
 $zone = 'Asia/Kathmandu'; $v = BlockInput::normalize( au_form() ); $i = RentalTime::interval( $v['start'], $v['end'] );
 au_check( $i['start_utc'] === '2030-10-10 03:15:00', 'timezone is configurable, including fractional offsets' );
 $zone = 'America/New_York'; $_GET = array(); ob_start(); $admin->availability(); $html = ob_get_clean();
 foreach ( array( 'type="date" name="start_date"', 'type="date" name="end_date"', '<select name="start_time"', '<select name="end_time"', '9:00 AM', '9:30 AM', 'All Day', 'Add Block', 'Quantity of Bikes', 'Reason', 'Add Availability Block' ) as $text ) { au_check( str_contains( $html, $text ), 'availability page renders ' . $text ); }
 au_check( ! str_contains( $html, 'datetime-local' ), 'availability has no combined date/time controls' );
 $_GET = array( 'id' => $all['id'] ); ob_start(); $admin->availability(); $edit_html = ob_get_clean();
 au_check( str_contains( $edit_html, 'Edit block' ) && str_contains( $edit_html, 'value="2030-12-26"' ) && str_contains( $edit_html, 'name="all_day" value="1" checked' ), 'Availability edit loads inclusive dates and all-day state' );
 au_check( str_contains( $edit_html, '<select name="start_time" disabled' ) && str_contains( $edit_html, '<select name="end_time" disabled' ), 'all-day edit renders disabled time dropdowns' );
 $_GET = array(); ob_start(); $admin->fleet(); $fleet_html = ob_get_clean();
 au_check( ! str_contains( $fleet_html, 'Add Availability Block' ) && ! str_contains( $fleet_html, 'value="block_create"' ) && ! str_contains( $fleet_html, 'brp-block-dates' ), 'Fleet contains no duplicate block controls' );
 au_check( str_contains( $fleet_html, 'Total rentable bikes' ) && str_contains( $fleet_html, 'value="capacity"' ) && str_contains( $fleet_html, 'Manage Availability Blocks' ) && str_contains( $fleet_html, 'page=brp-availability' ), 'Fleet retains capacity form and links to Availability' );
 au_check( ! is_wp_error( $admin->dispatch( au_form( array( 'operation' => 'capacity', '_wpnonce' => 'valid-brp_capacity_0', 'quantity' => '12' ) ) ) ) && Fleet::capacity() === 12, 'Fleet capacity action persists through actual service' );
 au_check( ! is_wp_error( $admin->dispatch( au_form( array( 'operation' => 'capacity', '_wpnonce' => 'valid-brp_capacity_0', 'quantity' => '10' ) ) ) ) && Fleet::capacity() === 10, 'Fleet capacity can decrease to valid commitments' );
 au_check( ! str_contains( strtolower( $html ), 'check availability' ) && ! str_contains( $html, 'availability_test' ), 'Availability has no checker heading or form' );
 au_check( str_contains( $html, 'Updated' ) && str_contains( $html, 'Disabled' ) && str_contains( $html, '9:17 AM' ), 'existing edited and disabled blocks remain visible' );
 $before = Database::$blocks;
 au_check( is_wp_error( $admin->dispatch( au_form( array( 'operation' => 'availability_test', '_wpnonce' => 'valid-brp_availability_test_0' ) ) ) ) && Database::$blocks === $before, 'removed checker action rejects even valid nonce without mutation' );
 au_check( is_wp_error( $admin->dispatch( array( 'operation' => 'reservation_create' ) ) ) && Database::$blocks === $before, 'removed admin create operation rejected' );
 $allowed = false;
 au_check( is_wp_error( $admin->dispatch( au_form() ) ), 'block mutation still requires authorization' );
 $allowed = true;
 foreach ( array( $timed, $all ) as $calendar_block ) {
  $start = new DateTimeImmutable( substr( RentalTime::display( $calendar_block['start_utc'], true ), 0, 10 ), wp_timezone() );
  $days = array(); for ( $d = 0; $d <= 7; ++$d ) { $days[] = $start->modify( "+$d days" ); }
  $usage = array(); for ( $d = 0; $d < 7; ++$d ) { $usage[] = Availability::check( \BikeRentalPlugin\AdminCalendar::utc( $days[$d] ), \BikeRentalPlugin\AdminCalendar::utc( $days[$d+1] ) ); }
  $data = array( 'days' => $days, 'status' => 'all', 'usage' => $usage, 'capacity' => 10, 'rows' => array(), 'blocks' => array( $calendar_block ) );
  $before_calendar = Database::$blocks;
  ob_start(); require dirname( __DIR__ ) . '/bike-rental-plugin/src/calendar-page.php'; $calendar_html = ob_get_clean();
  au_check( str_contains( $calendar_html, BlockInput::interval_label( $calendar_block ) ), 'calendar renders actual block interval label' );
  au_check( str_contains( $calendar_html, 'data-event="block-' . $calendar_block['id'] . '"' ) && str_contains( $calendar_html, 'Peak reserved:' ), 'calendar retains block bar and capacity totals' );
  au_check( str_contains( $calendar_html, 'page=brp-availability' ) && ! str_contains( $calendar_html, 'page=brp-fleet' ), 'calendar block edit links open Availability' );
  au_check( Database::$blocks === $before_calendar, 'calendar remains read-only' );
 }
 if ( getenv( 'BRP_BLOCK_FIXTURE_DIR' ) ) { $dir = getenv( 'BRP_BLOCK_FIXTURE_DIR' ); if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); } file_put_contents( $dir . '/fleet.html', $fleet_html ); file_put_contents( $dir . '/blocks.html', $html ); file_put_contents( $dir . '/edit-block.html', $edit_html ); }
 echo "$checks availability UI checks passed. In-memory database/API doubles; not a real concurrency test.\n";
}
