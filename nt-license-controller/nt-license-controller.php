<?php
/**
 * Plugin Name: NT License Controller
 * Description: Independent product license administration and installation validation for NewByte Technologies.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 * Text Domain: nt-license-controller
 */
namespace NTLicenseController;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/Licenses.php';
require_once __DIR__ . '/src/Api.php';
require_once __DIR__ . '/src/Admin.php';
const VERSION = '0.1.0';
register_activation_hook( __FILE__, array( Store::class, 'install' ) );
register_deactivation_hook( __FILE__, static function () { wp_clear_scheduled_hook( 'ntlc_cleanup' ); } );
add_action( 'init', array( Store::class, 'install' ) );
add_action( 'init', static function () { if ( ! wp_next_scheduled( 'ntlc_cleanup' ) ) { wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'ntlc_cleanup' ); } } );
add_action( 'ntlc_cleanup', array( Store::class, 'cleanup' ) );
add_action( 'rest_api_init', array( Api::class, 'routes' ) );
add_action( 'admin_menu', array( Admin::class, 'menu' ) );
add_action( 'admin_post_ntlc_save', array( Admin::class, 'handle' ) );
