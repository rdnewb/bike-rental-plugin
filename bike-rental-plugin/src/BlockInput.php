<?php
/** Admin-only date/time presentation and conversion; allocation stays in Fleet. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class BlockInput {

	public static function times( $existing = '' ) {
		$increment = Settings::get()['time_increment'] ?? 30;
		$increment = in_array( (int) $increment, array( 5, 10, 15, 20, 30, 60 ), true ) ? (int) $increment : 30;
		$times = array();
		for ( $minute = 0; $minute < 1440; $minute += $increment ) {
			$value = sprintf( '%02d:%02d', intdiv( $minute, 60 ), $minute % 60 );
			$times[ $value ] = self::time_label( $value );
		}
		// Existing blocks may predate an increment change or have an off-grid end.
		if ( is_string( $existing ) && preg_match( '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $existing ) ) { $times[ $existing ] = self::time_label( $existing ); }
		ksort( $times );
		return $times;
	}
	private static function time_label( $time ) {
		$hour = (int) substr( $time, 0, 2 );
		return ( $hour % 12 ?: 12 ) . substr( $time, 2 ) . ( $hour < 12 ? ' AM' : ' PM' );
	}

	private static function date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value ) || (int) substr( $value, 0, 4 ) < 1001 || (int) substr( $value, 0, 4 ) > 9998 ) { return false; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date : false;
	}

	public static function values( $block = null ) {
		$start = RentalTime::display( $block['start_utc'] ?? '', true );
		$end = RentalTime::display( $block['end_utc'] ?? '', true );
		$all_day = $start && $end && substr( $start, 11 ) === '00:00' && substr( $end, 11 ) === '00:00' && $end > $start && substr( $block['start_utc'], -2 ) === '00' && substr( $block['end_utc'], -2 ) === '00';
		$end_date = substr( $end, 0, 10 );
		if ( $all_day ) { $end_date = self::date( $end_date )->modify( '-1 day' )->format( 'Y-m-d' ); }
		return array( 'start_date' => substr( $start, 0, 10 ), 'start_time' => substr( $start, 11 ), 'end_date' => $end_date, 'end_time' => substr( $end, 11 ), 'all_day' => (bool) $all_day, 'no_end' => (bool) ( $block && ! $block['end_utc'] ) );
	}

	/** Called after capability, nonce and timezone checks; never trusts legacy start/end fields. */
	public static function normalize( $input, $block = null, $allow_indefinite = true ) {
		if ( ! is_array( $input ) ) { return Database::error( 'input', 'Invalid block input.' ); }
		foreach ( array( 'all_day', 'no_end' ) as $flag ) {
			if ( ! in_array( $input[ $flag ] ?? '0', array( '0', '1' ), true ) ) { return Database::error( 'input', 'Invalid date option.' ); }
		}
		$all_day = '1' === ( $input['all_day'] ?? '0' );
		$no_end = '1' === ( $input['no_end'] ?? '0' );
		if ( $no_end && ( ! $allow_indefinite || $all_day ) ) { return Database::error( 'interval', 'All Day requires an end date. Choose either All Day or No end date.' ); }
		$start_date = self::date( $input['start_date'] ?? null );
		$end_date = $no_end ? null : self::date( $input['end_date'] ?? null );
		if ( ! $start_date || ( ! $no_end && ! $end_date ) ) { return Database::error( 'date', 'Enter valid start and end dates (year 1001-9998).' ); }
		if ( $all_day && $end_date < $start_date ) { return Database::error( 'interval', 'End date must be on or after start date.' ); }
		$old = self::values( $block );
		foreach ( array( 'start', 'end' ) as $side ) {
			if ( $all_day || ( 'end' === $side && $no_end ) ) { continue; }
			$time = $input[ $side . '_time' ] ?? null;
			if ( ! is_string( $time ) || ! array_key_exists( $time, self::times( $old[ $side . '_time' ] ) ) ) { return Database::error( 'time', 'Choose a supported start and end time.' ); }
		}
		$input['start'] = $start_date->format( 'Y-m-d' ) . 'T' . ( $all_day ? '00:00' : $input['start_time'] );
		$input['end'] = $no_end ? '' : ( $all_day ? $end_date->modify( '+1 day' )->format( 'Y-m-d' ) . 'T00:00' : $end_date->format( 'Y-m-d' ) . 'T' . $input['end_time'] );
		$interval = RentalTime::interval( $input['start'], $input['end'], $allow_indefinite );
		return is_wp_error( $interval ) ? $interval : $input;
	}

	public static function interval_label( $block ) {
		$v = self::values( $block );
		if ( $v['all_day'] ) { return $v['start_date'] . ( $v['end_date'] === $v['start_date'] ? '' : ' - ' . $v['end_date'] ) . ' (All Day)'; }
		return $v['start_date'] . ' ' . self::time_label( $v['start_time'] ) . ' - ' . ( $v['no_end'] ? __( 'Indefinite', 'bike-rental-plugin' ) : $v['end_date'] . ' ' . self::time_label( $v['end_time'] ) );
	}

	public static function render( $block = null, $allow_indefinite = true ) {
		$v = self::values( $block );
		wp_enqueue_script( 'brp-block-admin', plugins_url( 'assets/js/block-admin.js', dirname( __DIR__ ) . '/bike-rental-plugin.php' ), array(), Plugin::VERSION, true );
		echo '<fieldset class="brp-block-dates"><legend>' . esc_html__( 'Dates and times', 'bike-rental-plugin' ) . '</legend>';
		foreach ( array( 'start' => 'Start', 'end' => 'End' ) as $side => $label ) {
			$disabled_date = 'end' === $side && $v['no_end'];
			$disabled_time = $v['all_day'] || $disabled_date;
			echo '<p><label>' . esc_html( $label . ' Date' ) . '<br><input type="date" name="' . esc_attr( $side ) . '_date" value="' . esc_attr( $v[ $side . '_date' ] ) . '" min="1001-01-01" max="9998-12-31"' . ( $disabled_date ? ' disabled' : ' required' ) . '></label></p>';
			echo '<p><label>' . esc_html( $label . ' Time' ) . '<br><select name="' . esc_attr( $side ) . '_time"' . ( $disabled_time ? ' disabled' : ' required' ) . '><option value="">Choose a time</option>';
			foreach ( self::times( $v[ $side . '_time' ] ) as $value => $text ) { echo '<option value="' . esc_attr( $value ) . '"'; selected( $v[ $side . '_time' ], $value ); echo '>' . esc_html( $text ) . '</option>'; }
			echo '</select></label></p>';
		}
		echo '<p><label><input type="checkbox" name="all_day" value="1"' . ( $v['all_day'] ? ' checked' : '' ) . '> All Day</label></p>';
		if ( $allow_indefinite ) { echo '<p><label><input type="checkbox" name="no_end" value="1"' . ( $v['no_end'] ? ' checked' : '' ) . '> No end date (until disabled)</label></p>'; }
		echo '<p class="description">All Day includes every selected date, from local midnight on the start date to midnight after the end date. Times use ' . esc_html( wp_timezone_string() ) . '.</p></fieldset>';
	}
}
