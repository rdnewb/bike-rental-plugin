<?php
/** Read-only weekly operations view. Allocation remains owned by Availability. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class AdminCalendar {
	const PAGE = 'brp-calendar';

	/** Local calendar midnights, not seven fixed 24-hour periods; honors start_of_week. */
	public static function week( $value = '' ) {
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$date = is_string( $value ) && preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() ) : false;
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value || (int) $date->format( 'Y' ) < 1002 || (int) $date->format( 'Y' ) > 9997 ) { $date = $today; }
		$first = ( (int) get_option( 'start_of_week', 1 ) % 7 + 7 ) % 7;
		$start = $date->modify( '-' . ( ( (int) $date->format( 'w' ) - $first + 7 ) % 7 ) . ' days' );
		$days = array();
		for ( $i = 0; $i <= 7; ++$i ) { $days[] = $start->modify( '+' . $i . ' days' ); }
		return $days;
	}
	public static function utc( $date ) { return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); }
	public static function time( $utc ) {
		return Availability::valid_utc( $utc ) ? ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'D M j Y, g:i A T' ) : '';
	}
	public static function url( $date = '', $status = 'all' ) {
		return add_query_arg( array( 'page' => self::PAGE, 'week' => $date, 'status' => $status ), admin_url( 'admin.php' ) );
	}

	public static function load( $value = '', $status = 'all' ) {
		if ( ! Settings::can_manage() ) { return Database::error( 'permission', 'You do not have permission to view the rental calendar.' ); }
		$status = in_array( $status, Reservations::STATUSES, true ) ? $status : 'all';
		$days = self::week( $value );
		$data = Database::locked( static function ( $capacity ) use ( $days, $status ) {
			global $wpdb;
			$start = self::utc( $days[0] ); $end = self::utc( $days[7] );
			// Indexed status/occupied interval predicates. Overdue active intervals are open-ended.
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT id,reference,order_id,quantity,start_utc,end_utc,occupied_start_utc,occupied_end_utc,status,hold_expires_at,snapshot,issue_code FROM %i WHERE status IN (%s,%s,%s,%s,%s,%s,%s) AND occupied_start_utc < %s AND (occupied_end_utc > %s OR (status = %s AND occupied_end_utc < %s))',
				Database::table( 'reservations' ), ...array_merge( Reservations::STATUSES, array( $end, $start, 'active', Database::now() ) )
			) . ( 'all' !== $status ? $wpdb->prepare( ' AND status = %s', $status ) : '' ) . ' ORDER BY occupied_start_utc,id FOR UPDATE', ARRAY_A );
			if ( ! is_array( $rows ) || $wpdb->last_error ) { return Database::retry_error(); }
			$blocks = $wpdb->get_results( $wpdb->prepare(
				'SELECT id,quantity,start_utc,end_utc,reason FROM %i WHERE record_type = %s AND active = 1 AND start_utc < %s AND (end_utc IS NULL OR end_utc > %s) ORDER BY start_utc,id FOR UPDATE',
				Database::table( 'availability' ), 'block', $end, $start
			), ARRAY_A );
			if ( ! is_array( $blocks ) || $wpdb->last_error ) { return Database::retry_error(); }
			$usage = array();
			for ( $i = 0; $i < 7; ++$i ) {
				// Unfiltered totals under one lock/clock, using the authoritative sweep unchanged.
				$day = Availability::evaluate( $capacity, self::utc( $days[ $i ] ), self::utc( $days[ $i + 1 ] ) );
				if ( is_wp_error( $day ) ) { return $day; }
				$usage[] = $day;
			}
			foreach ( $rows as &$row ) { $row['effective_end'] = Availability::reservation_end( $row ); }
			unset( $row );
			return array( 'days' => $days, 'status' => $status, 'rows' => $rows, 'blocks' => $blocks, 'usage' => $usage, 'capacity' => $capacity, 'now' => Database::now() );
		} );
		if ( is_wp_error( $data ) ) { return $data; }
		// Woo CRUD, outside the inventory lock; one batch of distinct linked orders.
		$data['orders'] = array();
		$ids = array_values( array_unique( array_filter( array_map( 'intval', array_column( $data['rows'], 'order_id' ) ) ) ) );
		if ( $ids && function_exists( 'wc_get_orders' ) ) {
			try {
				foreach ( wc_get_orders( array( 'include' => $ids, 'limit' => count( $ids ), 'type' => 'shop_order' ) ) as $order ) {
					$data['orders'][ $order->get_id() ] = array( 'customer' => trim( $order->get_formatted_billing_full_name() ), 'number' => $order->get_order_number() );
				}
			} catch ( \Throwable $error ) { /* Rental operations remain visible without order data. */ }
		}
		return $data;
	}

	/** Equal-width date columns with fractions measured against each local day's length (DST). */
	public static function position( $utc, $days ) {
		$stamp = ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		for ( $i = 0; $i < 7; ++$i ) {
			if ( $stamp < $days[ $i + 1 ]->getTimestamp() ) {
				return max( 0, ( $i + ( $stamp - $days[ $i ]->getTimestamp() ) / ( $days[ $i + 1 ]->getTimestamp() - $days[ $i ]->getTimestamp() ) ) / 7 * 100 );
			}
		}
		return 100;
	}

	public static function render() {
		if ( ! Settings::can_manage() ) { wp_die( esc_html__( 'You do not have permission to view the rental calendar.', 'bike-rental-plugin' ), '', array( 'response' => 403 ) ); }
		$data = self::load( wp_unslash( $_GET['week'] ?? '' ), wp_unslash( $_GET['status'] ?? 'all' ) );
		echo '<div class="wrap brp-calendar"><h1>' . esc_html__( 'Reservation Calendar', 'bike-rental-plugin' ) . '</h1>';
		if ( is_wp_error( $data ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $data->get_error_message() ) . '</p></div></div>'; return; }
		wp_enqueue_style( 'brp-calendar', plugins_url( 'assets/css/calendar.css', dirname( __DIR__ ) . '/bike-rental-plugin.php' ), array(), Plugin::VERSION );
		wp_print_styles( 'brp-calendar' );
		require __DIR__ . '/calendar-page.php';
		echo '</div>';
	}
}
