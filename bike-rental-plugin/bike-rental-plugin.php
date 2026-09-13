<?php
/**
 * Plugin Name: Bike Rental Plugin
 * Description: Rental packages, fleet records, and reservation storage for a reusable WooCommerce bicycle rental extension.
 * Version: 0.5.0
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Text Domain: bike-rental-plugin
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 *
 * @package BikeRentalPlugin
 */

namespace BikeRentalPlugin;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/RentalTime.php';
require_once __DIR__ . '/src/Availability.php';
require_once __DIR__ . '/src/Fleet.php';
require_once __DIR__ . '/src/Reservations.php';
require_once __DIR__ . '/src/HoldCleanup.php';
require_once __DIR__ . '/src/BookingSchedule.php';
require_once __DIR__ . '/src/GuestSession.php';
require_once __DIR__ . '/src/PublicBooking.php';
require_once __DIR__ . '/src/DataAdmin.php';
require_once __DIR__ . '/src/Plugin.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( HoldCleanup::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
