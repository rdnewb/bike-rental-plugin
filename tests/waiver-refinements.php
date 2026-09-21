<?php
/** Included by waivers.php inside its disposable fixture. Never run against a live site. */
use BikeRentalPlugin\{Database, Reservations, GuestSession, Checkout, Waivers, WaiverSettings, WaiverEmail, WaiverUI, WPFormsWaiverProvider, ReservationCleanup, DataAdmin, Settings};
if ( ! isset( $checks, $product, $wpf ) ) { throw new RuntimeException( 'Run tests/waivers.php.' ); }
function refine_hold( $riders = null, $qty = 1 ) {
 wc_load_cart(); WC()->cart->empty_cart(); unset( $_COOKIE[GuestSession::cookie_name()] ); GuestSession::start();
 $input = array( 'package_id' => $GLOBALS['product']->get_id(), 'quantity' => $qty, 'date' => $GLOBALS['date'], 'time' => '09:00', 'riders' => $riders );
 return Database::public_booking( static fn() => Reservations::create_booking_hold( $input, bin2hex( random_bytes(16) ), GuestSession::identity()['hash'] ) );
}
function refine_terminal( $status = 'cancelled' ) {
 $r = wok( refine_hold( array( 1 => wadult() ) ), 'cleanup fixture hold' );
 return wok( Reservations::change_status( $r['id'], $status, $r['revision'] ), 'cleanup fixture terminal status' );
}
function refine_delete_post( $r ) { return array( 'operation'=>'reservation_delete', 'id'=>$r['id'], 'revision'=>$r['revision'], 'confirm_delete'=>'yes', '_wpnonce'=>wp_create_nonce('brp_reservation_delete_'.$r['id']) ); }

$before = count($mail);
$reservation_count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Database::table('reservations'));
$deny_insert=static fn($sql)=>str_starts_with($sql,'INSERT INTO `'.Database::table('riders').'`') ? 'SELECT * FROM brp_intentionally_missing_fixture_table' : $sql;
$old_suppress=$wpdb->suppress_errors(true);add_filter('query',$deny_insert);
try { $failed=refine_hold(array(1=>wadult())); } finally { remove_filter('query',$deny_insert);$wpdb->suppress_errors($old_suppress); }
wbad($failed,'rider persistence failure rejects entire hold');
wcheck((int)$wpdb->get_var('SELECT COUNT(*) FROM '.Database::table('reservations'))===$reservation_count,'failed rider persistence leaves no reservation allocation');
foreach ( array(1,3) as $qty ) {
 foreach ( array(null,array(),array(1=>wadult(),2=>wadult(),3=>wadult(),4=>wadult())) as $bad ) { wbad(refine_hold($bad,$qty), "quantity $qty rejects missing/wrong roster"); }
 $rs=brp_test_riders($qty); $rs[1]['rider_type']='minor';
 $r=wok(refine_hold($rs,$qty),"quantity $qty valid hold"); $rows=Waivers::roster($r);
 wcheck(count($rows)===$qty,"quantity $qty roster exists before checkout");
 foreach($rows as $ri) { wcheck((int)$ri['reservation_id']===(int)$r['id'] && $ri['rider_type']==='adult' && !$ri['waiver_id'],'validated adult association and no unpaid request'); }
 $again=Database::public_booking(static fn()=>Reservations::create_booking_hold(array('package_id'=>$GLOBALS['product']->get_id(),'quantity'=>$qty,'date'=>$GLOBALS['date'],'time'=>'09:00','riders'=>$rs),$r['request_key'],GuestSession::identity()['hash']));
 wcheck(!is_wp_error($again) && $again['id']===$r['id'],'identical roster retry reuses hold');
 $rs[1]['legal_name']='Different person';
 wbad(Database::public_booking(static fn()=>Reservations::create_booking_hold(array('package_id'=>$GLOBALS['product']->get_id(),'quantity'=>$qty,'date'=>$GLOBALS['date'],'time'=>'09:00','riders'=>$rs),$r['request_key'],GuestSession::identity()['hash'])),'same key with different roster rejected');
 $wpdb->update(Database::table('riders'),array('email'=>''),array('id'=>$rows[0]['id']));
 wbad(Checkout::transfer($r['request_key']),'invalid persisted roster blocks checkout');
 Reservations::cancel($r['id'],$r['revision']);
}
foreach(array('email','legal_name','age') as $key) { $bad=wadult();unset($bad[$key]);wbad(refine_hold(array(1=>$bad)),"hold rejects adult missing $key"); }
foreach(array('guardian_name','guardian_email','guardian_relationship') as $key) { $bad=wminor();unset($bad[$key]);wbad(refine_hold(array(1=>$bad)),"hold rejects minor missing $key"); }
$minor=wminor();$minor['rider_type']='adult';$r=wok(refine_hold(array(1=>$minor)),'minor without own email accepted');
wcheck(Waivers::roster($r)[0]['rider_type']==='minor' && Waivers::roster($r)[0]['email']==='','server classifies minor and preserves optional email');
Waivers::invite_pending($r);wcheck(count($mail)===$before && !Waivers::roster($r)[0]['waiver_id'],'unpaid holds never create or send invitations');
Reservations::cancel($r['id'],$r['revision']);
$new=wok(refine_hold(array(1=>wadult('New Person'))),'new booking after cancellation');wcheck(Waivers::roster($new)[0]['legal_name']==='New Person','new booking does not inherit old rider');Reservations::cancel($new['id'],$new['revision']);

$current=Settings::get();$current['waivers']['adult_subject']='Adult {rider_name} {unknown}';$current['waivers']['guardian_subject']='Guardian {guardian_name}';
$current['waivers']['adult_body']=$current['waivers']['guardian_body']=implode('|',array_map(static fn($k)=>'{'.$k.'}',WaiverSettings::placeholders()));update_option(Settings::OPTION,$current);
list($paid,$paid_order)=wh(2);$paid_roster=Waivers::roster($paid);$adult_token=wtoken($paid_roster[0]['waiver_id']);$minor_token=wtoken($paid_roster[1]['waiver_id']);
$last=array_slice($mail,-2);wcheck(str_contains($last[0]['subject'],'Adult Adult One {unknown}'),'adult current subject and safe unknown placeholder');wcheck($last[1]['subject']==='Guardian Guardian One','guardian current subject');
foreach(WaiverSettings::placeholders() as $key) { wcheck(!str_contains($last[1]['message'],'{'.$key.'}'),"email resolves $key"); }
wcheck(str_contains($last[1]['message'],get_permalink($signing_page)),'invitation targets selected page');
$current['waivers']['adult_subject']='Changed template';update_option(Settings::OPTION,$current);
$wpdb->update(Database::table('waivers'),array('last_invited_at'=>'2000-01-01 00:00:00'),array('id'=>$paid_roster[0]['waiver_id']));wok(Waivers::invite($paid_roster[0]['waiver_id'],true),'resend after throttle');
wcheck(end($mail)['subject']==='Changed template' && count(Waivers::roster($paid))===2,'resend uses latest template without duplicate riders');$adult_token=wtoken($paid_roster[0]['waiver_id']);
foreach(array(0,999999999) as $id) { $current['waivers']['signing_page']=$id;update_option(Settings::OPTION,$current);wbad(WaiverSettings::ready($current['waivers']),'missing signing page prevents ready'); }
$current['waivers']['signing_page']=$signing_page;update_option(Settings::OPTION,$current);
foreach(array(array('post_status'=>'draft'),array('post_status'=>'publish','post_content'=>'[wpforms id="77"]'),array('post_content'=>'[bike_rental_waiver]','post_password'=>'private')) as $change) {
 wp_update_post(array('ID'=>$signing_page)+$change);wbad(WaiverSettings::ready($current['waivers']),'unpublished/raw provider/password page rejected');
}
wp_update_post(array('ID'=>$signing_page,'post_status'=>'publish','post_password'=>'','post_content'=>'[bike_rental_waiver]'));wok(WaiverSettings::ready($current['waivers']),'generic shortcode page ready');
$render_count=0;
add_shortcode('wpforms', static function() {
 ++$GLOBALS['render_count'];$f=$GLOBALS['wpf']->form->value;$html='';
 foreach($f['fields'] as $field) { $p=apply_filters('wpforms_field_properties',array('inputs'=>array('primary'=>array('attr'=>array('value'=>'')))),$field,$f);$html.='<input name="wpforms[fields]['.$field['id'].']" value="'.esc_attr($p['inputs']['primary']['attr']['value']).'">'; }
 ob_start();do_action('wpforms_display_submit_before',$f);return $html.ob_get_clean();
});
WaiverUI::$signed=false;$_GET=array('brp_waiver'=>'bad');$html=WaiverUI::shortcode();wcheck(str_contains($html,'unavailable') && $render_count===0,'invalid token never renders provider');
foreach(array($adult_token,$minor_token) as $index=>$token) {
 $ctx=Waivers::context($token);WaiverUI::$signed=false;$_GET=array('brp_waiver'=>$token);$html=WaiverUI::shortcode();
 wcheck(str_contains($html,esc_html($ctx['waiver']['waiver_text'])) && str_contains($html,esc_html($ctx['waiver']['waiver_version'])),'shortcode renders frozen legal text/version');
 foreach(WPFormsWaiverProvider::expected($ctx) as $key=>$expected) { wcheck(str_contains($html,'name="wpforms[fields]['.$config['mapping'][$key].']" value="'.esc_attr($expected).'"'),"signer $index rendered $key"); }
 wcheck(WPFormsWaiverProvider::verify_rendered($html,$ctx,$token,$config),'all actual rendered inputs verified');
 wcheck(!WPFormsWaiverProvider::verify_rendered(str_replace('value="'.$ctx['rider']['age'].'"','value="999"',$html),$ctx,$token,$config),'incompatible provider population fails closed');
 $f=wentry($token,800+$index);$_POST=array('page_url'=>'https://example.test/waiver/?brp_waiver='.$token,'url_referer'=>'https://example.test/?brp_waiver='.$token);
 foreach(array('signer_name','signer_email','reservation','guardian_name') as $key) { $bad=$f;$bad[$config['mapping'][$key]]['value']='tampered';$wpf->process->errors=array();WPFormsWaiverProvider::process($bad,array('brp_waiver_token'=>$token),$form);wcheck(!empty($wpf->process->errors[77]),"signer $index tampered $key rejected"); }
 foreach(array('signature','consent') as $key) { $bad=$f;unset($bad[$config['mapping'][$key]]);$wpf->process->errors=array();WPFormsWaiverProvider::process($bad,array('brp_waiver_token'=>$token),$form);wcheck(!empty($wpf->process->errors[77]),"signer $index needs $key"); }
 $_POST['wpforms']=array('brp_waiver_token'=>$token);
 $raw=apply_filters('wpforms_process_before_filter',array('brp_waiver_token'=>$token,'fields'=>array()),$form);
 wcheck(!isset($raw['brp_waiver_token']) && !isset($_POST['wpforms']['brp_waiver_token']),'bearer removed from raw provider entry and POST after in-memory capture');
 $wpf->process->errors=array();WPFormsWaiverProvider::process($f,$raw,$form);
 wcheck(!str_contains(wp_json_encode($_POST),$token),'bearer removed from stored source URLs');
 WPFormsWaiverProvider::completed($f,array(),$form,800+$index);
 wcheck(Waivers::progress(wr($paid['id']))['done']===$index+1,'completion rereads entry and updates intended rider');
 $stable=wr($paid['id']);WPFormsWaiverProvider::completed($f,array(),$form,800+$index);wcheck(wr($paid['id'])===$stable,'completion replay leaves reservation unchanged');
}
wcheck(wr($paid['id'])['status']==='confirmed','adult plus minor completion confirms');$_GET=$_POST=array();WaiverUI::$signed=false;

$admin=new DataAdmin();
foreach(array('cancelled','expired') as $status) {
 $r=refine_terminal($status);$post=refine_delete_post($r);$roster=Waivers::roster($r);$template=Database::read('waivers',$paid_roster[0]['waiver_id']);
 unset($template['id']);$template=array_replace($template,array('reservation_id'=>$r['id'],'rider_id'=>$roster[0]['id'],'status'=>'invitation_pending','completed_at'=>null,'provider_submission_id'=>null,'token_hash'=>null,'override_user_id'=>0,'override_reason'=>''));
 wok(Database::locked(static fn()=>Database::insert('waivers',$template)),'incomplete cleanup fixture request');
 $deny_delete=static fn($sql)=>str_starts_with($sql,'DELETE FROM `'.Database::table('riders').'`') ? 'SELECT * FROM brp_intentionally_missing_fixture_table' : $sql;
 $old_suppress=$wpdb->suppress_errors(true);add_filter('query',$deny_delete);
 try { $failed=$admin->dispatch($post); } finally { remove_filter('query',$deny_delete);$wpdb->suppress_errors($old_suppress); }
 wbad($failed,'associated-row delete failure rejects permanent deletion');
 wcheck(!is_wp_error(Reservations::read($r['id'])) && count(Waivers::roster($r))===1 && Waivers::roster($r)[0]['waiver_id'],'failed deletion rolls back reservation rider and incomplete waiver together');
 $capacity=\BikeRentalPlugin\Fleet::capacity();wok($admin->dispatch($post),"$status eligible deletion");wbad(Reservations::read($r['id']),'deleted reservation unavailable');
 foreach(array('riders','waivers') as $table) { wcheck((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE reservation_id=%d',Database::table($table),$r['id']))===0,"no orphan $table"); }
 wcheck(\BikeRentalPlugin\Fleet::capacity()===$capacity,'permanent deletion leaves fleet capacity unchanged');wbad($admin->dispatch($post),'double delete safely rejected');
}
foreach(array('hold','pending_waivers','confirmed','active','completed') as $status) {
 $r=refine_terminal();$wpdb->update(Database::table('reservations'),array('status'=>$status),array('id'=>$r['id']));wbad($admin->dispatch(refine_delete_post($r)),"$status deletion blocked");$wpdb->update(Database::table('reservations'),array('status'=>'cancelled'),array('id'=>$r['id']));
}
$r=refine_terminal();$post=refine_delete_post($r);wp_set_current_user(0);wbad($admin->dispatch($post),'delete capability enforced');wp_set_current_user(1);
foreach(array(array('_wpnonce'=>'bad'),array('confirm_delete'=>'no'),array('revision'=>999)) as $bad) { wbad($admin->dispatch(array_replace($post,$bad)),'nonce confirmation revision enforced'); }
$wpdb->update(Database::table('reservations'),array('order_id'=>$paid_order->get_id()),array('id'=>$r['id']));$bad=$admin->dispatch($post);wcheck(is_wp_error($bad) && $bad->get_error_message()===ReservationCleanup::PAYMENT,'linked Woo order protected with exact reason');$wpdb->update(Database::table('reservations'),array('order_id'=>null),array('id'=>$r['id']));
$reverse=wc_create_order();$orders[]=$reverse->get_id();$reverse->update_meta_data('_brp_reservation_id',$r['id']);$reverse->save();wbad($admin->dispatch($post),'reverse order relationship protects evidence');$reverse->delete(true);
foreach(array('completed','exempt') as $status) {
 $r=refine_terminal();$ri=Waivers::roster($r)[0];$template=array_replace($template,array('reservation_id'=>$r['id'],'rider_id'=>$ri['id'],'status'=>$status));
 wok(Database::locked(static fn()=>Database::insert('waivers',$template)),'protected waiver fixture');wbad($admin->dispatch(refine_delete_post($r)),"$status evidence blocks deletion");
}
$old=refine_terminal();$young=refine_terminal();$wpdb->update(Database::table('reservations'),array('updated_at'=>'2000-01-01 00:00:00'),array('id'=>$old['id']));
wok(ReservationCleanup::retention(),'abandoned PII retention cleanup');wcheck(count(Waivers::roster($old))===0 && !is_wp_error(Reservations::read($old['id'])),'old unpaid unlinked PII removed with audit reservation retained');wcheck(count(Waivers::roster($young))===1,'recent abandoned riders retained temporarily');wcheck(count(Waivers::roster(wr($paid['id'])))===2,'paid signed evidence retained');
