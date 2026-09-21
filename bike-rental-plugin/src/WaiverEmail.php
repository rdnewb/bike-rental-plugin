<?php
/** Generic plain-text email substitution; no evaluation or HTML rendering. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class WaiverEmail {
	public static function compose( $prepared, $url ) {
		$r = $prepared['rider']; $w = $prepared['waiver']; $row = $prepared['row']; $s = json_decode( $row['snapshot'], true );
		$values = array( 'business_name' => Settings::get()['business_name'] ?: get_bloginfo( 'name' ), 'reservation_reference' => $row['reference'], 'rider_name' => $r['legal_name'], 'rider_age' => $r['age'], 'guardian_name' => $r['guardian_name'], 'guardian_relationship' => $r['guardian_relationship'], 'package_name' => $s['name'] ?? '', 'rental_start' => str_replace( 'T', ' ', $s['local_start'] ?? '' ) . ' ' . $row['timezone'], 'rental_end' => str_replace( 'T', ' ', $s['local_end'] ?? '' ) . ' ' . $row['timezone'], 'waiver_url' => esc_url_raw( $url ), 'waiver_version' => $w['waiver_version'] );
		$replace = array(); foreach ( $values as $key => $value ) { $replace[ '{' . $key . '}' ] = sanitize_text_field( (string) $value ); }
		$v = WaiverSettings::get(); $role = 'guardian' === $w['signer_role'] ? 'guardian' : 'adult';
		return array( 'subject' => sanitize_text_field( strtr( $v[ $role . '_subject' ], $replace ) ), 'body' => strtr( $v[ $role . '_body' ], $replace ) );
	}
}
