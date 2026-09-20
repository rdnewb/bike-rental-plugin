<?php
/** Native link-based settings tabs; one Settings API form for the active panel. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
<h1><?php esc_html_e( 'Bike Rentals Settings', 'bike-rental-plugin' ); ?></h1>
<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Rental settings', 'bike-rental-plugin' ); ?>">
<?php foreach ( Settings::tabs() as $key => $label ) : ?>
<a class="nav-tab<?php if ( $tab === $key ) { echo ' nav-tab-active'; } ?>" href="<?php echo esc_url( Settings::tab_url( $key ) ); ?>"<?php if ( $tab === $key ) { echo ' aria-current="page"'; } ?>><?php echo esc_html( $label ); ?></a>
<?php endforeach; ?>
</nav>
<?php settings_errors(); ?>
<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
<?php settings_fields( Settings::GROUP ); // Core includes the current tab URL as its return referer. ?>
<input type="hidden" name="brp_settings_tab" value="<?php echo esc_attr( $tab ); ?>">
<?php if ( 'branding' === $tab ) { require __DIR__ . '/branding-fields.php'; } else { require __DIR__ . '/settings-general.php'; } ?>
<?php submit_button( __( 'Save settings', 'bike-rental-plugin' ) ); ?>
</form>
</div>
