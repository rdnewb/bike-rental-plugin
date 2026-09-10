<?php
/**
 * Native administration settings and strict, all-or-nothing validation.
 *
 * @package BikeRentalPlugin
 */

namespace BikeRentalPlugin;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'brp_settings';
	const GROUP  = 'brp_settings_group';
	const PAGE   = 'brp-settings';

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( self::class, 'capability' ) );
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
	}

	/** Use the same capability for menu access and the core options.php save. */
	public static function capability() {
		return current_user_can( 'manage_options' ) ? 'manage_options' : 'manage_woocommerce';
	}

	public static function days() {
		return array(
			'monday'    => __( 'Monday', 'bike-rental-plugin' ),
			'tuesday'   => __( 'Tuesday', 'bike-rental-plugin' ),
			'wednesday' => __( 'Wednesday', 'bike-rental-plugin' ),
			'thursday'  => __( 'Thursday', 'bike-rental-plugin' ),
			'friday'    => __( 'Friday', 'bike-rental-plugin' ),
			'saturday'  => __( 'Saturday', 'bike-rental-plugin' ),
			'sunday'    => __( 'Sunday', 'bike-rental-plugin' ),
		);
	}

	public static function defaults() {
		$hours = array();
		foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $day ) {
			$hours[ $day ] = array( 'open' => 0, 'start' => '09:00', 'end' => '17:00' );
		}
		return array(
			'business_name'       => '',
			'booking_horizon'     => 90,
			'minimum_notice'      => 0,
			'time_increment'      => 30,
			'pickup_time'         => '17:00',
			'preparation_buffer'  => 0,
			'turnaround_buffer'   => 0,
			'weekly_hours'        => $hours,
		);
	}

	/** Read saved values unchanged; display defaults are never written on update. */
	public static function get() {
		$saved = get_option( self::OPTION, false );
		return false === $saved ? self::defaults() : $saved;
	}

	public function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	public function add_menu() {
		add_menu_page( __( 'Bike Rental Plugin', 'bike-rental-plugin' ), __( 'Bike Rentals', 'bike-rental-plugin' ), self::capability(), self::PAGE, array( $this, 'render' ), 'dashicons-location-alt' );
		add_submenu_page( self::PAGE, __( 'Bike Rental Plugin Settings', 'bike-rental-plugin' ), __( 'Settings', 'bike-rental-plugin' ), self::capability(), self::PAGE, array( $this, 'render' ) );
	}

	/** Inclusive numeric limits, all in minutes except the horizon in days. */
	public static function number_fields() {
		return array(
			'booking_horizon'    => array( 'label' => __( 'Booking horizon (days)', 'bike-rental-plugin' ), 'min' => 1, 'max' => 365 ),
			'minimum_notice'     => array( 'label' => __( 'Minimum booking notice (minutes)', 'bike-rental-plugin' ), 'min' => 0, 'max' => 10080 ),
			'preparation_buffer' => array( 'label' => __( 'Preparation buffer (minutes)', 'bike-rental-plugin' ), 'min' => 0, 'max' => 1440 ),
			'turnaround_buffer'  => array( 'label' => __( 'Turnaround buffer (minutes)', 'bike-rental-plugin' ), 'min' => 0, 'max' => 1440 ),
		);
	}

	private static function integer_in_range( $value, $min, $max ) {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return false;
		}
		return 1 === preg_match( '/\A[0-9]+\z/', (string) $value ) && $value >= $min && $value <= $max;
	}

	private static function valid_time( $value ) {
		return is_string( $value ) && 1 === preg_match( '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $value );
	}

	/**
	 * No persistence or side effects: usable for save validation and readiness.
	 * Blank business name and all-closed hours are valid incomplete drafts.
	 *
	 * @return array {values: array, errors: array}
	 */
	public static function validate( $input ) {
		$values = array();
		$errors = array();
		if ( ! is_array( $input ) ) {
			return array( 'values' => array(), 'errors' => array( __( 'Settings must be submitted as a complete form.', 'bike-rental-plugin' ) ) );
		}
		$name = $input['business_name'] ?? null;
		if ( ! is_string( $name ) ) {
			$errors[] = __( 'Business name must be plain text.', 'bike-rental-plugin' );
		} else {
			$name = sanitize_text_field( $name );
			if ( strlen( $name ) > 240 ) {
				$errors[] = __( 'Business name must be no more than 240 bytes of plain text.', 'bike-rental-plugin' );
			} else {
				$values['business_name'] = $name;
			}
		}
		foreach ( self::number_fields() as $key => $field ) {
			$value = $input[ $key ] ?? null;
			if ( ! self::integer_in_range( $value, $field['min'], $field['max'] ) ) {
				/* translators: 1: setting label, 2: minimum, 3: maximum. */
				$errors[] = sprintf( __( '%1$s must be a whole number from %2$d to %3$d.', 'bike-rental-plugin' ), $field['label'], $field['min'], $field['max'] );
			} else {
				$values[ $key ] = (int) $value;
			}
		}
		$increment = $input['time_increment'] ?? null;
		if ( ! self::integer_in_range( $increment, 5, 60 ) || ! in_array( (int) $increment, array( 5, 10, 15, 20, 30, 60 ), true ) ) {
			$errors[] = __( 'Booking time increment must be 5, 10, 15, 20, 30, or 60 minutes.', 'bike-rental-plugin' );
		} else {
			$values['time_increment'] = (int) $increment;
		}
		if ( ! self::valid_time( $input['pickup_time'] ?? null ) ) {
			$errors[] = __( 'Calendar-day pickup time must use 24-hour HH:MM format.', 'bike-rental-plugin' );
		} else {
			$values['pickup_time'] = $input['pickup_time'];
		}
		$hours = $input['weekly_hours'] ?? null;
		foreach ( self::days() as $day => $label ) {
			$row = is_array( $hours ) ? ( $hours[ $day ] ?? null ) : null;
			if ( ! is_array( $row ) || ! in_array( $row['open'] ?? null, array( 0, 1, '0', '1' ), true ) || ! self::valid_time( $row['start'] ?? null ) || ! self::valid_time( $row['end'] ?? null ) ) {
				/* translators: %s: weekday. */
				$errors[] = sprintf( __( '%s needs an Open/Closed selection and valid opening and closing times.', 'bike-rental-plugin' ), $label );
				continue;
			}
			if ( (int) $row['open'] && $row['end'] <= $row['start'] ) {
				/* translators: %s: weekday. */
				$errors[] = sprintf( __( '%s closing time must be later than opening time. Overnight opening hours are not supported yet.', 'bike-rental-plugin' ), $label );
				continue;
			}
			$values['weekly_hours'][ $day ] = array( 'open' => (int) $row['open'], 'start' => $row['start'], 'end' => $row['end'] );
		}
		return array( 'values' => $values, 'errors' => $errors );
	}

	/** Core options.php verifies the settings_fields nonce before calling this. */
	public function sanitize( $input ) {
		if ( ! self::can_manage() ) {
			add_settings_error( self::OPTION, 'brp_permission', __( 'You do not have permission to change rental settings.', 'bike-rental-plugin' ) );
			return self::get();
		}
		$result = self::validate( $input );
		if ( $result['errors'] ) {
			foreach ( $result['errors'] as $index => $error ) {
				add_settings_error( self::OPTION, 'brp_invalid_' . $index, $error );
			}
			add_settings_error( self::OPTION, 'brp_unchanged', __( 'Nothing was saved. Your previous settings are shown below.', 'bike-rental-plugin' ) );
			return self::get();
		}
		return $result['values'];
	}

	public static function configuration_status( $saved ) {
		$result = self::validate( $saved );
		if ( $result['errors'] ) {
			return __( 'Partially Configured', 'bike-rental-plugin' );
		}
		if ( $result['values'] == self::defaults() ) {
			return __( 'Not Configured', 'bike-rental-plugin' );
		}
		$open_days = array_filter( $result['values']['weekly_hours'], static function ( $day ) { return 1 === $day['open']; } );
		if ( '' !== $result['values']['business_name'] && $open_days ) {
			return __( 'Ready for Package Setup', 'bike-rental-plugin' );
		}
		return __( 'Partially Configured', 'bike-rental-plugin' );
	}

	public function render() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to view rental settings.', 'bike-rental-plugin' ) );
		}
		$saved = self::get();
		// Malformed options should be repairable without emitting PHP warnings.
		$result = self::validate( $saved );
		$values = $result['errors'] ? self::defaults() : $result['values'];
		$status = self::configuration_status( $saved );
		$dependencies = Plugin::dependencies();
		require __DIR__ . '/settings-page.php';
	}
}
