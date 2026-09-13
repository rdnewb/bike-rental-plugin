<?php
/**
 * Rental metadata on ordinary WooCommerce simple products.
 *
 * @package BikeRentalPlugin
 */

namespace BikeRentalPlugin;

defined( 'ABSPATH' ) || exit;

final class Packages {

	const ENABLED  = '_brp_rental_package';
	const TYPE     = '_brp_duration_type';
	const AMOUNT   = '_brp_duration_amount';
	const PROMO    = '_brp_promotional_label';
	const ACTIVE   = '_brp_rental_active';
	const PAGE     = 'brp-packages';
	const MAX_DAYS = 365;
	const MAX_HOURS = 8760;

	public function register_hooks() {
		// WooCommerce's documented custom product-query parameter extension point.
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( $this, 'filter_query' ), 10, 2 );
		if ( is_admin() ) {
			add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
			add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
			add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
			add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		}
	}

	/** Only positive IDs or existing product objects are accepted. */
	private static function product( $product_or_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		if ( $product_or_id instanceof \WC_Product ) {
			return $product_or_id->get_id() > 0 ? $product_or_id : null;
		}
		if ( ! is_int( $product_or_id ) && ! is_string( $product_or_id ) ) {
			return null;
		}
		$id = filter_var( $product_or_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $id ) {
			return null;
		}
		$product = wc_get_product( $id );
		return $product instanceof \WC_Product ? $product : null;
	}

	public static function is_rental_package( $product_or_id ) {
		$product = self::product( $product_or_id );
		return $product && $product->is_type( 'simple' ) && 'yes' === $product->get_meta( self::ENABLED, true, 'edit' );
	}

	/** Current regular price, never sale/promotional pricing or a floating-point amount. */
	public static function get_current_price( $product_or_id ) {
		$product = self::product( $product_or_id );
		if ( ! self::is_rental_package( $product ) ) {
			return null;
		}
		$price = $product->get_regular_price( 'edit' );
		if ( ! is_string( $price ) || ! preg_match( '/\A[0-9]+(?:\.[0-9]+)?\z/', $price ) ) {
			return null;
		}
		return $price;
	}

	/**
	 * Read current configuration. Call after woocommerce_init. No persistence/cache.
	 * Returns null for non-rentals or malformed rental metadata. Null price means unset.
	 * Reservations copy these values; catalog changes alone never rewrite saved terms.
	 */
	public static function get_package( $product_or_id ) {
		$product = self::product( $product_or_id );
		if ( ! self::is_rental_package( $product ) ) {
			return null;
		}
		$result = self::validate( array(
			'enabled' => 'yes',
			'duration_type' => $product->get_meta( self::TYPE, true, 'edit' ),
			'duration_amount' => $product->get_meta( self::AMOUNT, true, 'edit' ),
			'promotional_label' => $product->get_meta( self::PROMO, true, 'edit' ),
			'active' => $product->get_meta( self::ACTIVE, true, 'edit' ),
		) );
		if ( $result['errors'] ) {
			return null;
		}
		$data = $result['values'];
		return array(
			'product_id' => $product->get_id(),
			'name' => $product->get_name( 'edit' ),
			'price' => self::get_current_price( $product ),
			'currency' => get_woocommerce_currency(),
			'duration_type' => $data['duration_type'],
			'duration_amount' => $data['duration_amount'],
			'promotional_label' => $data['promotional_label'],
			'active' => 'yes' === $data['active'],
			'product_status' => $product->get_status( 'edit' ),
			'display_order' => $product->get_menu_order( 'edit' ),
			'tax_status' => $product->get_tax_status( 'edit' ),
			'tax_class' => $product->get_tax_class( 'edit' ),
		);
	}

	/** Published, enabled, valid, priced packages; this does NOT check inventory. */
	public static function get_active_packages() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$products = wc_get_products( array(
			'brp_rental_packages' => true,
			'type' => 'simple',
			'status' => 'publish',
			'limit' => -1,
			'orderby' => 'menu_order title ID',
			'order' => 'ASC',
		) );
		$packages = array();
		foreach ( $products as $product ) {
			$package = self::get_package( $product );
			if ( $package && $package['active'] && 'publish' === $package['product_status'] && null !== $package['price'] ) {
				$packages[] = $package;
			}
		}
		return $packages;
	}

	public function filter_query( $query, $query_vars ) {
		if ( true === ( $query_vars['brp_rental_packages'] ?? false ) ) {
			$clause = array( 'key' => self::ENABLED, 'value' => 'yes', 'compare' => '=' );
			$query['meta_query'] = empty( $query['meta_query'] ) ? array( $clause ) : array( 'relation' => 'AND', $query['meta_query'], $clause );
		}
		return $query;
	}

	/** Validate before changing any metadata. Disabled fields can be omitted. */
	public static function validate( $input ) {
		if ( ! is_array( $input ) || ! in_array( $input['enabled'] ?? null, array( 'yes', 'no' ), true ) ) {
			return array( 'values' => array(), 'errors' => array( __( 'Use as Rental Package must be enabled or disabled.', 'bike-rental-plugin' ) ) );
		}
		if ( 'no' === $input['enabled'] ) {
			return array( 'values' => array( 'enabled' => 'no' ), 'errors' => array() );
		}
		$errors = array();
		$type = $input['duration_type'] ?? null;
		$amount = $input['duration_amount'] ?? null;
		$active = $input['active'] ?? null;
		$promo = $input['promotional_label'] ?? '';
		if ( ! in_array( $type, array( 'hours', 'calendar_days' ), true ) ) {
			$errors[] = __( 'Duration type must be Hours or Calendar Days.', 'bike-rental-plugin' );
		}
		$max = 'hours' === $type ? self::MAX_HOURS : self::MAX_DAYS;
		if ( ( ! is_int( $amount ) && ! is_string( $amount ) ) || ! preg_match( '/\A[0-9]+\z/', (string) $amount ) || $amount < 1 || $amount > $max ) {
			/* translators: %d: maximum permitted duration in the selected unit. */
			$errors[] = sprintf( __( 'Duration must be a whole number from 1 to %d for the selected type.', 'bike-rental-plugin' ), $max );
		}
		if ( ! in_array( $active, array( 'yes', 'no' ), true ) ) {
			$errors[] = __( 'Rental Active must be enabled or disabled.', 'bike-rental-plugin' );
		}
		if ( ! is_string( $promo ) ) {
			$errors[] = __( 'Promotional label must be plain text.', 'bike-rental-plugin' );
		} else {
			$promo = sanitize_text_field( $promo );
			$length = preg_match_all( '/./us', $promo );
			if ( false === $length || $length > 120 ) {
				$errors[] = __( 'Promotional label must contain no more than 120 characters.', 'bike-rental-plugin' );
			}
		}
		return array(
			'errors' => $errors,
			'values' => $errors ? array() : array( 'enabled' => 'yes', 'duration_type' => $type, 'duration_amount' => (int) $amount, 'promotional_label' => $promo, 'active' => $active ),
		);
	}

	public function add_tab( $tabs ) {
		if ( current_user_can( 'edit_products' ) ) {
			$tabs['brp_rental'] = array( 'label' => __( 'Rental Settings', 'bike-rental-plugin' ), 'target' => 'brp_rental_data', 'class' => array( 'show_if_simple' ), 'priority' => 80 );
		}
		return $tabs;
	}

	public function render_panel() {
		global $product_object;
		if ( ! $product_object instanceof \WC_Product || ! current_user_can( 'edit_products' ) || ! current_user_can( 'edit_post', $product_object->get_id() ) ) {
			return;
		}
		$product = $product_object;
		require __DIR__ . '/package-fields.php';
	}

	public function enqueue() {
		$screen = get_current_screen();
		if ( $screen && 'product' === $screen->id && current_user_can( 'edit_products' ) ) {
			wp_enqueue_script( 'brp-package-admin', plugins_url( 'assets/js/package-admin.js', dirname( __DIR__ ) . '/bike-rental-plugin.php' ), array( 'jquery' ), Plugin::VERSION, true );
		}
	}

	/**
	 * WooCommerce already checks its save nonce; also require a product-bound nonce.
	 * Only stage metadata on the supplied CRUD object. WooCommerce performs save().
	 */
	public function save_product( $product ) {
		if ( ! isset( $_POST['brp_package'] ) ) {
			return; // Quick edit, bulk edit, autosaves, and other writers are untouched.
		}
		if ( ! $product instanceof \WC_Product || $product->get_id() < 1 || ! current_user_can( 'edit_products' ) || ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = $_POST['brp_package_nonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'brp_save_package_' . $product->get_id() ) ) {
			\WC_Admin_Meta_Boxes::add_error( __( 'Rental settings were not saved because the security token is missing or expired. Reload the product editor and try again.', 'bike-rental-plugin' ) );
			return;
		}
		$input = wp_unslash( $_POST['brp_package'] );
		$result = self::validate( $input );
		if ( ! $product->is_type( 'simple' ) && 'yes' === ( $result['values']['enabled'] ?? null ) ) {
			$result['errors'][] = __( 'Rental packages require a Simple product. Rental metadata was not changed.', 'bike-rental-plugin' );
		}
		if ( $result['errors'] ) {
			foreach ( $result['errors'] as $error ) {
				\WC_Admin_Meta_Boxes::add_error( $error );
			}
			\WC_Admin_Meta_Boxes::add_error( __( 'Previous rental settings were retained. Standard WooCommerce product fields may still have been saved.', 'bike-rental-plugin' ) );
			return;
		}
		$map = array( 'enabled' => self::ENABLED, 'duration_type' => self::TYPE, 'duration_amount' => self::AMOUNT, 'promotional_label' => self::PROMO, 'active' => self::ACTIVE );
		if ( 'no' === $result['values']['enabled'] ) {
			foreach ( $map as $key ) {
				$product->delete_meta_data( $key );
			}
			return;
		}
		foreach ( $map as $field => $key ) {
			$product->update_meta_data( $key, $result['values'][ $field ] );
		}
	}

	public function add_menu() {
		add_submenu_page( Settings::PAGE, __( 'Rental Packages', 'bike-rental-plugin' ), __( 'Packages', 'bike-rental-plugin' ), 'edit_products', self::PAGE, array( $this, 'render_overview' ) );
	}

	public function render_overview() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You do not have permission to view rental packages.', 'bike-rental-plugin' ) );
		}
		// Read-only pagination. No mutation or nonce is needed for this GET parameter.
		$requested = isset( $_GET['brp_page'] ) && is_string( $_GET['brp_page'] ) ? wp_unslash( $_GET['brp_page'] ) : '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = filter_var( $requested, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 100000 ) ) );
		$page = false === $page ? 1 : $page;
		$results = wc_get_products( array(
			'brp_rental_packages' => true,
			'status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'limit' => 25,
			'page' => $page,
			'paginate' => true,
			'orderby' => 'menu_order title ID',
			'order' => 'ASC',
		) );
		require __DIR__ . '/packages-page.php';
	}
}
