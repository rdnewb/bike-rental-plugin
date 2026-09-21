<?php
/**
 * Settings and business data are deliberately retained on uninstall.
 * A future explicit cleanup process must be separately authorized.
 *
 * @package BikeRentalPlugin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'brp_license_daily' );

// No deletion of options, package metadata, files, reservations, or availability tables.
