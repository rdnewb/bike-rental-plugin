<?php
/** Real Woo rendering, stored legacy metadata and email audience separation. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
use BikeRentalPlugin\{CustomerSummary, Checkout, RentalLocation, Settings, WaiverEmail, Database};
$checks = 0;
function summary_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$GLOBALS['checks']; echo "PASS: $label\n"; }
function summary_safe( $text ) { return ! preg_match( '/Rental Start|Rental End|Delivery Time|America\/New_York|8:00 AM|5:00 PM|T08:00|T17:00/', $text ); }
$saved = Settings::get(); $order = null;
add_filter( 'pre_wp_mail', '__return_false' );
try {
 foreach ( array(
  array('2032-10-01T08:00','2032-10-01T12:00','October 1, 2032'),
  array('2032-10-01T08:00','2032-10-03T17:00','October 1, 2032 – October 3, 2032'),
  array('2032-10-31T08:00','2032-11-02T17:00','October 31, 2032 – November 2, 2032'),
  array('2032-12-31T08:00','2033-01-02T17:00','December 31, 2032 – January 2, 2033')
 ) as [$start,$end,$expected] ) { summary_check(CustomerSummary::dates($start,$end)===$expected,'friendly same/multi/month/year range '.$start); }
 summary_check(CustomerSummary::dates('bad','2032-01-01')===''&&CustomerSummary::date('2032-02-30')==='','malformed snapshot dates omitted safely');
 $snapshot=array('name'=>'3 Day Rental','local_start'=>'2032-12-31T08:00','local_end'=>'2033-01-02T17:00','timezone'=>'America/New_York','duration_type'=>'calendar_days','duration_amount'=>3,'payment_mode'=>'full','rental_location'=>brp_test_location()+array('country'=>'US'));
 $row=array('reference'=>'BRP-SUMMARY','quantity'=>2,'timezone'=>'America/New_York','start_utc'=>'2032-12-31 13:00:00','end_utc'=>'2033-01-02 22:00:00','snapshot'=>wp_json_encode($snapshot));
 $expected='December 31, 2032 – January 2, 2033';
 $customer=CustomerSummary::details($row);
 summary_check($customer['Rental Date']===$expected&&summary_safe(wp_json_encode($customer)),'cart details hide precise timing');
 summary_check(str_contains($customer['Drop Off / Pick Up Location'],'123 Example Street'),'cart details use existing formatted location');
 $staff=Checkout::details($row);summary_check(str_contains($staff['Rental Start'],'8:00 AM')&&str_contains($staff['Rental End'],'5:00 PM'),'staff details retain exact times');
 $order=wc_create_order();$order->update_meta_data('_brp_reservation_id',12345);$order->update_meta_data('_brp_snapshot',$snapshot);$order->update_meta_data('_brp_start_utc',$row['start_utc']);$order->update_meta_data('_brp_end_utc',$row['end_utc']);$order->update_meta_data(RentalLocation::META,$snapshot['rental_location']);$order->save();
 $item=new WC_Order_Item_Product();$item->set_name('3 Day Rental');$item->set_quantity(2);foreach($staff as $key=>$value){$item->add_meta_data($key,$value);}$order->add_item($item);$order->save();
 $raw=wp_json_encode($item->get_meta_data());
 set_current_screen('front');wp_set_current_user(0);
 $display=wc_display_item_meta($item,array('echo'=>false));
 summary_check(summary_safe($display)&&str_contains($display,'December 31, 2032'),'thank-you/account formatted item dates replace legacy timing');
 $schema=\Automattic\WooCommerce\StoreApi\StoreApi::container()->get(\Automattic\WooCommerce\StoreApi\SchemaController::class)->get(\Automattic\WooCommerce\StoreApi\Schemas\V1\OrderItemSchema::IDENTIFIER);
 $api=wp_json_encode($schema->get_item_response($item)['item_data']);
 summary_check(summary_safe($api)&&str_contains($api,'December 31, 2032'),'Order Confirmation Block item data contains friendly dates only');
 summary_check($item->get_meta('Rental Start')===$staff['Rental Start']&&wp_json_encode($item->get_meta_data())===$raw,'formatting never mutates stored legacy item data');
 summary_check($order->get_meta('_brp_start_utc')===$row['start_utc']&&$order->get_meta('_brp_end_utc')===$row['end_utc'],'order UTC metadata preserved');
 set_current_screen('shop_order');wp_set_current_user(1);
 summary_check(str_contains(wc_display_item_meta($item,array('echo'=>false)),'8:00 AM'),'Woo order admin retains precise metadata');
 foreach(array(false,true) as $plain){
  foreach(array(false,true) as $admin){
   // Render real Woo email templates while the caller is in admin, as for resends.
   $html=wc_get_template_html(($plain?'emails/plain/':'emails/').'email-order-details.php',array('order'=>$order,'sent_to_admin'=>$admin,'plain_text'=>$plain,'email'=>null));
   summary_check($admin?str_contains($html,'8:00 AM'):(summary_safe($html)&&str_contains($html,'December 31, 2032')),'real '.($plain?'plain':'HTML').' '.($admin?'staff':'customer').' email audience');
   summary_check(str_contains(wc_display_item_meta($item,array('echo'=>false)),'8:00 AM'),'email context restored after rendering');
  }
 }
 // Non-rental metadata must not be touched by the rental filter.
 $retail=new WC_Order_Item_Product();$retail->set_name('Retail');$retail->add_meta_data('Rental Start','Custom retail text');$retail->save();
 set_current_screen('front');summary_check(str_contains(wc_display_item_meta($retail,array('echo'=>false)),'Custom retail text'),'unrelated items unaffected');$retail->delete();
 $settings=$saved;$settings['waivers']['adult_body']='Rental Dates: {rental_dates} | {rental_start} | {rental_end}';update_option(Settings::OPTION,$settings);
 $mail=WaiverEmail::compose(array('row'=>$row,'rider'=>array('legal_name'=>'Example','age'=>25,'guardian_name'=>'','guardian_relationship'=>''),'waiver'=>array('signer_role'=>'self','waiver_version'=>'v1')),'https://example.test/waiver');
 summary_check(str_contains($mail['body'],$expected)&&summary_safe($mail['body']),'waiver email date placeholders never expand to timestamps');
 summary_check(Database::VERSION==='2','schema remains 2');
 echo "$checks customer-summary checks passed. Real Woo templates; no email delivery.\n";
} finally { if($order){$order->delete(true);}update_option(Settings::OPTION,$saved);set_current_screen('front'); }
ob_end_flush();
