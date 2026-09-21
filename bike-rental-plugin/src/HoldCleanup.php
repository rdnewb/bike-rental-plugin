<?php
/** WP-Cron housekeeping; allocation ignores expired timestamps even if cron is delayed. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class HoldCleanup {
	const HOOK = 'brp_expire_holds';
	public static function register_hooks() {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		add_action( 'init', array( self::class, 'schedule' ), 30 );
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( self::HOOK, array( ReservationCleanup::class, 'retention' ), 30 );
	}
	public static function schedules( $schedules ) {
		$schedules['brp_five_minutes'] = array( 'interval' => 300, 'display' => __( 'Every five minutes (rental holds)', 'bike-rental-plugin' ) );
		return $schedules;
	}
	public static function schedule() {
		global $wpdb;
		if ( Database::VERSION !== (string) get_option( Database::OPTION, '' ) || wp_next_scheduled( self::HOOK ) ) { return; }
		// Serialize first-time registration only; this is not the inventory lock.
		$lock = 'brp_cron_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { return; }
		try {
			// Another request may have registered while our initial option read was cached.
			wp_cache_delete( 'cron', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 300, 'brp_five_minutes', self::HOOK ); }
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
	public static function run() { return Reservations::expire_holds(); }
	public static function deactivate() { wp_clear_scheduled_hook( self::HOOK ); }
}
