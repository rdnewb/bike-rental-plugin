<?php
/**
 * Settings and business data are deliberately retained on uninstall.
 * A future explicit cleanup process must be separately authorized.
 *
 * @package BikeRentalPlugin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// No deletion of options, files, or future rental data.
