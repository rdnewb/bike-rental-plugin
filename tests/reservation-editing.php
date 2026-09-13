<?php
/** Corrective M3 editing integration suite; inherits the disposable-only storage test guard. */
require __DIR__ . '/reservations.php';

use BikeRentalPlugin\Database;
use BikeRentalPlugin\DataAdmin;
use BikeRentalPlugin\Packages;
use BikeRentalPlugin\Reservations;
use BikeRentalPlugin\RentalTime;

$storage_checks = $checks;
wp_set_current_user( 1 );
$admin = new DataAdmin();
// Editing tests isolate field behavior; the M4 engine suite tests positive return buffers.
$edit_settings = \BikeRentalPlugin\Settings::get();
$edit_settings['preparation_buffer'] = 0; $edit_settings['turnaround_buffer'] = 0;
update_option( \BikeRentalPlugin\Settings::OPTION, $edit_settings );
$original = good( Reservations::create( $input ), 'editing fixture created' );
$edit_id = $original['id'];
$table = Database::table( 'reservations' );
// Establish older timestamps without sleeping or changing the application's clock.
$wpdb->update( $table, array( 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-02 00:00:00', 'order_id' => 2147483000, 'order_item_id' => 2147483001, 'request_key' => 'edit-' . wp_generate_uuid4(), 'request_hash' => 'original-request-hash', 'session_hash' => 'original-session-hash' ), array( 'id' => $edit_id ) );
$original = Reservations::read( $edit_id );
$legacy_snapshot = json_decode( $original['snapshot'], true );
unset( $legacy_snapshot['quantity'], $legacy_snapshot['status'] );
$wpdb->update( $table, array( 'snapshot' => wp_json_encode( $legacy_snapshot ) ), array( 'id' => $edit_id ) );
$original = Reservations::read( $edit_id );
$legacy_noop = good( $admin->dispatch( form_data( 'reservation_update', $edit_id, array_merge( $input, array( 'issue_code' => '', 'revision' => 1 ) ) ) ), 'existing 0.3.0 snapshot accepted by full edit form' );
verify( $original === $legacy_noop, 'no-op on legacy snapshot preserves every byte without forced migration' );
$untouched = Reservations::read( $id ); // A prior reservation must not be rewritten.
$replacement = new WC_Product_Simple();
$replacement->set_name( 'Replacement editing package' );
$replacement->set_status( 'publish' );
$replacement->set_regular_price( '120.00' );
$replacement->set_sale_price( '91.37' );
$replacement->update_meta_data( Packages::ENABLED, 'yes' );
$replacement->update_meta_data( Packages::ACTIVE, 'yes' );
$replacement->update_meta_data( Packages::TYPE, 'calendar_days' );
$replacement->update_meta_data( Packages::AMOUNT, 2 );
$replacement->update_meta_data( Packages::PROMO, 'Replacement offer' );
$replacement_id = $replacement->save();
verify( '91.37' === wc_get_product( $replacement_id )->get_price( 'edit' ), 'replacement fixture has real WooCommerce selling price distinct from regular price' );
$changes = array( 'package_product_id' => $replacement_id, 'quantity' => 4, 'start' => '2030-07-20T10:00', 'end' => '2030-07-22T10:00', 'status' => 'confirmed', 'issue_code' => 'Review pickup', 'revision' => 1 );
$post = form_data( 'reservation_update', $edit_id, $changes );

wp_set_current_user( 0 );
bad( $admin->dispatch( $post ), 'unauthorized user cannot submit reservation edit' );
bad( Reservations::update( $edit_id, $changes, 1 ), 'unauthorized direct service edit denied' );
wp_set_current_user( 1 );
bad( $admin->dispatch( array_replace( $post, array( '_wpnonce' => 'invalid' ) ) ), 'edit nonce failure blocks update' );
bad( $admin->dispatch( array_replace( $post, array( '_wpnonce' => wp_create_nonce( 'brp_reservation_update_' . $id ) ) ) ), 'edit nonce from another reservation rejected' );
$incomplete = $post; unset( $incomplete['status'] );
bad( $admin->dispatch( $incomplete ), 'incomplete edit form rejected' );
verify( $original === Reservations::read( $edit_id ), 'failed authorization/nonce/form checks leave entire row unchanged' );

// Submit immutable properties as an attacker would; the service must allowlist its writes.
$forged = array( 'reference' => 'REPLACE-ME', 'created_at' => '1999-01-01 00:00:00', 'updated_at' => '1999-01-01 00:00:00', 'snapshot' => '{"price":"0"}', 'order_id' => 1, 'order_item_id' => 1, 'request_key' => 'forged', 'request_hash' => 'forged', 'session_hash' => 'forged' );
$saved = good( $admin->dispatch( array_merge( $post, $forged ) ), 'authorized administrator saves full reservation edit with real nonce' );
$current = json_decode( $saved['snapshot'], true );
verify( (int) $saved['package_product_id'] === $replacement_id, 'package change persists' );
verify( $current['product_id'] === $replacement_id && 'Replacement editing package' === $current['name'], 'current snapshot identifies new package' );
verify( '91.37' === $current['price'], 'replacement snapshot uses current WooCommerce selling price including active sale' );
verify( 'calendar_days' === $current['duration_type'] && 2 === $current['duration_amount'] && 'Replacement offer' === $current['promotional_label'] && 'USD' === $current['currency'], 'replacement snapshot includes duration, promotion, and currency' );
verify( 4 === (int) $saved['quantity'] && 4 === $current['quantity'], 'quantity persists in row and current snapshot' );
verify( '2030-07-20 14:00:00' === $saved['start_utc'] && '2030-07-22 14:00:00' === $saved['end_utc'], 'edited start/end persist in UTC' );
verify( '2030-07-20T10:00' === RentalTime::display( $saved['start_utc'], true ) && $changes['start'] === $current['local_start'] && $changes['end'] === $current['local_end'] && 'America/New_York' === $current['timezone'], 'edited local schedule and timezone round-trip through snapshot and database' );
verify( 'confirmed' === $saved['status'] && 'confirmed' === $current['status'], 'administrative valid status correction persists in row and snapshot' );
verify( 'Review pickup' === $saved['issue_code'], 'internal issue code persists' );
verify( 2 === (int) $saved['revision'], 'multi-field save increments revision exactly once' );
verify( $original['reference'] === $saved['reference'], 'reference preserved despite forged payload' );
verify( $original['created_at'] === $saved['created_at'], 'created timestamp preserved despite forged payload' );
verify( $original['updated_at'] < $saved['updated_at'] && $saved['updated_at'] <= gmdate( 'Y-m-d H:i:s' ), 'modified timestamp updated to actual UTC save time' );
foreach ( array( 'id', 'order_id', 'order_item_id', 'request_key', 'request_hash', 'session_hash' ) as $field ) { verify( $original[ $field ] === $saved[ $field ], 'protected field unchanged: ' . $field ); }
verify( $untouched === Reservations::read( $id ), 'another prior reservation remains untouched' );
verify( '120.00' === wc_get_product( $replacement_id )->get_regular_price( 'edit' ), 'reservation edit does not change WooCommerce product price' );
bad( $admin->dispatch( $post ), 'stale edit revision rejected' );
$stale = $admin->dispatch( $post );
verify( str_contains( $stale->get_error_message(), 'changed elsewhere' ) && str_contains( $stale->get_error_message(), 'Reload' ), 'stale form explains concurrent edit and reload requirement' );
verify( $saved === Reservations::read( $edit_id ), 'stale form does not overwrite newer state' );

$noop_post = form_data( 'reservation_update', $edit_id, array_replace( $changes, array( 'revision' => 2 ) ) );
$noop = good( $admin->dispatch( $noop_post ), 'unchanged full form accepted' );
verify( $saved === $noop, 'no-op preserves revision, modified timestamp, and snapshot bytes' );
$replacement->set_regular_price( '150.00' ); $replacement->set_sale_price( '99.00' ); $replacement->update_meta_data( Packages::AMOUNT, 3 ); $replacement->save();
$noop = good( $admin->dispatch( $noop_post ), 'no-op remains valid after catalog price/duration change' );
verify( $saved === $noop, 'unchanged selected package does not silently reprice or rewrite its agreed snapshot' );
$quantity_only = good( $admin->dispatch( array_replace( $noop_post, array( 'quantity' => 5 ) ) ), 'quantity-only edit accepted' );
$quantity_snapshot = json_decode( $quantity_only['snapshot'], true );
verify( 5 === $quantity_snapshot['quantity'] && '91.37' === $quantity_snapshot['price'] && 2 === $quantity_snapshot['duration_amount'], 'quantity-only edit refreshes state while retaining agreed package terms' );
$status_only = good( $admin->dispatch( array_replace( $noop_post, array( 'quantity' => 5, 'status' => 'cancelled', 'revision' => 3 ) ) ), 'status-only admin edit accepted' );
verify( 'cancelled' === json_decode( $status_only['snapshot'], true )['status'], 'status-only edit updates current snapshot' );

$valid_post = array_replace( $noop_post, array( 'quantity' => 5, 'status' => 'cancelled', 'revision' => 4 ) );
foreach ( array(
	array( 'quantity' => 0 ), array( 'quantity' => -1 ), array( 'quantity' => 11 ), array( 'quantity' => '1.5' ), array( 'quantity' => array() ),
	array( 'start' => '2030-02-30T10:00' ), array( 'start' => '2030-03-10T02:30' ), array( 'end' => $changes['start'] ), array( 'end' => '2030-07-19T10:00' ), array( 'end' => array() ),
	array( 'package_product_id' => 0 ), array( 'package_product_id' => '1 OR 1=1' ), array( 'package_product_id' => array() ),
	array( 'status' => 'paid' ), array( 'status' => array() ), array( 'status' => null ), array( 'issue_code' => str_repeat( 'x', 65 ) ), array( 'issue_code' => array() ), array( 'issue_code' => null ),
) as $index => $invalid ) { bad( $admin->dispatch( array_replace( $valid_post, $invalid ) ), 'invalid edit rejected atomically: ' . $index ); }
verify( $status_only === Reservations::read( $edit_id ), 'invalid fields cause no partial changes to row or snapshot' );
$ordinary = new WC_Product_Simple(); $ordinary->set_name( 'Ordinary test product' ); $ordinary->set_regular_price( '20' ); $ordinary->set_status( 'publish' ); $ordinary_id = $ordinary->save();
bad( $admin->dispatch( array_replace( $valid_post, array( 'package_product_id' => $ordinary_id ) ) ), 'ordinary product cannot replace rental package' );
$replacement->update_meta_data( Packages::ACTIVE, 'no' ); $replacement->save();
good( $admin->dispatch( $valid_post ), 'existing inactive rental package may retain its agreed terms' );
$replacement->delete_meta_data( Packages::ENABLED ); $replacement->save();
bad( $admin->dispatch( $valid_post ), 'current selected product must still be a rental package' );
$replacement->update_meta_data( Packages::ENABLED, 'yes' ); $replacement->update_meta_data( Packages::ACTIVE, 'yes' ); $replacement->save();

wp_set_current_user( $manager_id );
$manager_saved = good( $admin->dispatch( form_data( 'reservation_update', $edit_id, array_replace( $changes, array( 'quantity' => 5, 'status' => 'cancelled', 'issue_code' => 'Manager correction', 'revision' => 4 ) ) ) ), 'authorized real shop manager saves reservation edit' );
wp_set_current_user( 1 );
foreach ( Reservations::STATUSES as $status ) {
	$status_schedule = in_array( $status, array( 'active', 'completed' ), true ) ? array( 'start' => '2020-07-20T10:00', 'end' => '2020-07-22T10:00' ) : array();
	$manager_saved = good( Reservations::update( $edit_id, array_replace( $changes, $status_schedule, array( 'quantity' => 5, 'status' => $status, 'issue_code' => 'Manager correction' ) ), $manager_saved['revision'] ), 'admin correction supports existing status with valid timing: ' . $status );
}
$cleared = good( Reservations::update( $edit_id, array_replace( $changes, array( 'quantity' => 5, 'status' => 'expired', 'issue_code' => '' ) ), $manager_saved['revision'] ), 'issue code can be cleared on expired reservation' );
verify( null === $cleared['issue_code'], 'empty issue code stored as NULL' );

$_GET = array( 'id' => $edit_id );
ob_start(); $admin->reservations(); $html = ob_get_clean();
foreach ( array( 'package_product_id', 'quantity', 'start', 'end', 'status', 'issue_code', 'revision', '_wpnonce' ) as $field ) { verify( str_contains( $html, 'name="' . $field . '"' ), 'detail renders edit control/security field: ' . $field ); }
verify( str_contains( $html, 'Save reservation' ) && str_contains( $html, 'Read-only reservation details' ) && str_contains( $html, 'Current reservation snapshot (read-only)' ), 'detail clearly separates editable controls and read-only system values for terminal records' );
verify( str_contains( $html, 'Availability conflict checking is enforced under the shared inventory lock.' ), 'visible enforced availability notice rendered' );
foreach ( array( 'reference', 'created_at', 'updated_at', 'snapshot', 'order_id', 'order_item_id', 'request_hash', 'session_hash' ) as $field ) { verify( ! str_contains( $html, 'name="' . $field . '"' ), 'protected value has no editable form control: ' . $field ); }
set_transient( 'brp_data_notice_1', array( 'error' => false, 'message' => 'Rental data saved.' ), 60 );
ob_start(); $admin->reservations(); $html = ob_get_clean();
verify( str_contains( $html, 'notice-success' ) && str_contains( $html, 'Rental data saved.' ), 'success notice rendered after redirect' );
set_transient( 'brp_data_notice_1', array( 'error' => true, 'message' => $stale->get_error_message() ), 60 );
ob_start(); $admin->reservations(); $html = ob_get_clean();
verify( str_contains( $html, 'notice-error' ) && str_contains( $html, 'changed elsewhere' ), 'validation/conflict notice rendered after redirect' );

// True simultaneous allocation/edit races now live in tests/availability-concurrency.php.
verify( '1' === Database::VERSION && '1' === get_option( Database::OPTION ), 'corrective update leaves schema version unchanged' );
update_option( 'brp_m3_verification_count', Database::listing( 'reservations' )['total'] );
echo PHP_EOL . ( $checks - $storage_checks ) . ' editing checks + ' . $storage_checks . ' storage checks = ' . $checks . ' real integration checks passed.' . PHP_EOL;
