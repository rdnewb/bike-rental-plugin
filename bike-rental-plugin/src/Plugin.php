<?php
/**
 * Plugin lifecycle and dependency visibility.
 *
 * @package BikeRentalPlugin
 */

namespace BikeRentalPlugin;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	const VERSION = '0.4.0';

	/** Initialize defaults once; never replace existing configuration. */
	public static function activate() {
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
	}

	/** Product administration is loaded only after WooCommerce initializes. */
	public static function boot() {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;
		add_action( 'init', array( self::class, 'load_textdomain' ) );
		add_action( 'init', array( Database::class, 'install' ), 20 );
		HoldCleanup::register_hooks();
		add_action( 'woocommerce_init', array( self::class, 'load_packages' ) );
		if ( is_admin() ) {
			$settings = new Settings();
			$settings->register_hooks();
			$data_admin = new DataAdmin();
			$data_admin->register_hooks();
			add_action( 'admin_notices', array( Database::class, 'notice' ) );
			add_action( 'admin_init', array( self::class, 'record_version' ) );
			add_action( 'admin_notices', array( self::class, 'packages_dependency_notice' ) );
		}
	}

	public static function load_packages() {
		static $loaded = false;
		if ( $loaded || ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product' ) ) {
			return;
		}
		$loaded = true;
		require_once __DIR__ . '/Packages.php';
		$packages = new Packages();
		$packages->register_hooks();
	}

	public static function packages_dependency_notice() {
		if ( function_exists( 'wc_get_product' ) && class_exists( 'WC_Product' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'toplevel_page_' . Settings::PAGE, 'plugins', 'product', 'edit-product' ), true ) || ! ( Settings::can_manage() || current_user_can( 'edit_products' ) ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Rental package management requires WooCommerce. Install or activate WooCommerce, then open a simple product to configure Rental Settings. Bike Rentals settings remain available.', 'bike-rental-plugin' ) . '</p></div>';
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'bike-rental-plugin', false, dirname( plugin_basename( dirname( __DIR__ ) . '/bike-rental-plugin.php' ) ) . '/languages' );
	}

	/** Code version marker is independent from the verified database schema version. */
	public static function record_version() {
		if ( ! Settings::can_manage() ) {
			return;
		}
		if ( self::VERSION !== get_option( 'brp_plugin_version', '' ) ) {
			update_option( 'brp_plugin_version', self::VERSION, false );
		}
	}

	/**
	 * Read plugin headers, not installation paths. Runtime checks never autoload
	 * third-party code. Active presence is not proof of configuration or compatibility.
	 *
	 * @return array
	 */
	public static function dependencies() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$definitions = array(
			'woocommerce' => array(
				'label'   => 'WooCommerce',
				'domains' => array( 'woocommerce' ),
				'runtime' => class_exists( 'WooCommerce', false ),
			),
			'square' => array(
				'label'   => 'WooCommerce Square',
				'domains' => array( 'woocommerce-square' ),
				'runtime' => class_exists( 'WooCommerce\Square\Plugin', false ) || class_exists( 'WC_Square', false ),
			),
			'wpforms' => array(
				'label'   => 'WPForms',
				'domains' => array( 'wpforms', 'wpforms-lite' ),
				'runtime' => function_exists( 'wpforms' ),
			),
			'signature' => array(
				'label'   => 'WPForms Signature Addon',
				'domains' => array( 'wpforms-signatures', 'wpforms-signature' ),
				'runtime' => false,
			),
			'deposits' => array(
				'label'   => __( 'WooCommerce deposit solution', 'bike-rental-plugin' ),
				'domains' => array( 'woocommerce-deposits' ),
				'runtime' => class_exists( 'WC_Deposits', false ),
			),
		);
		$plugins = get_plugins();
		foreach ( $definitions as $key => &$definition ) {
			$definition['available'] = $definition['runtime'];
			$definition['installed'] = array();
			foreach ( $plugins as $file => $data ) {
				$name   = isset( $data['Name'] ) ? $data['Name'] : '';
				$domain = isset( $data['TextDomain'] ) ? $data['TextDomain'] : '';
				$match  = in_array( $domain, $definition['domains'], true );
				// Some commercial add-ons have incomplete text-domain headers.
				if ( 'signature' === $key && preg_match( '/^WPForms Signature(s| Addon| Add-On)?$/i', $name ) ) {
					$match = true;
				}
				if ( 'deposits' === $key && preg_match( '/deposit/i', $name ) && preg_match( '/woocommerce/i', $name . ' ' . ( $data['Description'] ?? '' ) ) ) {
					$match = true;
				}
				if ( $match ) {
					$active = is_plugin_active( $file ) || is_plugin_active_for_network( $file );
					$definition['available'] = $definition['available'] || $active;
					$definition['installed'][] = array( 'name' => $name, 'active' => $active );
				}
			}
		}
		unset( $definition );
		return $definitions;
	}
}
