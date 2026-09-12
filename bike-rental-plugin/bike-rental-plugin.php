<?php
/**
 * Plugin Name: Bike Rental Plugin
 * Description: Settings and rental package management for a reusable WooCommerce bicycle rental extension.
 * Version: 0.2.0
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
require_once __DIR__ . '/src/Plugin.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
