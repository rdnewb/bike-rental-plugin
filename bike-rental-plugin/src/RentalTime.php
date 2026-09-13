<?php
/** Strict local input, UTC storage, and WordPress-local presentation. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class RentalTime {
	public static function from_local( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D', $value ) || (int) substr( $value, 0, 4 ) < 1001 || (int) substr( $value, 0, 4 ) > 9998 ) {
			return Database::error( 'time', 'Enter a valid local date and time (year 1001–9998).' );
		}
		try {
			$zone = wp_timezone();
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, $zone );
			if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $value ) { return Database::error( 'time', 'Invalid local time, including a daylight-saving clock gap.' ); }
			// A repeated clock time has two possible instants. Require an unambiguous input.
			$nominal = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, new \DateTimeZone( 'UTC' ) )->getTimestamp();
			$transitions = $zone->getTransitions( $nominal - 172800, $nominal + 172800 );
			$matches = array();
			foreach ( $transitions ?: array() as $transition ) {
				$timestamp = $nominal - $transition['offset'];
				if ( ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $zone )->format( 'Y-m-d\TH:i' ) === $value ) { $matches[ $timestamp ] = true; }
			}
			if ( count( $matches ) > 1 ) { return Database::error( 'time', 'This local time repeats during a daylight-saving change. Choose an unambiguous time.' ); }
			return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $error ) {
			return Database::error( 'timezone', 'The WordPress timezone is invalid. Correct Settings > General before saving.' );
		}
	}

	public static function interval( $start, $end, $optional_end = false ) {
		$start = self::from_local( $start );
		if ( is_wp_error( $start ) ) { return $start; }
		$end = $optional_end && '' === $end ? null : self::from_local( $end );
		if ( is_wp_error( $end ) ) { return $end; }
		if ( null !== $end && $end <= $start ) { return Database::error( 'interval', 'End must be later than start.' ); }
		return array( 'start_utc' => $start, 'end_utc' => $end );
	}

	public static function display( $utc, $input = false ) {
		if ( ! $utc ) { return ''; }
		try {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $utc, new \DateTimeZone( 'UTC' ) );
			if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $utc ) { return ''; }
			return $date->setTimezone( wp_timezone() )->format( $input ? 'Y-m-d\TH:i' : 'Y-m-d H:i T' );
		} catch ( \Exception $error ) { return ''; }
	}

	public static function shift( $utc, $minutes ) {
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->modify( (int) $minutes . ' minutes' )->format( 'Y-m-d H:i:s' );
	}
}
