<?php
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class WaiverSettings {
	public static function defaults() {
		return array( 'required' => 'no', 'provider' => 'none', 'signing_page' => 0, 'version' => '', 'text' => '', 'wpforms' => array( 'form_id' => 0, 'mapping' => array() ),
			'adult_subject' => 'Action Required: Sign Your Rider Waiver',
			'adult_body' => "{business_name}\nReservation: {reservation_reference}\nPackage: {package_name}\nRider: {rider_name}\n\nPlease read and sign your rider waiver:\n{waiver_url}\n\nYour reservation is not confirmed until all required rider waivers are complete. This private link expires in 7 days. Do not forward it.",
			'guardian_subject' => 'Action Required: Sign Waiver for Minor Rider',
			'guardian_body' => "{business_name}\nReservation: {reservation_reference}\nPackage: {package_name}\n\n{guardian_name}, please read and sign as parent/guardian for {rider_name}, age {rider_age}:\n{waiver_url}\n\nYour reservation is not confirmed until all required rider waivers are complete. This private link expires in 7 days. Do not forward it."
		);
	}
	public static function placeholders() { return array( 'business_name', 'reservation_reference', 'rider_name', 'rider_age', 'guardian_name', 'guardian_relationship', 'package_name', 'rental_start', 'rental_end', 'waiver_url', 'waiver_version' ); }
	/** Page and templates are operational settings; frozen legal/provider policy remains unchanged. */
	public static function signing_url() {
		$id = self::get()['signing_page']; $page = $id ? get_post( $id ) : null;
		if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status || $page->post_password || ! has_shortcode( $page->post_content, 'bike_rental_waiver' ) ) { return Database::error( 'waiver_page', 'Select a published, public Waiver Signing Page containing [bike_rental_waiver].' ); }
		$url = get_permalink( $page ); return $url ?: Database::error( 'waiver_page', 'The Waiver Signing Page URL is unavailable.' );
	}
	public static function get() { $s = Settings::get(); return is_array( $s['waivers'] ?? null ) ? array_replace( self::defaults(), $s['waivers'] ) : self::defaults(); }
	public static function validate( $input ) {
		if ( ! is_array( $input ) || ! in_array( $input['required'] ?? null, array( 'no', 'yes' ), true ) || ! is_string( $input['provider'] ?? null ) || ( 'none' !== $input['provider'] && ! isset( Waivers::providers()[ $input['provider'] ] ) ) ) { return Database::error( 'waiver_settings', 'Select a valid waiver requirement and provider.' ); }
		$v = self::defaults(); $v['required'] = $input['required']; $v['provider'] = $input['provider'];
		foreach ( array( 'version' => 100, 'text' => 50000 ) as $key => $max ) {
			if ( ! is_string( $input[ $key ] ?? '' ) || strlen( $input[ $key ] ?? '' ) > $max ) { return Database::error( 'waiver_settings', 'Waiver version or text is invalid or too long.' ); }
			$v[ $key ] = 'text' === $key ? sanitize_textarea_field( $input[ $key ] ?? '' ) : sanitize_text_field( $input[ $key ] ?? '' );
		}
		$previous = self::get();
		$page = $input['signing_page'] ?? $previous['signing_page'];
		if ( ! is_scalar( $page ) || ! preg_match( '/^[0-9]{1,10}$/D', (string) $page ) ) { return Database::error( 'waiver_settings', 'Select a valid Waiver Signing Page.' ); }
		$v['signing_page'] = (int) $page;
		foreach ( array( 'adult_subject', 'adult_body', 'guardian_subject', 'guardian_body' ) as $key ) {
			$raw = $input[ $key ] ?? $previous[ $key ]; $body = str_ends_with( $key, '_body' );
			if ( ! is_string( $raw ) || strlen( $raw ) > ( $body ? 20000 : 200 ) || '' === trim( $raw ) ) { return Database::error( 'waiver_settings', 'Enter a valid invitation subject and body within the allowed length.' ); }
			$v[ $key ] = $body ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
			if ( $body && ! str_contains( $v[ $key ], '{waiver_url}' ) ) { return Database::error( 'waiver_settings', 'Each invitation body must include {waiver_url}.' ); }
		}
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
		$page = self::signing_url(); if ( is_wp_error( $page ) ) { return $page; }
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
		echo '<h2>General</h2>';
		echo '<h2>Rider waivers</h2><p>Adults are age 18 or older. Each adult signs for themselves; a guardian signs separately for each minor. New reservations capture this policy. Existing reservations are not changed.</p><table class="form-table">';
		echo '<tr><th><label for="brp-waivers-required">Require Rider Waivers</label></th><td><select id="brp-waivers-required" name="brp_settings[waivers][required]">';
		foreach ( array( 'no' => 'No', 'yes' => 'Yes' ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $v['required'], $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></td></tr>';
		echo '<tr><th><label for="brp-waivers-provider">Waiver Provider</label></th><td><select id="brp-waivers-provider" name="brp_settings[waivers][provider]"><option value="none">None</option>';
		foreach ( Waivers::providers() as $key => $provider ) { if ( $provider->available() || $v['provider'] === $key ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $v['provider'], $key, false ) . '>' . esc_html( $key . ( $provider->available() ? '' : ' — missing capability' ) ) . '</option>'; } } echo '</select></td></tr>';
		echo '<tr><th><label for="brp-signing-page">Waiver Signing Page</label></th><td>';
		wp_dropdown_pages( array( 'name' => 'brp_settings[waivers][signing_page]', 'id' => 'brp-signing-page', 'selected' => $v['signing_page'], 'show_option_none' => 'Select a page', 'option_none_value' => 0 ) );
		echo '<p>Publish a page containing <code>[bike_rental_waiver]</code>. Exclude the page and all invitation links from CDN/page caching.</p></td></tr>';
		foreach ( array( 'version' => 'Waiver Version', 'text' => 'Approved waiver text' ) as $key => $label ) {
			echo '<tr><th><label for="brp-waiver-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'text' === $key ) { echo '<textarea class="large-text" rows="12" id="brp-waiver-text" name="brp_settings[waivers][text]">' . esc_textarea( $v[ $key ] ) . '</textarea><p>This exact plain text is displayed to signers and preserved with each waiver. Supply your approved adult/guardian wording; the plugin does not provide legal text.</p>'; }
			else { echo '<input class="regular-text" id="brp-waiver-version" name="brp_settings[waivers][version]" value="' . esc_attr( $v[ $key ] ) . '">'; } echo '</td></tr>';
		} echo '</table>';
		foreach ( Waivers::providers() as $id => $provider ) {
			if ( $v['provider'] === $id ) { $provider->settings( $v[ $id ] ?? array() ); }
			elseif ( ! $provider->available() ) { echo '<p>' . esc_html( $id . ': missing required capability.' ) . '</p>'; }
		}
		$ready = self::ready( $v ); echo '<p><strong>' . esc_html( is_wp_error( $ready ) ? $ready->get_error_message() : ( 'yes' === $v['required'] ? 'Waiver setup is configured.' : 'Waivers are not required.' ) ) . '</strong></p>';
		foreach ( array( 'adult' => 'Adult Invitation Email', 'guardian' => 'Guardian Invitation Email' ) as $role => $heading ) {
			echo '<h2>' . esc_html( $heading ) . '</h2><table class="form-table">';
			foreach ( array( 'subject' => 'Subject', 'body' => 'Body (plain text)' ) as $part => $label ) {
				$key = $role . '_' . $part; $name = 'brp_settings[waivers][' . $key . ']';
				echo '<tr><th><label for="brp-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
				if ( 'body' === $part ) { echo '<textarea class="large-text" rows="8" id="brp-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" maxlength="20000">' . esc_textarea( $v[ $key ] ) . '</textarea>'; }
				else { echo '<input class="large-text" id="brp-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" maxlength="200" value="' . esc_attr( $v[ $key ] ) . '">'; } echo '</td></tr>';
			} echo '</table>';
		}
		echo '<p>Available placeholders: <code>' . esc_html( '{' . implode( '} {', self::placeholders() ) . '}' ) . '</code>. Unknown placeholders stay as literal text. Resends use the current templates.</p>';
	}
}
