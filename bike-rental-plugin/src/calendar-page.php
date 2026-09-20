<?php
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;
$days = $data['days']; $status = $data['status'];
$peak = max( array_column( $data['usage'], 'peak_existing_usage' ) );
?>
<h2><?php echo esc_html( $days[0]->format( 'M j, Y' ) . ' – ' . $days[6]->format( 'M j, Y' ) ); ?></h2>
<p>Timezone: <strong><?php echo esc_html( wp_timezone_string() ); ?></strong> · Fleet capacity: <strong><?php echo (int) $data['capacity']; ?></strong> · Peak reserved this week: <strong><?php echo (int) $peak; ?></strong> · Lowest available: <strong><?php echo (int) ( $data['capacity'] - $peak ); ?></strong></p>
<nav class="brp-calendar-nav" aria-label="Calendar weeks">
<?php foreach ( array( 'Previous Week' => $days[0]->modify( '-7 days' )->format( 'Y-m-d' ), 'Today' => '', 'Next Week' => $days[7]->format( 'Y-m-d' ) ) as $label => $date ) : ?>
<a class="button" href="<?php echo esc_url( AdminCalendar::url( $date, $status ) ); ?>"><?php echo esc_html( $label ); ?></a>
<?php endforeach; ?>
</nav>
<form method="get" class="brp-calendar-filter">
<input type="hidden" name="page" value="<?php echo esc_attr( AdminCalendar::PAGE ); ?>">
<input type="hidden" name="week" value="<?php echo esc_attr( $days[0]->format( 'Y-m-d' ) ); ?>">
<label for="brp-calendar-status">Reservation status</label>
<select id="brp-calendar-status" name="status">
<?php foreach ( array_merge( array( 'all' ), Reservations::STATUSES ) as $value ) : ?>
<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $status ); ?>><?php echo esc_html( 'all' === $value ? 'All statuses' : ucfirst( $value ) ); ?></option>
<?php endforeach; ?></select> <button class="button" type="submit">Apply filter</button>
</form>
<p>Bars show occupied time, including buffers. Each row is one reservation or quantity block. Daily totals always include all inventory claims, regardless of the status filter. Negative availability indicates an inventory conflict.</p>
<div class="brp-calendar-scroll" tabindex="0" role="region" aria-label="Weekly rental timeline; scroll horizontally for all seven days">
<div class="brp-calendar-timeline">
<div class="brp-calendar-row brp-calendar-header"><div>Reservation / inventory block</div>
<?php for ( $i = 0; $i < 7; ++$i ) : $usage = $data['usage'][ $i ]; ?>
<div class="brp-calendar-day"><strong><?php echo esc_html( $days[ $i ]->format( 'D M j' ) ); ?></strong><br>Peak reserved: <?php echo (int) $usage['peak_existing_usage']; ?> / <?php echo (int) $data['capacity']; ?><br>Available at peak: <?php echo (int) $usage['available_quantity']; ?></div>
<?php endfor; ?></div>
<?php
$events = array();
foreach ( $data['rows'] as $row ) {
	$snapshot = json_decode( $row['snapshot'], true ); $order = $data['orders'][ $row['order_id'] ] ?? array();
	$state = ucfirst( $row['status'] );
	if ( 'hold' === $row['status'] ) { $state .= $row['hold_expires_at'] > $data['now'] ? ' — expires ' . AdminCalendar::time( $row['hold_expires_at'] ) : ' — expired; no inventory claim'; }
	if ( Availability::FOREVER === $row['effective_end'] ) { $state .= ' — overdue; blocking until returned'; }
	$events[] = array(
		'key' => 'reservation-' . $row['id'], 'style' => $row['status'], 'title' => $row['reference'], 'name' => $snapshot['name'] ?? 'Rental package',
		'waiver' => Waivers::progress( $row ), 'quantity' => $row['quantity'], 'state' => $state, 'customer' => $order['customer'] ?? '', 'order' => $order['number'] ?? '',
		'url' => DataAdmin::url( DataAdmin::RESERVATIONS, $row['id'] ), 'start' => $row['occupied_start_utc'], 'end' => $row['effective_end'],
		'rental' => AdminCalendar::time( $row['start_utc'] ) . ' – ' . AdminCalendar::time( $row['end_utc'] ), 'issue' => $row['issue_code'],
	);
}
foreach ( $data['blocks'] as $block ) {
	$events[] = array( 'key' => 'block-' . $block['id'], 'style' => 'block', 'title' => 'Inventory block', 'name' => $block['reason'], 'quantity' => $block['quantity'], 'state' => 'Quantity blocked', 'customer' => '', 'order' => '', 'url' => DataAdmin::url( DataAdmin::FLEET, $block['id'] ), 'start' => $block['start_utc'], 'end' => $block['end_utc'] ?? Availability::FOREVER, 'rental' => '', 'issue' => '' );
}
foreach ( $events as $event ) :
	$left = AdminCalendar::position( $event['start'], $days ); $right = AdminCalendar::position( $event['end'], $days );
	$interval = AdminCalendar::time( $event['start'] ) . ' – ' . ( Availability::FOREVER === $event['end'] ? 'Until released / returned' : AdminCalendar::time( $event['end'] ) );
	$description = $event['title'] . ', ' . $event['name'] . ', ' . $event['quantity'] . ' bikes, ' . $event['state'] . '. Occupied: ' . $interval;
?>
<div class="brp-calendar-row brp-calendar-event brp-calendar-<?php echo esc_attr( $event['style'] ); ?><?php if ( $event['issue'] ) { echo ' brp-calendar-exception'; } ?>" data-event="<?php echo esc_attr( $event['key'] ); ?>">
<div class="brp-calendar-label">
<a href="<?php echo esc_url( $event['url'] ); ?>"><strong><?php echo esc_html( $event['title'] ); ?></strong></a><br>
<?php echo esc_html( $event['name'] ); ?> · <strong><?php echo (int) $event['quantity']; ?> bikes</strong><br>
<span class="brp-calendar-state"><?php echo esc_html( $event['state'] ); ?></span>
<?php if ( ! empty( $event['waiver']['required'] ) ) : ?><br><span class="<?php echo $event['waiver']['complete'] ? 'brp-calendar-waiver' : 'brp-calendar-warning'; ?>">Waivers: <?php echo esc_html( $event['waiver']['label'] ); ?></span><?php endif; ?>
<?php if ( $event['customer'] ) : ?><br><?php echo esc_html( $event['customer'] ); ?><?php endif; ?>
<?php if ( $event['issue'] ) : ?><br><strong class="brp-calendar-warning">Exception: <?php echo esc_html( $event['issue'] ); ?></strong><?php endif; ?>
<details><summary>Times / details</summary>
<?php if ( $event['rental'] ) : ?><p>Rental: <?php echo esc_html( $event['rental'] ); ?></p><?php endif; ?>
<p>Occupied: <?php echo esc_html( $interval ); ?></p>
<?php if ( $event['order'] ) : ?><p>Order #<?php echo esc_html( $event['order'] ); ?></p><?php endif; ?>
</details>
</div>
<div class="brp-calendar-track">
<p class="brp-calendar-time"><?php echo esc_html( $event['rental'] ?: $interval ); ?></p>
<a class="brp-calendar-bar" style="left:<?php echo esc_attr( round( $left, 5 ) ); ?>%;width:<?php echo esc_attr( round( $right - $left, 5 ) ); ?>%" href="<?php echo esc_url( $event['url'] ); ?>" aria-label="<?php echo esc_attr( $description ); ?>" title="<?php echo esc_attr( $description ); ?>"></a>
</div></div>
<?php endforeach; ?>
<?php if ( ! $events ) : ?><p>No reservations or active inventory blocks intersect this week for the selected filter.</p><?php endif; ?>
</div></div>
<p>Capacity includes unexpired holds, pending-waiver rentals, confirmed rentals, active rentals and active quantity blocks. Overdue active rentals continue indefinitely. Completed, cancelled and expired reservations do not consume inventory; completion turnaround blocks still count. This is current allocation state, not a historical utilization report.</p>
<p class="brp-calendar-legend">Status key: <span class="brp-calendar-hold">Hold</span> <span class="brp-calendar-pending_waivers">Pending Waivers</span> <span class="brp-calendar-confirmed">Confirmed</span> <span class="brp-calendar-active">Active</span> <span class="brp-calendar-completed">Completed</span> <span class="brp-calendar-cancelled">Cancelled</span> <span class="brp-calendar-expired">Expired</span> <span class="brp-calendar-block">Inventory block</span> <span class="brp-calendar-exception">Exception</span></p>
