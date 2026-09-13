<?php
/** Fleet form/list; included only by the authorized DataAdmin renderer. */
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;
?>
<h2><?php echo esc_html( $block ? __( 'Edit block', 'bike-rental-plugin' ) : __( 'Add block', 'bike-rental-plugin' ) ); ?></h2>
<?php
$this->form( $block ? 'block_update' : 'block_create', $block['id'] ?? 0 );
$this->field( 'quantity', __( 'Quantity unavailable', 'bike-rental-plugin' ), $block['quantity'] ?? 1, 'number' );
$this->field( 'start', __( 'Start (local)', 'bike-rental-plugin' ), RentalTime::display( $block['start_utc'] ?? '', true ), 'datetime-local' );
$this->field( 'end', __( 'End (local; blank means indefinite)', 'bike-rental-plugin' ), RentalTime::display( $block['end_utc'] ?? '', true ), 'datetime-local', false );
$this->field( 'reason', __( 'Reason (maximum 240 UTF-8 bytes)', 'bike-rental-plugin' ), $block['reason'] ?? '' );
?>
<p><label><?php esc_html_e( 'State', 'bike-rental-plugin' ); ?><br><select name="active"><option value="1" <?php selected( $block['active'] ?? 1, 1 ); ?>><?php esc_html_e( 'Active', 'bike-rental-plugin' ); ?></option><option value="0" <?php selected( $block['active'] ?? 1, 0 ); ?>><?php esc_html_e( 'Inactive', 'bike-rental-plugin' ); ?></option></select></label></p>
<?php $this->end_form( __( 'Save block', 'bike-rental-plugin' ) ); ?>
<?php if ( $block ) : ?>
<p><a href="<?php echo esc_url( self::url( self::FLEET ) ); ?>"><?php esc_html_e( 'Add another block', 'bike-rental-plugin' ); ?></a></p>
<?php endif; ?>
<h2><?php esc_html_e( 'Unavailability blocks', 'bike-rental-plugin' ); ?></h2>
<p><?php esc_html_e( 'Quantity-based records for the shared pool. Overlapping block totals will be evaluated in a later milestone.', 'bike-rental-plugin' ); ?></p>
<?php
$listing = Database::listing( 'availability', $_GET['paged'] ?? 1 );
if ( is_wp_error( $listing ) ) { $this->error( $listing ); return; }
?>
<table class="widefat striped"><thead><tr>
<?php foreach ( array( 'ID', 'Quantity', 'Start', 'End', 'Reason', 'State', 'Actions' ) as $label ) : ?><th scope="col"><?php echo esc_html( $label ); ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ( $listing['rows'] as $item ) :
	$now = gmdate( 'Y-m-d H:i:s' );
	$state = ! $item['active'] ? __( 'Disabled', 'bike-rental-plugin' ) : ( $item['end_utc'] && $item['end_utc'] <= $now ? __( 'Past', 'bike-rental-plugin' ) : ( $item['start_utc'] > $now ? __( 'Upcoming', 'bike-rental-plugin' ) : __( 'Current', 'bike-rental-plugin' ) ) );
?>
<tr><td><?php echo esc_html( $item['id'] ); ?></td><td><?php echo esc_html( $item['quantity'] ); ?></td><td><?php echo esc_html( RentalTime::display( $item['start_utc'] ) ); ?></td><td><?php echo esc_html( $item['end_utc'] ? RentalTime::display( $item['end_utc'] ) : __( 'Indefinite', 'bike-rental-plugin' ) ); ?></td><td><?php echo esc_html( $item['reason'] ); ?></td><td><?php echo esc_html( $state ); ?></td><td>
<a href="<?php echo esc_url( self::url( self::FLEET, $item['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'bike-rental-plugin' ); ?></a>
<?php if ( $item['active'] ) { $this->form( 'block_disable', $item['id'] ); $this->end_form( __( 'Disable', 'bike-rental-plugin' ) ); } ?>
</td></tr>
<?php endforeach; ?>
<?php if ( ! $listing['rows'] ) : ?><tr><td colspan="7"><?php esc_html_e( 'No blocks stored.', 'bike-rental-plugin' ); ?></td></tr><?php endif; ?>
</tbody></table>
<?php $this->pagination( $listing, self::FLEET ); ?>
