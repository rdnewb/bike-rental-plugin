<?php
/**
 * Native settings view. Included only after the capability check in Settings.
 *
 * @package BikeRentalPlugin
 */

namespace BikeRentalPlugin;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Bike Rental Plugin', 'bike-rental-plugin' ); ?></h1>
	<p><?php esc_html_e( 'Version', 'bike-rental-plugin' ); ?> <?php echo esc_html( Plugin::VERSION ); ?> &mdash; <?php echo esc_html( $status ); ?></p>
	<p><?php esc_html_e( 'These settings control rental scheduling and payment mode. Full Payment uses WooCommerce Square. Deposit checkout is unavailable until a compatible provider is selected.', 'bike-rental-plugin' ); ?></p>
	<?php settings_errors(); ?>
	<?php if ( $result['errors'] ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Stored settings need repair. Defaults are displayed but have not replaced your saved data. Review all fields and save to repair the configuration.', 'bike-rental-plugin' ); ?></p></div>
	<?php endif; ?>
	<p><strong><?php esc_html_e( 'WordPress timezone:', 'bike-rental-plugin' ); ?></strong> <?php echo esc_html( wp_timezone_string() ); ?></p>
	<?php
	$timezone = get_option( 'timezone_string', '' );
	if ( ! is_string( $timezone ) || ! in_array( $timezone, timezone_identifiers_list(), true ) ) :
		?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'WordPress is using a fixed offset or an unrecognized timezone. Choose a named city timezone in WordPress Settings > General so future rentals can follow daylight saving changes. This plugin has not changed your timezone.', 'bike-rental-plugin' ); ?></p></div>
	<?php endif; ?>
	<h2><?php esc_html_e( 'Dependencies and integrations', 'bike-rental-plugin' ); ?></h2>
	<p><?php esc_html_e( 'Available means an active plugin or runtime identifier was detected. It does not verify configuration, licensing, or compatibility. WooCommerce is needed for later rental checkout; the foundation remains usable without it.', 'bike-rental-plugin' ); ?></p>
	<table class="widefat striped">
		<thead><tr><th scope="col"><?php esc_html_e( 'Component', 'bike-rental-plugin' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'bike-rental-plugin' ); ?></th><th scope="col"><?php esc_html_e( 'Integration', 'bike-rental-plugin' ); ?></th><th scope="col"><?php esc_html_e( 'Detection details', 'bike-rental-plugin' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $dependencies as $dependency ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $dependency['label'] ); ?></th>
				<td><?php echo esc_html( $dependency['available'] ? __( 'Available', 'bike-rental-plugin' ) : __( 'Missing', 'bike-rental-plugin' ) ); ?></td>
				<td><?php esc_html_e( 'Not yet integration tested', 'bike-rental-plugin' ); ?></td>
				<td>
				<?php if ( ! $dependency['installed'] ) : ?>
					<?php echo esc_html( $dependency['runtime'] ? __( 'Runtime identifier detected.', 'bike-rental-plugin' ) : __( 'No recognized installation detected.', 'bike-rental-plugin' ) ); ?>
				<?php else : ?>
					<?php foreach ( $dependency['installed'] as $installed ) : ?>
						<div><?php echo esc_html( $installed['name'] ); ?> &mdash; <?php echo esc_html( $installed['active'] ? __( 'Active', 'bike-rental-plugin' ) : __( 'Installed, inactive', 'bike-rental-plugin' ) ); ?></div>
					<?php endforeach; ?>
				<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p><?php esc_html_e( 'A detected WPForms installation may be Lite. Elite licensing and Signature Addon functionality still need verification. Unrecognized deposit extensions need manual identification before integration.', 'bike-rental-plugin' ); ?></p>
	<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
		<?php settings_fields( Settings::GROUP ); ?>
		<h2><?php esc_html_e( 'General and rental scheduling', 'bike-rental-plugin' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="brp-payment-mode">Payment Mode</label></th><td><select id="brp-payment-mode" name="brp_settings[payment_mode]"><option value="full" <?php selected( $values['payment_mode'], 'full' ); ?>>Full Payment</option><option value="deposit" <?php selected( $values['payment_mode'], 'deposit' ); ?>>Deposit</option></select><p class="description">Deposit mode blocks new rental checkout until a compatible provider is configured. Existing checkout payment-mode snapshots are retained.</p></td></tr>
			<tr><th scope="row"><label for="brp-business-name"><?php esc_html_e( 'Business name', 'bike-rental-plugin' ); ?></label></th>
				<td><input class="regular-text" type="text" id="brp-business-name" name="brp_settings[business_name]" value="<?php echo esc_attr( $values['business_name'] ); ?>" placeholder="<?php esc_attr_e( 'Your rental business', 'bike-rental-plugin' ); ?>"><p class="description"><?php esc_html_e( 'Plain text. Leave blank while setting up.', 'bike-rental-plugin' ); ?></p></td></tr>
			<?php foreach ( Settings::number_fields() as $key => $field ) : ?>
				<tr><th scope="row"><label for="brp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
					<td><input class="small-text" type="number" required step="1" id="brp-<?php echo esc_attr( $key ); ?>" name="brp_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $values[ $key ] ); ?>" min="<?php echo esc_attr( $field['min'] ); ?>" max="<?php echo esc_attr( $field['max'] ); ?>"></td></tr>
			<?php endforeach; ?>
			<tr><th scope="row"><label for="brp-time-increment"><?php esc_html_e( 'Booking time increment (minutes)', 'bike-rental-plugin' ); ?></label></th>
				<td><select id="brp-time-increment" name="brp_settings[time_increment]">
					<?php foreach ( array( 5, 10, 15, 20, 30, 60 ) as $increment ) : ?>
						<option value="<?php echo esc_attr( $increment ); ?>" <?php selected( $values['time_increment'], $increment ); ?>><?php echo esc_html( $increment ); ?></option>
					<?php endforeach; ?>
				</select></td></tr>
			<tr><th scope="row"><label for="brp-pickup-time"><?php esc_html_e( 'Calendar-day pickup time', 'bike-rental-plugin' ); ?></label></th>
				<td><input type="time" required step="60" id="brp-pickup-time" name="brp_settings[pickup_time]" value="<?php echo esc_attr( $values['pickup_time'] ); ?>"><p class="description"><?php esc_html_e( 'Business-controlled local pickup time in the WordPress timezone. Calendar rentals end on start date + (duration - 1) days at this time, independently of final-day delivery/start hours. Intermediate and final days may be closed for new starts. A one-day rental must start before pickup. Check AM/PM when entering the time.', 'bike-rental-plugin' ); ?></p></td></tr>
		</table>
		<h2><?php esc_html_e( 'Weekly operating hours', 'bike-rental-plugin' ); ?></h2>
		<p><?php esc_html_e( 'All days start closed. Opening and closing times are retained for closed days but do not make those days open. Open days must close later on the same day.', 'bike-rental-plugin' ); ?></p>
		<table class="widefat striped">
			<thead><tr><th scope="col"><?php esc_html_e( 'Day', 'bike-rental-plugin' ); ?></th><th scope="col"><?php esc_html_e( 'Open / Closed', 'bike-rental-plugin' ); ?></th><th scope="col"><?php esc_html_e( 'Opening time', 'bike-rental-plugin' ); ?></th><th scope="col"><?php esc_html_e( 'Closing time', 'bike-rental-plugin' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( Settings::days() as $day => $label ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td><label class="screen-reader-text" for="brp-<?php echo esc_attr( $day ); ?>-open"><?php echo esc_html( $label . ' ' . __( 'Open / Closed', 'bike-rental-plugin' ) ); ?></label>
						<select id="brp-<?php echo esc_attr( $day ); ?>-open" name="brp_settings[weekly_hours][<?php echo esc_attr( $day ); ?>][open]">
							<option value="0" <?php selected( $values['weekly_hours'][ $day ]['open'], 0 ); ?>><?php esc_html_e( 'Closed', 'bike-rental-plugin' ); ?></option>
							<option value="1" <?php selected( $values['weekly_hours'][ $day ]['open'], 1 ); ?>><?php esc_html_e( 'Open', 'bike-rental-plugin' ); ?></option>
						</select></td>
					<?php foreach ( array( 'start' => __( 'Opening time', 'bike-rental-plugin' ), 'end' => __( 'Closing time', 'bike-rental-plugin' ) ) as $part => $part_label ) : ?>
						<td><label class="screen-reader-text" for="brp-<?php echo esc_attr( $day . '-' . $part ); ?>"><?php echo esc_html( $label . ' ' . $part_label ); ?></label><input type="time" required step="60" id="brp-<?php echo esc_attr( $day . '-' . $part ); ?>" name="brp_settings[weekly_hours][<?php echo esc_attr( $day ); ?>][<?php echo esc_attr( $part ); ?>]" value="<?php echo esc_attr( $values['weekly_hours'][ $day ][ $part ] ); ?>"></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><?php esc_html_e( 'Ready for Package Setup requires a business name, valid scheduling settings, and at least one open day. It does not mean the system is ready for rentals.', 'bike-rental-plugin' ); ?></p>
		<?php submit_button( __( 'Save settings', 'bike-rental-plugin' ) ); ?>
		<?php require __DIR__ . '/branding-fields.php'; ?>
		<?php submit_button( __( 'Save settings', 'bike-rental-plugin' ) ); ?>
	</form>
</div>
