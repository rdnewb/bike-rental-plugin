<?php
/** Rental tab in WooCommerce's standard product data editor. @package BikeRentalPlugin */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

$enabled = 'yes' === $product->get_meta( Packages::ENABLED, true, 'edit' );
$active = 'yes' === $product->get_meta( Packages::ACTIVE, true, 'edit' );
$type = $product->get_meta( Packages::TYPE, true, 'edit' );
$type = in_array( $type, array( 'hours', 'calendar_days' ), true ) ? $type : 'hours';
$amount = $product->get_meta( Packages::AMOUNT, true, 'edit' );
$promo = $product->get_meta( Packages::PROMO, true, 'edit' );
?>
<div id="brp_rental_data" class="panel woocommerce_options_panel hidden">
	<?php wp_nonce_field( 'brp_save_package_' . $product->get_id(), 'brp_package_nonce' ); ?>
	<div class="options_group">
		<p class="form-field">
			<label for="brp-package-enabled"><?php esc_html_e( 'Use as Rental Package', 'bike-rental-plugin' ); ?></label>
			<input type="hidden" name="brp_package[enabled]" value="no">
			<input type="checkbox" class="checkbox" id="brp-package-enabled" name="brp_package[enabled]" value="yes" <?php checked( $enabled ); ?>>
			<span class="description"><?php esc_html_e( 'Only Simple products can be rental packages. Unchecking removes the rental metadata when saved.', 'bike-rental-plugin' ); ?></span>
		</p>
	</div>
	<div class="options_group brp-package-fields">
		<p class="form-field">
			<label for="brp-duration-type"><?php esc_html_e( 'Duration Type', 'bike-rental-plugin' ); ?></label>
			<select id="brp-duration-type" name="brp_package[duration_type]">
				<option value="hours" <?php selected( $type, 'hours' ); ?>><?php esc_html_e( 'Hours', 'bike-rental-plugin' ); ?></option>
				<option value="calendar_days" <?php selected( $type, 'calendar_days' ); ?>><?php esc_html_e( 'Calendar Days', 'bike-rental-plugin' ); ?></option>
			</select>
		</p>
		<p class="form-field">
			<label for="brp-duration-amount"><?php esc_html_e( 'Duration Amount', 'bike-rental-plugin' ); ?></label>
			<input type="number" class="short" id="brp-duration-amount" name="brp_package[duration_amount]" min="1" max="<?php echo esc_attr( Packages::MAX_HOURS ); ?>" step="1" value="<?php echo esc_attr( is_scalar( $amount ) ? $amount : '' ); ?>">
			<span class="description"><?php esc_html_e( 'Whole number: up to 8,760 hours or 365 calendar days.', 'bike-rental-plugin' ); ?></span>
		</p>
		<p class="form-field">
			<label for="brp-promotional-label"><?php esc_html_e( 'Promotional Label', 'bike-rental-plugin' ); ?></label>
			<input type="text" class="short" id="brp-promotional-label" name="brp_package[promotional_label]" maxlength="120" value="<?php echo esc_attr( is_string( $promo ) ? $promo : '' ); ?>">
			<span class="description"><?php esc_html_e( 'Optional plain text, up to 120 characters. This does not change the price.', 'bike-rental-plugin' ); ?></span>
		</p>
		<p class="form-field">
			<label for="brp-rental-active"><?php esc_html_e( 'Rental Active', 'bike-rental-plugin' ); ?></label>
			<input type="hidden" name="brp_package[active]" value="no">
			<input type="checkbox" class="checkbox" id="brp-rental-active" name="brp_package[active]" value="yes" <?php checked( $active ); ?>>
			<span class="description"><?php esc_html_e( 'Include in the future rental package list when published with valid duration and regular price. Public rental booking is not implemented yet.', 'bike-rental-plugin' ); ?></span>
		</p>
	</div>
	<p class="form-field"><?php esc_html_e( 'Use the standard product title, Regular price, tax fields, description, and image. Use Advanced > Menu order for display order. Sale prices and promotional labels do not change the package reader’s regular price. Rental Active does not change ordinary WooCommerce storefront purchasing.', 'bike-rental-plugin' ); ?></p>
</div>
