<?php
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class WaiverSettings {
	public static function defaults() { return array( 'required' => 'no', 'provider' => 'none', 'version' => '', 'text' => '', 'wpforms' => array( 'form_id' => 0, 'mapping' => array() ) ); }
	public static function get() { $s = Settings::get(); return is_array( $s['waivers'] ?? null ) ? array_replace( self::defaults(), $s['waivers'] ) : self::defaults(); }
	public static function validate( $input ) {
		if ( ! is_array( $input ) || ! in_array( $input['required'] ?? null, array( 'no', 'yes' ), true ) || ! is_string( $input['provider'] ?? null ) || ( 'none' !== $input['provider'] && ! isset( Waivers::providers()[ $input['provider'] ] ) ) ) { return Database::error( 'waiver_settings', 'Select a valid waiver requirement and provider.' ); }
		$v = self::defaults(); $v['required'] = $input['required']; $v['provider'] = $input['provider'];
		foreach ( array( 'version' => 100, 'text' => 50000 ) as $key => $max ) {
			if ( ! is_string( $input[ $key ] ?? '' ) || strlen( $input[ $key ] ?? '' ) > $max ) { return Database::error( 'waiver_settings', 'Waiver version or text is invalid or too long.' ); }
			$v[ $key ] = 'text' === $key ? sanitize_textarea_field( $input[ $key ] ?? '' ) : sanitize_text_field( $input[ $key ] ?? '' );
		}
		$previous = self::get();
		foreach ( Waivers::providers() as $id => $provider ) {
			if ( ! array_key_exists( $id, $input ) ) { $v[ $id ] = $previous[ $id ] ?? array(); continue; }
			if ( ! is_array( $input[ $id ] ) ) { return Database::error( 'waiver_settings', 'Invalid provider configuration.' ); }
			$config = $provider->sanitize_config( $input[ $id ] ); if ( is_wp_error( $config ) ) { return $config; } $v[ $id ] = $config;
		}
		if ( 'yes' === $v['required'] && ( '' === $v['version'] || '' === $v['text'] ) ) { return Database::error( 'waiver_settings', 'Enter the approved waiver text and a version before requiring waivers.' ); }
		// Missing installations can be saved as a draft, but diagnostics and checkout fail closed.
		return $v;
	}
	public static function ready( $policy ) {
		if ( 'yes' !== ( $policy['required'] ?? 'no' ) ) { return true; }
		$provider = Waivers::providers()[ $policy['provider'] ?? '' ] ?? null;
		if ( ! $provider || ! $provider->available() ) { return Database::error( 'waiver_provider', 'A working waiver provider and signature capability are required. New rental checkout is blocked.' ); }
		if ( empty( $policy['version'] ) || empty( $policy['text'] ) ) { return Database::error( 'waiver_provider', 'Waiver version and approved legal text are required.' ); }
		return $provider->configuration( $policy[ $policy['provider'] ] ?? array() );
	}
	public static function notice() {
		if ( ! Settings::can_manage() ) { return; } $ready = self::ready( self::get() );
		if ( is_wp_error( $ready ) ) { echo '<div class="notice notice-error"><p><strong>Bike Rentals waivers:</strong> ' . esc_html( $ready->get_error_message() ) . ' <a href="' . esc_url( Settings::tab_url( 'waivers' ) ) . '">Review Waivers settings</a></p></div>'; }
	}
	public static function render() {
		$v = self::get();
		echo '<h2>Rider waivers</h2><p>Adults are age 18 or older. Each adult signs for themselves; a guardian signs separately for each minor. New reservations capture this policy. Existing reservations are not changed.</p><table class="form-table">';
		echo '<tr><th><label for="brp-waivers-required">Require Rider Waivers</label></th><td><select id="brp-waivers-required" name="brp_settings[waivers][required]">';
		foreach ( array( 'no' => 'No', 'yes' => 'Yes' ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $v['required'], $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></td></tr>';
		echo '<tr><th><label for="brp-waivers-provider">Waiver Provider</label></th><td><select id="brp-waivers-provider" name="brp_settings[waivers][provider]"><option value="none">None</option>';
		foreach ( Waivers::providers() as $key => $provider ) { if ( $provider->available() || $v['provider'] === $key ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $v['provider'], $key, false ) . '>' . esc_html( $key . ( $provider->available() ? '' : ' — missing capability' ) ) . '</option>'; } } echo '</select></td></tr>';
		foreach ( array( 'version' => 'Waiver Version', 'text' => 'Approved waiver text' ) as $key => $label ) {
			echo '<tr><th><label for="brp-waiver-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'text' === $key ) { echo '<textarea class="large-text" rows="12" id="brp-waiver-text" name="brp_settings[waivers][text]">' . esc_textarea( $v[ $key ] ) . '</textarea><p>This exact plain text is displayed to signers and preserved with each waiver. Supply your approved adult/guardian wording; the plugin does not provide legal text.</p>'; }
			else { echo '<input class="regular-text" id="brp-waiver-version" name="brp_settings[waivers][version]" value="' . esc_attr( $v[ $key ] ) . '">'; } echo '</td></tr>';
		} echo '</table>';
		foreach ( Waivers::providers() as $id => $provider ) {
			if ( $v['provider'] === $id ) { $provider->settings( $v[ $id ] ?? array() ); }
			elseif ( ! $provider->available() ) { echo '<p>' . esc_html( $id . ': missing required capability.' ) . '</p>'; }
		}
	}
}
