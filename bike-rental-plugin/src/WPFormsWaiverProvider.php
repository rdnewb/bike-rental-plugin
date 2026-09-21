<?php
/** All WPForms-specific runtime hooks and persisted-entry verification live here. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class WPFormsWaiverProvider implements WaiverProvider {
	private static $rendering = null;
	private static $validated = array();
	private static $submitted = array();
	private static $result_forms = array();
	public static $completed = false;
	public function sanitize_config( array $input ) {
		$out = array( 'mapping' => array() );
		foreach ( array_merge( array( 'form_id' ), array_keys( self::mapping() ) ) as $key ) {
			$raw = 'form_id' === $key ? ( $input['form_id'] ?? '0' ) : ( $input['mapping'][ $key ] ?? '0' );
			if ( ! is_scalar( $raw ) || ! preg_match( '/^[0-9]{1,9}$/D', (string) $raw ) ) { return Database::error( 'waiver_settings', 'Provider form and field IDs must be nonnegative integers.' ); }
			if ( 'form_id' === $key ) { $out['form_id'] = (int) $raw; } else { $out['mapping'][ $key ] = (int) $raw; }
		} return $out;
	}
	public function settings( array $config ) {
		echo '<h3>WPForms configuration</h3><p>' . esc_html( $this->diagnostic() ) . '</p><p>Use a dedicated form with entry storage enabled. Map the distinct fields described below; see the provider setup guide.</p><table class="form-table">';
		foreach ( array( 'form_id' => 'Waiver Form ID' ) + self::mapping() as $key => $label ) {
			$name = 'form_id' === $key ? 'brp_settings[waivers][wpforms][form_id]' : 'brp_settings[waivers][wpforms][mapping][' . $key . ']'; $value = 'form_id' === $key ? ( $config['form_id'] ?? 0 ) : ( $config['mapping'][ $key ] ?? 0 );
			echo '<tr><th><label for="brp-map-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" min="0" id="brp-map-' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></td></tr>';
		} echo '</table>'; $ready = $this->configuration( $config ); if ( ! is_wp_error( $ready ) ) { $ready = WaiverSettings::signing_url(); }
		echo '<p>' . esc_html( is_wp_error( $ready ) ? 'Misconfigured: ' . $ready->get_error_message() : 'Available and configured.' ) . '</p>';
		if ( Settings::can_manage() && defined( 'BRP_WAIVER_DIAGNOSTICS' ) && BRP_WAIVER_DIAGNOSTICS ) { echo '<h4>Temporary runtime diagnostics</h4><pre>' . esc_html( wp_json_encode( get_transient( 'brp_waiver_diagnostics' ) ?: array(), JSON_PRETTY_PRINT ) ) . '</pre><p>Latest observed stages only; no signer data or bearer values. Remove BRP_WAIVER_DIAGNOSTICS after verification.</p>'; }
	}
	public static function mapping() { return array( 'reservation' => 'Reservation reference (Hidden)', 'rider_id' => 'Rider ID (Hidden)', 'rider_name' => 'Rider legal name (Hidden)', 'age' => 'Rider age (Hidden)', 'rider_type' => 'Adult/minor (Hidden)', 'guardian_name' => 'Guardian name (Hidden)', 'relationship' => 'Guardian relationship (Hidden)', 'package' => 'Package reference (Hidden)', 'version' => 'Waiver version (Hidden)', 'text_hash' => 'Waiver text hash (Hidden)', 'request' => 'Waiver request reference (Hidden)', 'signer_name' => 'Signer full legal name (Single Line Text)', 'signer_email' => 'Signer email (Email)', 'signature' => 'Signature (required)', 'consent' => 'Self/guardian acceptance (Checkboxes, required)' ); }
	public function available() { return function_exists( 'wpforms' ) && is_object( wpforms()->entry ) && is_callable( array( wpforms()->entry, 'get' ) ) && has_action( 'wpforms_display_field_signature' ) && has_action( 'wpforms_process_validate_signature' ); }
	public function diagnostic() {
		if ( ! function_exists( 'wpforms' ) || ! is_object( wpforms()->entry ) || ! is_callable( array( wpforms()->entry, 'get' ) ) ) { return 'Missing WPForms paid entry-storage capability. WPForms Lite is insufficient.'; }
		return $this->available() ? 'WPForms entry storage and Signature field display/validation capabilities are available.' : 'Missing WPForms Signature Addon display/validation capability.';
	}
	public function configuration( array $config ) {
		if ( ! $this->available() ) { return Database::error( 'waiver_provider', $this->diagnostic() ); }
		$form = wpforms()->form->get( $config['form_id'] ?? 0, array( 'content_only' => true ) );
		if ( ! is_array( $form ) || empty( $form['fields'] ) || ! empty( $form['settings']['disable_entries'] ) ) { return Database::error( 'waiver_form', 'Select a saved WPForms form with entry storage enabled.' ); }
		$confirmations = $form['settings']['confirmations'] ?? array( array( 'type' => $form['settings']['confirmation_type'] ?? 'message' ) );
		foreach ( $confirmations as $confirmation ) { if ( 'message' !== ( $confirmation['type'] ?? 'message' ) ) { return Database::error( 'waiver_form', 'Use Message confirmations on the dedicated waiver form so signing results remain visible.' ); } }
		$used = array();
		foreach ( self::mapping() as $key => $label ) {
			$id = $config['mapping'][ $key ] ?? 0; $field = $form['fields'][ $id ] ?? null;
			$type = match ( $key ) { 'signer_name' => 'text', 'signer_email' => 'email', 'signature' => 'signature', 'consent' => 'checkbox', default => 'hidden' };
			if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! preg_match( '/^[0-9]{1,9}$/D', (string) $id ) || isset( $used[ $id ] ) || ! is_array( $field ) || $type !== ( $field['type'] ?? '' ) || ! empty( $field['conditional_logic'] ) || ! empty( $field['conditional_logic_enabled'] ) || ( 'hidden' !== $type && empty( $field['required'] ) ) ) { return Database::error( 'waiver_mapping', 'Map a distinct, unconditional field of the required type: ' . $label . '.' ); }
			$used[ $id ] = true;
			if ( 'signer_email' === $key && ! empty( $field['confirmation'] ) ) { return Database::error( 'waiver_mapping', 'Disable Email Confirmation on the mapped signer email field; the invitation already identifies the signer.' ); }
		}
		return true;
	}
	public static function register_hooks() {
		add_filter( 'wpforms_process_before_filter', array( self::class, 'before_process' ), 100, 2 );
		add_filter( 'wpforms_field_properties', array( self::class, 'properties' ), 100, 3 );
		add_action( 'wpforms_display_submit_before', array( self::class, 'token_field' ), 10 );
		add_filter( 'wpforms_process_filter', array( self::class, 'process' ), 100, 3 );
		add_action( 'wpforms_process_complete', array( self::class, 'completed' ), 100, 4 );
		add_filter( 'wpforms_frontend_confirmation_message', array( self::class, 'confirmation' ), 100, 2 );
	}
	public static function confirmation( $message, $form ) {
		if ( ! array_key_exists( (int) $form['id'], self::$result_forms ) ) { return $message; }
		return self::$result_forms[ (int) $form['id'] ] ? '<p>Your rider waiver was recorded. The purchaser can view reservation progress from their order.</p>' : '<p>Your submission was received, but the rental waiver could not be verified. Please contact the shop before relying on this waiver as complete.</p>';
	}
	public static function expected( $ctx ) {
		$w = $ctx['waiver']; $r = $ctx['rider']; $row = $ctx['reservation']; $s = json_decode( $row['snapshot'], true );
		return array( 'reservation' => $row['reference'], 'rider_id' => (string) $r['id'], 'rider_name' => $r['legal_name'], 'age' => (string) $r['age'], 'rider_type' => $r['rider_type'], 'guardian_name' => $r['guardian_name'], 'relationship' => $r['guardian_relationship'], 'package' => (string) $row['package_product_id'] . ': ' . ( $s['name'] ?? '' ), 'version' => $w['waiver_version'], 'text_hash' => $w['text_hash'], 'request' => (string) $w['id'], 'signer_name' => $w['signer_name'], 'signer_email' => $w['signer_email'] );
	}
	public function render( array $context, string $token ) {
		$config = json_decode( $context['waiver']['provider_config'], true ); $valid = $this->configuration( $config ); if ( is_wp_error( $valid ) ) { return '<p>Signing is temporarily unavailable. Please contact the shop.</p>'; }
		self::$rendering = array( 'context' => $context, 'token' => $token, 'config' => $config );
		try {
			$html = do_shortcode( '[wpforms id="' . (int) $config['form_id'] . '" title="false" description="false"]' );
			$verified = self::verify_rendered( $html, $context, $token, $config );
			return $verified ? $html : '<p>Signing is temporarily unavailable because the form context could not be populated. Please contact the shop.</p>';
		}
		finally { self::$rendering = null; }
	}
	/** Inspect actual provider output, not a successful hook alone. Fail closed on incompatible markup. */
	public static function verify_rendered( $html, $ctx, $token, $config ) {
		$tags = new \WP_HTML_Tag_Processor( $html ); $values = array();
		while ( $tags->next_tag( array( 'tag_name' => 'INPUT' ) ) ) {
			$name = $tags->get_attribute( 'name' ); if ( is_string( $name ) ) { $values[ $name ][] = $tags->get_attribute( 'value' ) ?? ''; }
		}
		$checks = array(); foreach ( self::expected( $ctx ) as $key => $expected ) { $checks[ $key ] = ( $values[ 'wpforms[fields][' . $config['mapping'][ $key ] . ']' ] ?? null ) === array( $expected ); }
		$checks['bearer_present_separately'] = ( $values['wpforms[brp_waiver_token]'] ?? null ) === array( $token );
		self::diagnose( 'rendered_inputs', $checks ); return ! in_array( false, $checks, true );
	}
	private static function diagnose( $stage, $checks ) {
		if ( ! defined( 'BRP_WAIVER_DIAGNOSTICS' ) || ! BRP_WAIVER_DIAGNOSTICS ) { return; }
		$record = get_transient( 'brp_waiver_diagnostics' ) ?: array();
		$record[ $stage ] = array( 'observed_utc' => gmdate( 'c' ), 'checks' => array_map( 'boolval', $checks ) );
		set_transient( 'brp_waiver_diagnostics', $record, DAY_IN_SECONDS );
	}
	public static function properties( $properties, $field, $form ) {
		if ( ! self::$rendering || (int) $form['id'] !== (int) self::$rendering['config']['form_id'] ) { return $properties; }
		$key = array_search( (int) $field['id'], array_map( 'intval', self::$rendering['config']['mapping'] ), true ); $expected = self::expected( self::$rendering['context'] );
		if ( $key && array_key_exists( $key, $expected ) && isset( $properties['inputs']['primary']['attr'] ) ) {
			$properties['inputs']['primary']['attr']['value'] = $expected[ $key ];
			if ( 'signer_email' === $key && isset( $properties['inputs']['secondary']['attr'] ) ) { $properties['inputs']['secondary']['attr']['value'] = $expected[ $key ]; }
			self::diagnose( 'field_properties', array( 'filter_ran' => true, 'mapped_value_set' => true ) );
		}
		return $properties;
	}
	public static function token_field( $form ) {
		if ( self::$rendering && (int) $form['id'] === (int) self::$rendering['config']['form_id'] ) { echo '<input type="hidden" name="wpforms[brp_waiver_token]" value="' . esc_attr( self::$rendering['token'] ) . '">'; }
	}
	private static function fail( $form ) { wpforms()->process->errors[ $form['id'] ]['header'] = 'This waiver could not be verified. Use your current invitation, sign only for the named rider, and complete all required fields.'; }
	/** Retain the bearer in memory, never in the raw entry handed to provider storage/hooks. */
	public static function before_process( $entry, $form ) {
		self::$submitted[ (int) $form['id'] ] = $entry['brp_waiver_token'] ?? '';
		unset( $entry['brp_waiver_token'] );
		if ( isset( $_POST['wpforms'] ) && is_array( $_POST['wpforms'] ) ) { unset( $_POST['wpforms']['brp_waiver_token'] ); }
		return $entry;
	}
	/** Invalid or direct submissions to the configured dedicated form fail before entry storage. */
	public static function process( $fields, $entry, $form ) {
		unset( self::$validated[ (int) $form['id'] ] );
		$token = $entry['brp_waiver_token'] ?? ( self::$submitted[ (int) $form['id'] ] ?? '' ); unset( self::$submitted[ (int) $form['id'] ] );
		$ctx = Waivers::context( $token ); $configured = (int) ( WaiverSettings::get()['wpforms']['form_id'] ?? 0 );
		if ( is_wp_error( $ctx ) ) { if ( $token || (int) $form['id'] === $configured ) { self::fail( $form ); } return $fields; }
		$w = $ctx['waiver']; $config = json_decode( $w['provider_config'], true );
		if ( 'wpforms' !== $w['provider'] || (int) $form['id'] !== (int) $config['form_id'] || is_wp_error( ( new self() )->configuration( $config ) ) ) { self::fail( $form ); return $fields; }
		// WPForms stores these POST metadata URLs separately from mapped fields. Never retain a bearer in them.
		foreach ( array( 'page_url', 'url_referer' ) as $key ) { if ( isset( $_POST[ $key ] ) ) { $_POST[ $key ] = wp_slash( remove_query_arg( 'brp_waiver', is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '' ) ); } }
		foreach ( self::expected( $ctx ) as $key => $value ) {
			$id = $config['mapping'][ $key ]; $actual = $fields[ $id ]['value'] ?? null;
			if ( ! is_scalar( $actual ) || ( 'signer_email' === $key ? strtolower( trim( (string) $actual ) ) : trim( (string) $actual ) ) !== (string) $value ) { self::fail( $form ); return $fields; }
		}
		if ( empty( $fields[ $config['mapping']['signature'] ]['value'] ) || empty( $fields[ $config['mapping']['consent'] ]['value'] ) ) { self::fail( $form ); return $fields; }
		self::diagnose( 'submission', array( 'bearer_present_separately' => true, 'mapped_values_match' => true, 'signature_and_acceptance_present' => true ) );
		self::$validated[ (int) $form['id'] ] = $token; return $fields; // Secret is outside fields, never copied into entry fields.
	}
	public static function completed( $fields, $entry, $form, $entry_id ) {
		$token = self::$validated[ (int) $form['id'] ] ?? null;
		if ( ! $token || ! Database::positive( $entry_id ) ) { return; }
		unset( self::$validated[ (int) $form['id'] ] );
		$result = Waivers::complete( $token, (string) $entry_id ); self::$completed = ! is_wp_error( $result );
		self::diagnose( 'completion', array( 'completion_hook_ran' => true, 'intended_rider_completed' => self::$completed ) );
		self::$result_forms[ (int) $form['id'] ] = self::$completed;
		if ( self::$completed ) { do_action( 'brp_waiver_completed', $result ); }
	}
	public function verify_completion( array $context, string $submission ) {
		$config = json_decode( $context['waiver']['provider_config'], true ); $ready = $this->configuration( $config ); if ( is_wp_error( $ready ) ) { return $ready; }
		if ( ! Database::positive( $submission ) ) { return Database::error( 'waiver_proof', 'A persisted entry is required.' ); }
		// The validated single-rider bearer authorizes this internal read for a guest signer.
		$entry = wpforms()->entry->get( (int) $submission, array( 'cap' => false ) );
		self::diagnose( 'stored_entry', array( 'reread' => true, 'found' => is_object( $entry ) ) );
		if ( ! is_object( $entry ) || (int) ( $entry->form_id ?? 0 ) !== (int) $config['form_id'] || ! empty( $entry->status ) && in_array( $entry->status, array( 'spam', 'trash' ), true ) ) { return Database::error( 'waiver_proof', 'The saved provider entry is unavailable or invalid.' ); }
		$fields = json_decode( $entry->fields ?? '', true ); if ( ! is_array( $fields ) ) { return Database::error( 'waiver_proof', 'Saved entry fields are unavailable.' ); }
		foreach ( self::expected( $context ) as $key => $expected ) {
			$value = $fields[ $config['mapping'][ $key ] ]['value'] ?? null;
			if ( ! is_scalar( $value ) || ( 'signer_email' === $key ? strtolower( trim( (string) $value ) ) : trim( (string) $value ) ) !== $expected ) { return Database::error( 'waiver_proof', 'Saved provider identity/context does not match this rider.' ); }
		}
		$signature = $fields[ $config['mapping']['signature'] ] ?? array();
		if ( 'signature' !== ( $signature['type'] ?? '' ) || ! is_string( $signature['value'] ?? null ) || ! filter_var( $signature['value'], FILTER_VALIDATE_URL ) || empty( $fields[ $config['mapping']['consent'] ]['value'] ) ) { return Database::error( 'waiver_signature', 'A stored signature reference and explicit acceptance are required.' ); }
		self::diagnose( 'stored_entry', array( 'reread' => true, 'context_matches' => true, 'signature_reference_present' => true, 'acceptance_present' => true ) );
		return array( 'submission' => $submission, 'signer_name' => $context['waiver']['signer_name'], 'signer_email' => $context['waiver']['signer_email'], 'text_hash' => $context['waiver']['text_hash'] );
	}
}
