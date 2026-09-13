<?php
/** Native admin-only test tools; no public routes or booking workflow. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

final class DataAdmin {
	const FLEET = 'brp-fleet';
	const RESERVATIONS = 'brp-reservations';
	const AVAILABILITY = 'brp-availability';

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menus' ) );
		add_action( 'admin_post_brp_data_save', array( $this, 'handle_post' ) );
	}
	public function menus() {
		add_submenu_page( Settings::PAGE, __( 'Fleet', 'bike-rental-plugin' ), __( 'Fleet', 'bike-rental-plugin' ), Settings::capability(), self::FLEET, array( $this, 'fleet' ) );
		add_submenu_page( Settings::PAGE, __( 'Reservations', 'bike-rental-plugin' ), __( 'Reservations', 'bike-rental-plugin' ), Settings::capability(), self::RESERVATIONS, array( $this, 'reservations' ) );
		add_submenu_page( Settings::PAGE, __( 'Availability test', 'bike-rental-plugin' ), __( 'Availability test', 'bike-rental-plugin' ), Settings::capability(), self::AVAILABILITY, array( $this, 'availability' ) );
	}

	/** Independently testable mutation boundary. All IDs remain untrusted here. */
	public function dispatch( $post ) {
		if ( ! Settings::can_manage() ) { return Database::error( 'permission', 'You do not have permission to manage rental data.' ); }
		if ( ! is_array( $post ) ) { return Database::error( 'input', 'Invalid form submission.' ); }
		$operation = $post['operation'] ?? null;
		if ( ! in_array( $operation, array( 'capacity', 'block_create', 'block_update', 'block_disable', 'reservation_create', 'reservation_update', 'reservation_status', 'reservation_confirm_hold', 'availability_test', 'hold_cleanup' ), true ) ) { return Database::error( 'operation', 'Unknown rental operation.' ); }
		$id = $post['id'] ?? '0';
		$existing = in_array( $operation, array( 'block_update', 'block_disable', 'reservation_update', 'reservation_status', 'reservation_confirm_hold' ), true );
		if ( $existing ? ! Database::positive( $id ) : ! in_array( $id, array( 0, '0' ), true ) ) { return Database::error( 'id', 'Invalid record ID.' ); }
		$nonce = $post['_wpnonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'brp_' . $operation . '_' . $id ) ) { return Database::error( 'nonce', 'This form expired or failed verification. Reload the page and try again.' ); }
		if ( in_array( $operation, array( 'block_create', 'block_update', 'reservation_create', 'reservation_update', 'availability_test' ), true ) && ( $post['timezone'] ?? null ) !== wp_timezone_string() ) { return Database::error( 'timezone', 'The WordPress timezone changed while this form was open. Reload before entering local times.' ); }
		switch ( $operation ) {
			case 'capacity': return Fleet::set_capacity( $post['quantity'] ?? null );
			case 'block_create': return Fleet::save_block( $post );
			case 'block_update': return Fleet::save_block( $post, $id );
			case 'block_disable': return Fleet::disable_block( $id );
			case 'reservation_create': return Reservations::create( $post );
			case 'reservation_update':
				foreach ( array( 'package_product_id', 'quantity', 'start', 'end', 'status', 'issue_code', 'revision' ) as $field ) {
					if ( ! array_key_exists( $field, $post ) ) { return Database::error( 'input', 'The edit form is incomplete. Reload the reservation and submit all editable fields.' ); }
				}
				return Reservations::update( $id, $post, $post['revision'] );
			case 'reservation_status': return Reservations::change_status( $id, $post['status'] ?? null, $post['revision'] ?? null );
			case 'reservation_confirm_hold': return Reservations::confirm_hold( $id, $post['revision'] ?? null );
			case 'hold_cleanup': return Reservations::expire_holds();
			case 'availability_test':
				$interval = RentalTime::interval( $post['start'] ?? null, $post['end'] ?? null );
				return is_wp_error( $interval ) ? $interval : Availability::check( $interval['start_utc'], $interval['end_utc'], $post['quantity'] ?? null );
		}
	}

	public function handle_post() {
		$post = wp_unslash( $_POST );
		$result = $this->dispatch( $post );
		if ( ! Settings::can_manage() ) { wp_die( esc_html__( 'You do not have permission to manage rental data.', 'bike-rental-plugin' ), '', array( 'response' => 403 ) ); }
		$operation = is_string( $post['operation'] ?? null ) ? $post['operation'] : '';
		$page = str_starts_with( $operation, 'reservation_' ) ? self::RESERVATIONS : self::FLEET;
		if ( in_array( $operation, array( 'availability_test', 'hold_cleanup' ), true ) ) { $page = self::AVAILABILITY; }
		$id = is_array( $result ) ? ( $result['id'] ?? 0 ) : ( Database::positive( $post['id'] ?? null ) ? $post['id'] : 0 );
		$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Rental data saved.', 'bike-rental-plugin' );
		if ( ! is_wp_error( $result ) && 'availability_test' === $operation ) { $message = sprintf( 'Fleet: %d. Peak used: %d. Available: %d. Requested: %d. %s', $result['total_capacity'], $result['peak_existing_usage'], $result['available_quantity'], $result['requested_quantity'], $result['fits'] ? 'Fits.' : 'Does not fit.' ); }
		if ( ! is_wp_error( $result ) && 'hold_cleanup' === $operation ) { $message = sprintf( 'Expired %d holds. Capacity ignores expired timestamps even before cleanup.', $result ); }
		set_transient( 'brp_data_notice_' . get_current_user_id(), array( 'error' => is_wp_error( $result ) || ( 'availability_test' === $operation && ! is_wp_error( $result ) && ! $result['fits'] ), 'message' => $message ), 60 );
		wp_safe_redirect( self::url( $page, $id ) );
		exit;
	}

	public static function url( $page, $id = 0, $paged = 1 ) {
		return add_query_arg( array( 'page' => $page, 'id' => $id, 'paged' => $paged ), admin_url( 'admin.php' ) );
	}

	private function begin( $title ) {
		if ( ! Settings::can_manage() ) { wp_die( esc_html__( 'You do not have permission to manage rental data.', 'bike-rental-plugin' ) ); }
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
		$notice = get_transient( 'brp_data_notice_' . get_current_user_id() );
		if ( is_array( $notice ) && isset( $notice['message'] ) ) {
			echo '<div class="notice ' . ( $notice['error'] ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
			delete_transient( 'brp_data_notice_' . get_current_user_id() );
		}
		$gate = Database::gate();
		if ( is_wp_error( $gate ) ) { $this->error( $gate ); echo '</div>'; return false; }
		echo '<p>' . esc_html__( 'Milestone 4 administration tools. Shared fleet availability is enforced when saving. Payment collection and fulfillment readiness are not implemented.', 'bike-rental-plugin' ) . '</p>';
		echo '<p>' . esc_html__( 'Input and display timezone:', 'bike-rental-plugin' ) . ' <strong>' . esc_html( wp_timezone_string() ) . '</strong>. ' . esc_html__( 'Use unambiguous local times; clock gaps and repeated daylight-saving times are rejected.', 'bike-rental-plugin' ) . '</p>';
		return true;
	}

	private function error( $error ) { echo '<div class="notice notice-error"><p>' . esc_html( $error->get_error_message() ) . '</p></div>'; }
	private function form( $operation, $id = 0, $revision = null ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		foreach ( array( 'action' => 'brp_data_save', 'operation' => $operation, 'id' => $id, 'timezone' => wp_timezone_string() ) as $name => $value ) { $this->hidden( $name, $value ); }
		if ( null !== $revision ) { $this->hidden( 'revision', $revision ); }
		if ( 'reservation_create' === $operation ) { $this->hidden( 'request_key', wp_generate_uuid4() ); }
		wp_nonce_field( 'brp_' . $operation . '_' . $id );
	}
	private function hidden( $name, $value ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">'; }
	private function field( $name, $label, $value = '', $type = 'text', $required = true ) {
		echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . ( 'number' === $type ? ' min="1" max="2147483647" step="1"' : '' ) . ( 'datetime-local' === $type ? ' step="60"' : '' ) . ( 'reason' === $name ? ' maxlength="240"' : '' ) . '></label></p>';
	}
	private function statuses( $value, $allowed ) {
		echo '<p><label>' . esc_html__( 'Status', 'bike-rental-plugin' ) . '<br><select name="status">';
		foreach ( $allowed as $status ) { echo '<option value="' . esc_attr( $status ) . '"'; selected( $value, $status ); echo '>' . esc_html( $status ) . '</option>'; }
		echo '</select></label></p>';
	}
	private function end_form( $label ) { submit_button( $label ); echo '</form>'; }
	private function pagination( $listing, $page ) {
		echo '<p>' . esc_html( sprintf( __( '%1$d records · Page %2$d', 'bike-rental-plugin' ), $listing['total'], $listing['page'] ) ) . ' ';
		if ( $listing['page'] > 1 ) { echo '<a href="' . esc_url( self::url( $page, 0, $listing['page'] - 1 ) ) . '">' . esc_html__( 'Previous', 'bike-rental-plugin' ) . '</a> '; }
		if ( $listing['page'] * 25 < $listing['total'] ) { echo '<a href="' . esc_url( self::url( $page, 0, $listing['page'] + 1 ) ) . '">' . esc_html__( 'Next', 'bike-rental-plugin' ) . '</a>'; }
		echo '</p>';
	}

	public function fleet() {
		if ( ! $this->begin( __( 'Fleet', 'bike-rental-plugin' ) ) ) { return; }
		$capacity = Fleet::capacity();
		if ( is_wp_error( $capacity ) ) { $this->error( $capacity ); echo '</div>'; return; }
		$this->form( 'capacity' );
		$this->field( 'quantity', __( 'Total rentable bikes', 'bike-rental-plugin' ), $capacity, 'number' );
		$this->end_form( __( 'Save fleet quantity', 'bike-rental-plugin' ) );
		$id = $_GET['id'] ?? '0';
		$block = in_array( $id, array( 0, '0' ), true ) ? null : Fleet::block( $id );
		if ( is_wp_error( $block ) ) { $this->error( $block ); $block = null; }
		require __DIR__ . '/fleet-page.php';
		echo '</div>';
	}

	public function reservations() {
		if ( ! $this->begin( __( 'Reservations', 'bike-rental-plugin' ) ) ) { return; }
		$id = $_GET['id'] ?? '0';
		$row = in_array( $id, array( 0, '0' ), true ) ? null : Reservations::read( $id );
		if ( is_wp_error( $row ) ) { $this->error( $row ); $row = null; }
		require __DIR__ . '/reservations-page.php';
		echo '</div>';
	}

	public function availability() {
		if ( ! $this->begin( __( 'Availability test', 'bike-rental-plugin' ) ) ) { return; }
		echo '<p>' . esc_html__( 'Enter the occupied interval, including preparation and turnaround. This result is a point-in-time check; saving a reservation rechecks under the inventory lock. Active rentals remain allocated until completed. Completed returns with turnaround create a temporary block.', 'bike-rental-plugin' ) . '</p>';
		$this->form( 'availability_test' );
		$this->field( 'start', __( 'Occupied start (local)', 'bike-rental-plugin' ), '', 'datetime-local' );
		$this->field( 'end', __( 'Occupied end (local)', 'bike-rental-plugin' ), '', 'datetime-local' );
		$this->field( 'quantity', __( 'Quantity', 'bike-rental-plugin' ), 1, 'number' );
		$this->end_form( __( 'Check availability', 'bike-rental-plugin' ) );
		$this->form( 'hold_cleanup' );
		$this->end_form( __( 'Run expired-hold cleanup', 'bike-rental-plugin' ) );
		echo '</div>';
	}
}
