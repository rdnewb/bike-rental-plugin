<?php
/** Reservation test forms and historical detail; no customer data collection. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

if ( $row ) :
	$snapshot = json_decode( $row['snapshot'], true );
	$details = array(
		'quantity' => __( 'Quantity', 'bike-rental-plugin' ),
		'start_utc' => __( 'Start (local)', 'bike-rental-plugin' ),
		'end_utc' => __( 'End (local)', 'bike-rental-plugin' ),
		'occupied_start_utc' => __( 'Occupied start (local)', 'bike-rental-plugin' ),
		'occupied_end_utc' => __( 'Occupied end (local)', 'bike-rental-plugin' ),
		'timezone' => __( 'Schedule entry timezone', 'bike-rental-plugin' ),
		'status' => __( 'Status', 'bike-rental-plugin' ),
		'revision' => __( 'Revision', 'bike-rental-plugin' ),
		'issue_code' => __( 'Issue code', 'bike-rental-plugin' ),
		'order_item_id' => __( 'Order item ID', 'bike-rental-plugin' ),
		'created_at' => __( 'Created (local)', 'bike-rental-plugin' ),
		'updated_at' => __( 'Updated (local)', 'bike-rental-plugin' ),
	);
?>
<h2><?php echo esc_html( $row['reference'] ); ?></h2>
<table class="widefat striped"><tbody>
<?php foreach ( $details as $key => $label ) : ?>
<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( str_ends_with( $key, '_utc' ) || in_array( $key, array( 'created_at', 'updated_at' ), true ) ? RentalTime::display( $row[ $key ] ) : ( $row[ $key ] ?? '—' ) ); ?></td></tr>
<?php endforeach; ?>
<tr><th scope="row"><?php esc_html_e( 'Related order', 'bike-rental-plugin' ); ?></th><td>
<?php
$order = ! empty( $row['order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( $row['order_id'] ) : false;
if ( $order && ( current_user_can( 'manage_options' ) || current_user_can( 'edit_shop_order', $row['order_id'] ) ) ) {
	echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( $row['order_id'] ) . '</a>';
} else { echo esc_html( $row['order_id'] ?? __( 'None', 'bike-rental-plugin' ) ); }
?>
</td></tr></tbody></table>
<h3><?php esc_html_e( 'Original package snapshot', 'bike-rental-plugin' ); ?></h3>
<p><?php esc_html_e( 'Captured at creation; schedule edits do not rewrite this historical snapshot. Current times appear above in the current WordPress timezone; timezone records the timezone used for the last schedule save.', 'bike-rental-plugin' ); ?></p>
<pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo esc_html( is_array( $snapshot ) ? wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : $row['snapshot'] ); ?></pre>
<?php if ( in_array( $row['status'], array( 'hold', 'confirmed' ), true ) ) : ?>
<h3><?php esc_html_e( 'Edit test reservation', 'bike-rental-plugin' ); ?></h3>
<?php
$this->form( 'reservation_update', $row['id'], $row['revision'] );
$this->field( 'quantity', __( 'Quantity', 'bike-rental-plugin' ), $row['quantity'], 'number' );
$this->field( 'start', __( 'Start (local)', 'bike-rental-plugin' ), RentalTime::display( $row['start_utc'], true ), 'datetime-local' );
$this->field( 'end', __( 'End (local)', 'bike-rental-plugin' ), RentalTime::display( $row['end_utc'], true ), 'datetime-local' );
$this->end_form( __( 'Save schedule and quantity', 'bike-rental-plugin' ) );
endif;
$next = Reservations::TRANSITIONS[ $row['status'] ] ?? array();
if ( $next ) {
	$this->form( 'reservation_status', $row['id'], $row['revision'] );
	$this->statuses( $row['status'], array_merge( array( $row['status'] ), $next ) );
	$this->end_form( __( 'Save status', 'bike-rental-plugin' ) );
}
?>
<p><a href="<?php echo esc_url( self::url( self::RESERVATIONS ) ); ?>"><?php esc_html_e( 'Back to list / create test reservation', 'bike-rental-plugin' ); ?></a></p>
<?php else : ?>
<h2><?php esc_html_e( 'Create manual test reservation', 'bike-rental-plugin' ); ?></h2>
<p><?php esc_html_e( 'Explicit start/end values test storage; package-duration rules are not enforced yet. A hold here is a stored test status with no automatic expiry. This tool does not authorize fulfillment.', 'bike-rental-plugin' ); ?></p>
<?php
$packages = function_exists( 'wc_get_product' ) && class_exists( Packages::class ) ? Packages::get_active_packages() : array();
if ( ! $packages ) : ?>
<p><?php esc_html_e( 'To create test reservations, activate WooCommerce and publish an active rental package with a valid duration and regular price.', 'bike-rental-plugin' ); ?></p>
<?php else :
$this->form( 'reservation_create' );
?>
<p><label><?php esc_html_e( 'Rental package', 'bike-rental-plugin' ); ?><br><select name="package_product_id" required>
<?php foreach ( $packages as $package ) : ?><option value="<?php echo esc_attr( $package['product_id'] ); ?>"><?php echo esc_html( $package['name'] . ' — ' . $package['price'] . ' ' . $package['currency'] ); ?></option><?php endforeach; ?>
</select></label></p>
<?php
$this->field( 'quantity', __( 'Quantity', 'bike-rental-plugin' ), 1, 'number' );
$this->field( 'start', __( 'Start (local)', 'bike-rental-plugin' ), '', 'datetime-local' );
$this->field( 'end', __( 'End (local)', 'bike-rental-plugin' ), '', 'datetime-local' );
$this->statuses( 'hold', Reservations::STATUSES );
$this->end_form( __( 'Create test reservation', 'bike-rental-plugin' ) );
endif;
endif;
$listing = Database::listing( 'reservations', $_GET['paged'] ?? 1 );
if ( is_wp_error( $listing ) ) { $this->error( $listing ); return; }
?>
<h2><?php esc_html_e( 'Stored reservations', 'bike-rental-plugin' ); ?></h2>
<table class="widefat striped"><thead><tr>
<?php foreach ( array( 'Reference', 'Package (snapshot)', 'Quantity', 'Start', 'End', 'Status', 'Order ID', 'Updated', 'Action' ) as $label ) : ?><th scope="col"><?php echo esc_html( $label ); ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ( $listing['rows'] as $item ) : $saved = json_decode( $item['snapshot'], true ); ?>
<tr><td><?php echo esc_html( $item['reference'] ); ?></td><td><?php echo esc_html( $saved['name'] ?? __( 'Snapshot needs review', 'bike-rental-plugin' ) ); ?></td><td><?php echo esc_html( $item['quantity'] ); ?></td><td><?php echo esc_html( RentalTime::display( $item['start_utc'] ) ); ?></td><td><?php echo esc_html( RentalTime::display( $item['end_utc'] ) ); ?></td><td><?php echo esc_html( $item['status'] ); ?></td><td><?php echo esc_html( $item['order_id'] ?? '—' ); ?></td><td><?php echo esc_html( RentalTime::display( $item['updated_at'] ) ); ?></td><td><a href="<?php echo esc_url( self::url( self::RESERVATIONS, $item['id'] ) ); ?>"><?php esc_html_e( 'View / edit', 'bike-rental-plugin' ); ?></a></td></tr>
<?php endforeach; ?>
<?php if ( ! $listing['rows'] ) : ?><tr><td colspan="9"><?php esc_html_e( 'No reservations stored.', 'bike-rental-plugin' ); ?></td></tr><?php endif; ?>
</tbody></table>
<?php $this->pagination( $listing, self::RESERVATIONS ); ?>
