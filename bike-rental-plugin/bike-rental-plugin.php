<?php
/**
 * Plugin Name: Bike Rental Plugin
 * Description: Rental packages, fleet records, and reservation storage for a reusable WooCommerce bicycle rental extension.
 * Version: 0.6.1
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
require_once __DIR__ . '/src/PaymentMode.php';
require_once __DIR__ . '/src/CheckoutReservation.php';
require_once __DIR__ . '/src/Checkout.php';
require_once __DIR__ . '/src/Payments.php';
require_once __DIR__ . '/src/DataAdmin.php';
require_once __DIR__ . '/src/Plugin.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( HoldCleanup::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );
