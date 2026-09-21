<?php
/** Presentation-only settings stored inside the existing rental settings option. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class Branding {
	public static function defaults() {
		return array( 'heading' => 'Choose Your Rental', 'intro' => '<p>Choose a rental below, then select your date, start time, and number of bikes.</p>', 'select_text' => 'Select Rental', 'selected_text' => 'Selected', 'change_text' => 'Change Rental', 'accent' => '', 'button_bg' => '', 'button_text' => '', 'selected_border' => '', 'card_bg' => '', 'card_text' => '', 'card_border' => '', 'radius' => 'default', 'logo_id' => 0, 'custom_css' => '' );
	}
	public static function colors() {
		return array( 'accent' => 'Primary Accent Color', 'button_bg' => 'Primary Button Background Color', 'button_text' => 'Primary Button Text Color', 'selected_border' => 'Selected Package Border / Highlight Color', 'card_bg' => 'Card Background Color', 'card_text' => 'Product Card Text Color', 'card_border' => 'Card Border Color' );
	}
	public static function texts() {
		return array( 'heading' => 'Booking Section Heading', 'select_text' => 'Select Rental Button Text', 'selected_text' => 'Selected Button Text', 'change_text' => 'Change Rental Button Text' );
	}
	public static function radii() { return array( 'default' => 'Current defaults', 'square' => 'Square', 'slight' => 'Slightly Rounded', 'rounded' => 'Rounded', 'very' => 'Very Rounded' ); }
	public static function intro( $text ) {
		$text = preg_replace( '~<(script|style|iframe|object)\b[^>]*>.*?</\1\s*>~is', '', $text );
		return wp_kses( $text, array( 'p' => array(), 'strong' => array(), 'em' => array(), 'b' => array(), 'i' => array(), 'br' => array(), 'a' => array( 'href' => true, 'title' => true ) ) );
	}
	public static function color( $value ) {
		if ( ! is_string( $value ) ) { return false; }
		$value = trim( $value );
		if ( '' === $value ) { return ''; }
		$hex = sanitize_hex_color( $value );
		if ( ! $hex ) { return false; }
		if ( 4 === strlen( $hex ) ) { $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3]; }
		return strtolower( $hex );
	}
	/** Restricted flat CSS grammar, rechecked on output. No URL, escapes, at-rules or HTML. */
	public static function css( $value, $scope = '.brp-booking' ) {
		if ( ! is_string( $value ) || strlen( $value ) > 8000 || preg_match( '/[<@\\\\\x00-\x08\x0b\x0c\x0e-\x1f]/', $value ) ) { return false; }
		$value = preg_replace( '~/\*.*?\*/~s', '', $value );
		$output = array();
		$token = '(?:[a-z][a-z0-9-]*(?:\.[a-zA-Z_][a-zA-Z0-9_-]*)*|(?:\.[a-zA-Z_][a-zA-Z0-9_-]*)+)(?::(?:hover|focus|focus-visible|active|disabled|first-child|last-child))?';
		$properties = array( 'color', 'background-color', 'border', 'border-color', 'border-width', 'border-style', 'border-radius', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'gap', 'row-gap', 'column-gap', 'width', 'max-width', 'min-width', 'height', 'max-height', 'min-height', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-align', 'text-decoration', 'text-transform', 'box-shadow', 'outline', 'outline-color', 'outline-width', 'outline-offset', 'opacity' );
		while ( '' !== trim( $value ) ) {
			if ( ! preg_match( '/\A\s*([^{}]+)\{([^{}]*)\}/s', $value, $rule ) ) { return false; }
			$value = substr( $value, strlen( $rule[0] ) ); $selectors = array();
			foreach ( explode( ',', $rule[1] ) as $selector ) {
				$selector = trim( $selector );
				if ( strlen( $selector ) > 240 || ! preg_match( '/\A\.brp-booking(?:\s+(?:>\s*)?' . $token . ')*\z/D', $selector ) ) { return false; }
				$selectors[] = $scope . substr( $selector, strlen( '.brp-booking' ) );
			}
			$declarations = array();
			foreach ( explode( ';', $rule[2] ) as $declaration ) {
				if ( '' === trim( $declaration ) ) { continue; }
				if ( ! preg_match( '/\A\s*([a-z-]+)\s*:\s*([a-zA-Z0-9#%.,()!\s-]+)\s*\z/D', $declaration, $parts ) || ! in_array( $parts[1], $properties, true ) ) { return false; }
				$property_value = trim( $parts[2] );
				// Permit simple color functions only; never url(), expression(), var() or nesting.
				$plain = preg_replace( '/\b(?:rgb|rgba|hsl|hsla)\(\s*[0-9.%\s,]+\)/i', '', $property_value );
				if ( strpbrk( $plain, '()' ) || preg_match( '/(?:expression|javascript|vbscript|behavior|binding)/i', $property_value ) ) { return false; }
				$declarations[] = $parts[1] . ': ' . $property_value;
			}
			if ( $declarations ) { $output[] = implode( ', ', $selectors ) . ' { ' . implode( '; ', $declarations ) . '; }'; }
		}
		return implode( "\n", $output );
	}
	/** Save validation. Missing keys use defaults; absent whole section is preserved by Settings. */
	public static function validate( $input ) {
		$values = self::defaults(); $errors = array();
		if ( ! is_array( $input ) ) { return array( 'values' => $values, 'errors' => array( 'Booking branding must be submitted as a settings section.' ) ); }
		foreach ( self::texts() as $key => $label ) {
			$text = $input[ $key ] ?? $values[ $key ];
			if ( ! is_string( $text ) || strlen( $text ) > 240 ) { $errors[] = $label . ' must be plain text, at most 240 bytes.'; continue; }
			$values[ $key ] = sanitize_text_field( $text );
			if ( 'heading' !== $key && '' === $values[ $key ] ) { $values[ $key ] = self::defaults()[ $key ]; }
		}
		$intro = $input['intro'] ?? $values['intro'];
		if ( ! is_string( $intro ) || strlen( $intro ) > 8000 ) { $errors[] = 'Intro Text must be at most 8000 bytes.'; }
		else { $values['intro'] = self::intro( $intro ); }
		foreach ( self::colors() as $key => $label ) {
			$color = self::color( $input[ $key ] ?? '' );
			if ( false === $color ) { $errors[] = $label . ' must be blank or a 3/6-digit hex color such as #336699.'; }
			else { $values[ $key ] = $color; }
		}
		$radius = $input['radius'] ?? 'default';
		if ( ! is_string( $radius ) || ! array_key_exists( $radius, self::radii() ) ) { $errors[] = 'Choose a valid Border Radius preset.'; }
		else { $values['radius'] = $radius; }
		$id = $input['logo_id'] ?? 0;
		if ( ! in_array( $id, array( 0, '0', '' ), true ) && ( ! Database::positive( $id ) || ! wp_attachment_is_image( (int) $id ) ) ) { $errors[] = 'Choose a valid Media Library image, or remove the logo.'; }
		else { $values['logo_id'] = (int) $id; }
		$css = self::css( $input['custom_css'] ?? '' );
		if ( false === $css ) { $errors[] = 'Advanced Custom CSS requires flat .brp-booking rules with supported visual properties. HTML, at-rules, URLs, escapes, nested rules and unscoped selectors are not supported.'; }
		else { $values['custom_css'] = $css; }
		return array( 'values' => $values, 'errors' => $errors );
	}
	/** Bad saved presentation values fall back per field, without blocking booking/scheduling. */
	public static function get() {
		$settings = Settings::get();
		$branding = is_array( $settings ) && is_array( $settings['branding'] ?? null ) ? $settings['branding'] : array();
		return self::validate( $branding )['values'];
	}
	public static function variables( $values ) {
		$css = array();
		foreach ( self::colors() as $key => $label ) {
			$value = self::color( $values[ $key ] ?? '' );
			if ( $value ) { $css[] = '--brp-' . str_replace( '_', '-', $key ) . ':' . $value; }
		}
		$radius = array( 'square' => '0', 'slight' => '.25rem', 'rounded' => '.75rem', 'very' => '1.5rem' )[ $values['radius'] ?? 'default' ] ?? '';
		if ( '' !== $radius ) { $css[] = '--brp-radius:' . $radius; }
		return implode( ';', $css );
	}
	public static function logo( $id ) {
		if ( ! Database::positive( $id ) || ! wp_attachment_is_image( $id ) ) { return ''; }
		return wp_kses( wp_get_attachment_image( $id, 'medium', false, array( 'class' => 'brp-logo', 'decoding' => 'async' ) ), array( 'img' => array_fill_keys( array( 'src', 'srcset', 'sizes', 'width', 'height', 'alt', 'class', 'loading', 'decoding', 'fetchpriority' ), true ) ) );
	}
	public static function assets( $hook ) {
		if ( 'toplevel_page_' . Settings::PAGE !== $hook || ! Settings::can_manage() || 'branding' !== Settings::tab( $_GET['tab'] ?? null ) ) { return; }
		wp_enqueue_style( 'wp-color-picker' ); wp_enqueue_media();
		wp_enqueue_script( 'brp-branding-admin', plugins_url( 'assets/js/branding-admin.js', dirname( __DIR__ ) . '/bike-rental-plugin.php' ), array( 'jquery', 'wp-color-picker', 'media-views' ), Plugin::VERSION, true );
	}
}
