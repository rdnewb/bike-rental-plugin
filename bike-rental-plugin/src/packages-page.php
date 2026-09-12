<?php
/** Read-only package overview, with editing delegated to WooCommerce. @package BikeRentalPlugin */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Rental Packages', 'bike-rental-plugin' ); ?></h1>
	<p><?php esc_html_e( 'Create a Simple product in WooCommerce, then enable Use as Rental Package under Rental Settings. Edit all package fields in that product editor.', 'bike-rental-plugin' ); ?></p>
	<?php if ( current_user_can( 'publish_products' ) ) : ?>
		<p><a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product' ) ); ?>"><?php esc_html_e( 'Add product', 'bike-rental-plugin' ); ?></a></p>
	<?php endif; ?>
	<p><?php esc_html_e( 'Prices are regular prices as entered; this overview does not calculate taxes. Display order uses WooCommerce Advanced > Menu order. Inactive packages remain listed here for editing. No rental booking is available yet.', 'bike-rental-plugin' ); ?></p>
	<table class="widefat striped">
		<thead><tr>
			<?php foreach ( array( __( 'Package Name', 'bike-rental-plugin' ), __( 'Regular Price', 'bike-rental-plugin' ), __( 'Duration', 'bike-rental-plugin' ), __( 'Promotional Label', 'bike-rental-plugin' ), __( 'Rental Active', 'bike-rental-plugin' ), __( 'Product Status', 'bike-rental-plugin' ), __( 'Display Order', 'bike-rental-plugin' ), __( 'Edit', 'bike-rental-plugin' ) ) as $heading ) : ?>
				<th scope="col"><?php echo esc_html( $heading ); ?></th>
			<?php endforeach; ?>
		</tr></thead>
		<tbody>
		<?php $shown = 0; ?>
		<?php foreach ( $results->products as $product ) : ?>
			<?php if ( ! current_user_can( 'edit_post', $product->get_id() ) ) { continue; } ?>
			<?php ++$shown; $package = Packages::get_package( $product ); $price = Packages::get_current_price( $product ); ?>
			<tr>
				<th scope="row"><?php echo esc_html( $product->get_name( 'edit' ) ); ?></th>
				<td><?php echo null === $price ? esc_html__( 'Not set / unavailable', 'bike-rental-plugin' ) : wp_kses_post( wc_price( $price ) ); ?></td>
				<td><?php echo esc_html( $package ? $package['duration_amount'] . ' ' . ( 'hours' === $package['duration_type'] ? __( 'Hours', 'bike-rental-plugin' ) : __( 'Calendar Days', 'bike-rental-plugin' ) ) : __( 'Needs review: use a Simple product with valid rental fields.', 'bike-rental-plugin' ) ); ?></td>
				<td><?php echo esc_html( $package ? $package['promotional_label'] : '' ); ?></td>
				<td><?php echo esc_html( $package && $package['active'] ? __( 'Yes', 'bike-rental-plugin' ) : __( 'No', 'bike-rental-plugin' ) ); ?></td>
				<td><?php echo esc_html( $product->get_status( 'edit' ) ); ?></td>
				<td><?php echo esc_html( $product->get_menu_order( 'edit' ) ); ?></td>
				<td><a href="<?php echo esc_url( get_edit_post_link( $product->get_id(), 'raw' ) ); ?>"><?php esc_html_e( 'Edit product', 'bike-rental-plugin' ); ?></a></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( 0 === $shown ) : ?>
			<tr><td colspan="8"><?php esc_html_e( 'No editable rental packages on this page.', 'bike-rental-plugin' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	<?php if ( $results->max_num_pages > 1 ) : ?>
		<nav aria-label="<?php esc_attr_e( 'Package pages', 'bike-rental-plugin' ); ?>"><p>
		<?php if ( $page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => Packages::PAGE, 'brp_page' => $page - 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Previous', 'bike-rental-plugin' ); ?></a><?php endif; ?>
		<?php if ( $page < $results->max_num_pages ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => Packages::PAGE, 'brp_page' => $page + 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Next', 'bike-rental-plugin' ); ?></a><?php endif; ?>
		</p></nav>
	<?php endif; ?>
</div>
