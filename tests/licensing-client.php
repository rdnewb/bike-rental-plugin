<?php
/** Two independent WP installations. HTTP transport is bridged to the controller's real REST dispatcher. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
use BikeRentalPlugin\{License, LicenseAdmin, Settings, PublicBooking, Reservations, Database, DataAdmin};
$checks=0;$calls=0;$saved_license=License::state();$saved_home=get_option('home');$saved_install=get_option(License::INSTALLATION);$payloads=array();$saved_settings=Settings::get();$saved_capacity=\BikeRentalPlugin\Fleet::capacity();
function cl_check($ok,$text){if(!$ok){throw new RuntimeException('FAIL: '.$text);}++$GLOBALS['checks'];echo "PASS: $text\n";}
function cl_worker($input){
 $process=proc_open(array(PHP_BINARY,'-c',php_ini_loaded_file(),__DIR__.'/license-controller-worker.php'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
 if(!is_resource($process)){throw new RuntimeException('Worker unavailable.');}fwrite($pipes[0],wp_json_encode($input));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($process);
 $result=json_decode($out,true);if($exit||null===$result){throw new RuntimeException('Controller worker failed (no secrets printed).');}return $result;
}
function cl_fields($plan='lifetime'){return array('product_slug'=>'bike-rental-plugin','customer_name'=>'License buyer','customer_email'=>'license@example.test','notes'=>'Private license note','status'=>'active','plan_type'=>$plan,'activation_limit'=>1,'expires_at'=>$plan==='lifetime'?'':gmdate('Y-m-d H:i:s',time()+30*DAY_IN_SECONDS));}
function cl_admin($action,$key=''){return LicenseAdmin::dispatch(array('operation'=>$action,'license_key'=>$key,'_wpnonce'=>wp_create_nonce('brp_license')));}
$transport=static function($pre,$args,$url){
 if(!str_starts_with($url,'https://newbytechnologies.com/wp-json/nt-license/v1/')){return new WP_Error('external_disabled','No external network in tests.');}
 ++$GLOBALS['calls'];$payload=json_decode($args['body'],true);$GLOBALS['payloads'][]=$payload;
 if(!empty($GLOBALS['license_outage'])){return new WP_Error('http_request_failed','Fixture outage');}
 if(!empty($GLOBALS['license_bad_response'])){return array('response'=>array('code'=>200),'body'=>'<html>Proxy error</html>','headers'=>array());}
 $r=cl_worker(array('operation'=>basename($url),'input'=>$payload));
 return array('response'=>array('code'=>$r['status']),'body'=>wp_json_encode($r['body']),'headers'=>array());
};
$enforce=static fn()=>true;
try{
 update_option('home','https://rental.example.test');delete_option(License::OPTION);delete_option(License::INSTALLATION);cl_worker(array('operation'=>'reset'));
 add_filter('pre_http_request',$transport,10,3);
 cl_check(!License::enforced()&&License::allows_new(),'compatibility mode preserves existing installations');
 $settings=Settings::defaults();$settings['business_name']='Licensing fixture';$settings['preparation_buffer']=0;$settings['turnaround_buffer']=0;update_option(Settings::OPTION,$settings);
 \BikeRentalPlugin\Fleet::set_capacity(10000);
 $product=new WC_Product_Simple();$product->set_name('Licensing existing-rental fixture');$product->set_status('publish');$product->set_regular_price('10');
 foreach(array(\BikeRentalPlugin\Packages::ENABLED=>'yes',\BikeRentalPlugin\Packages::ACTIVE=>'yes',\BikeRentalPlugin\Packages::TYPE=>'hours',\BikeRentalPlugin\Packages::AMOUNT=>4) as $k=>$v){$product->update_meta_data($k,$v);}
 $rental=Reservations::create(array('package_product_id'=>$product->save(),'quantity'=>1,'start'=>'2020-01-01T09:00','end'=>'2020-01-01T13:00','status'=>'active'));
 cl_check(!is_wp_error($rental),'create existing active rental before enforcement');
 License::schedule();cl_check(wp_get_schedule(License::HOOK)==='daily','daily validation scheduled');
 $id=License::installation();cl_check(strlen($id)===64&&License::installation()===$id,'random stable installation ID');
 cl_check(get_option(License::INSTALLATION)!==get_current_user_id(),'installation not based on WordPress ID');
 $created=cl_worker(array('operation'=>'save','input'=>cl_fields()));$key=$created['key'];$license_id=$created['id'];
 cl_check(isset($created['key']),'A: create license in independent controller installation');
 wp_set_current_user(0);cl_check(is_wp_error(cl_admin('activate',$key))&&$calls===0,'unauthorized client admin denied');wp_set_current_user(1);
 cl_check(is_wp_error(LicenseAdmin::dispatch(array('operation'=>'activate','license_key'=>$key,'_wpnonce'=>'bad')))&&$calls===0,'client nonce required');
 $r=cl_admin('activate',$key);cl_check(!is_wp_error($r)&&$r['valid'],'B: activation through real separate controller REST');
 cl_check(cl_worker(array('operation'=>'used','id'=>$license_id))===1,'C: controller tracks activation');
 $s=License::state();cl_check($s['valid']&&License::entitlement()==='valid'&&$s['last_valid_at']>0,'valid activation caches entitlement');
 cl_check(!str_contains(wp_json_encode($s),$key)&&$s['key_cipher']!==$key,'key encrypted in client option');
 cl_check(!in_array($wpdb->get_var($wpdb->prepare('SELECT autoload FROM %i WHERE option_name=%s',$wpdb->options,License::OPTION)),array('yes','on','auto-on','auto'),true),'secret option not autoloaded');
 cl_check(array_keys($payloads[0])===array('license_key','product_slug','installation_id','site_url','plugin_version','wordpress_version','php_version'),'activation sends only documented licensing fields');
 cl_check($payloads[0]['plugin_version']==='0.9.0'&&$payloads[0]['site_url']==='https://rental.example.test','plugin version and normalized site sent');
 $_GET=array('tab'=>'license');ob_start();(new Settings())->render();$html=ob_get_clean();
 cl_check(str_contains($html,'Check License Now')&&str_contains($html,'Deactivate License')&&str_contains($html,'License Status'),'License tab renders actions and status');
 cl_check(!str_contains($html,$key)&&str_contains($html,'value="" maxlength="100"'),'raw stored key absent from HTML');
 cl_check(str_contains($html,'admin-post.php')&&!str_contains($html,'action="'.admin_url('options.php').'"'),'License has independent protected form');
 if($dir=getenv('BRP_LICENSE_FIXTURE_DIR')){file_put_contents($dir.'/license-client.html',$html);}
 $before=$calls;License::entitlement();License::allows_new();PublicBooking::shortcode();PublicBooking::shortcode();cl_check($calls===$before,'frontend requests do not remotely validate');
 cl_check(!is_wp_error(cl_admin('validate')),'D: manual check works');
 $s=License::state();$s['key_cipher']='unreadable-after-salt-change';update_option(License::OPTION,$s,false);
 cl_check(!is_wp_error(cl_admin('activate',$key))&&License::entitlement()==='valid','re-enter original key recovers encrypted storage without losing activation');
 $input=cl_fields();$input['status']='suspended';cl_worker(array('operation'=>'save','input'=>$input,'id'=>$license_id));$r=cl_admin('validate');
 cl_check($r['code']==='license_suspended'&&!License::state()['valid'],'E-F: controller suspension immediately invalidates cache');
 add_filter('brp_license_enforcement_enabled',$enforce);cl_check(!License::allows_new(),'invalid enforced license blocks new bookings');
 cl_check(PublicBooking::shortcode()==='<p>'.License::PUBLIC_MESSAGE.'</p>'&&!str_contains(strtolower(PublicBooking::shortcode()),'license'),'public message contains no licensing details');
 foreach(array('packages','times','availability') as $route){$response=PublicBooking::handle(new WP_REST_Request('GET','/bike-rental/v1/'.$route));$json=$response->get_data();cl_check($response->get_status()===503&&str_contains(wp_json_encode($json),'Online booking is temporarily unavailable')&&!str_contains(strtolower(wp_json_encode($json)),'license'),'enforced public REST guard: '.$route);}
 $finished=Reservations::mark_completed($rental['id'],$rental['revision']);cl_check(!is_wp_error($finished)&&$finished['status']==='completed','existing active rental can complete under invalid enforced license');
 $blocked=Reservations::create(array('status'=>'confirmed'));cl_check(is_wp_error($blocked)&&$blocked->get_error_code()==='brp_booking_disabled','service guard blocks new admin reservations too');
 $GLOBALS['license_outage']=true;cl_admin('validate');cl_check(!License::allows_new(),'definite suspension does not acquire grace on later outage');$GLOBALS['license_outage']=false;
 $input['status']='active';cl_worker(array('operation'=>'save','input'=>$input,'id'=>$license_id));cl_admin('validate');cl_check(License::allows_new(),'G: reactivation restores entitlement');
 $before=License::state()['grace_until'];$GLOBALS['license_outage']=true;$r=cl_admin('validate');cl_check(is_wp_error($r)&&License::entitlement()==='grace'&&License::allows_new(),'L-M: outage keeps previously valid operations during grace');
 cl_admin('validate');cl_check(License::state()['grace_until']===$before,'outages never slide or renew grace deadline');
 $s=License::state();$s['grace_until']=time()-1;update_option(License::OPTION,$s,false);cl_check(!License::allows_new(),'grace exhaustion blocks new booking');
 $GLOBALS['license_outage']=false;cl_admin('validate');$GLOBALS['license_bad_response']=true;cl_check(is_wp_error(cl_admin('validate'))&&License::entitlement()==='grace','malformed response treated as outage, not activation');$GLOBALS['license_bad_response']=false;
 $input['status']='revoked';cl_worker(array('operation'=>'save','input'=>$input,'id'=>$license_id));cl_admin('validate');cl_check(!License::allows_new()&&License::state()['grace_until']===0,'definite revocation bypasses grace');
 // Existing rental service behavior must remain usable even when enforcement rejects creation.
 $existing=$wpdb->get_row('SELECT * FROM '.Database::table('reservations').' ORDER BY id DESC LIMIT 1',ARRAY_A);
 cl_check(Settings::can_manage(),'existing rental administration capability retained');
 if($existing){cl_check(!is_wp_error(Reservations::read($existing['id'])),'existing reservation remains readable');}
 ob_start();(new DataAdmin())->reservations();$admin=ob_get_clean();cl_check(str_contains($admin,'Reservations'),'reservation administration remains accessible');
 $r=cl_admin('deactivate');cl_check($r['code']==='installation_deactivated'&&!License::state()['activated'],'J: deactivation works even for revoked license');
 cl_check(cl_worker(array('operation'=>'used','id'=>$license_id))===0,'J-K: remote activation slot released');
 $input['status']='active';cl_worker(array('operation'=>'save','input'=>$input,'id'=>$license_id));$other=$payloads[0];$other['installation_id']=bin2hex(random_bytes(32));$remote=cl_worker(array('operation'=>'activate','input'=>$other));cl_check($remote['body']['valid'],'K: another installation reuses slot');
 cl_check(cl_admin('activate')['code']==='activation_limit_reached'&&!License::allows_new(),'invalid activation cannot enable entitlement');
 cl_worker(array('operation'=>'deactivate','input'=>$other));cl_admin('activate');$GLOBALS['license_outage']=true;cl_admin('deactivate');cl_check(!License::allows_new()&&License::state()['deactivation_pending'],'offline deactivation clears entitlement and retains retry');$GLOBALS['license_outage']=false;License::scheduled();cl_check(!License::state()['deactivation_pending'],'cron retries pending remote deactivation');
 foreach(array('monthly','annual') as $plan){
  $input=cl_fields($plan);$c=cl_worker(array('operation'=>'save','input'=>$input));cl_admin('activate',$c['key']);cl_check(License::allows_new(),"activate $plan");
  $input['expires_at']='2020-01-01 00:00:00';cl_worker(array('operation'=>'save','input'=>$input,'id'=>$c['id']));$r=cl_admin('validate');cl_check($r['code']==='license_expired'&&!License::allows_new(),"H-I: authoritative $plan expiration");cl_admin('deactivate');
 }
 cl_admin('activate',$key);$s=License::state();$s['expires_at']=gmdate('Y-m-d\TH:i:s\Z',time()-1);update_option(License::OPTION,$s,false);cl_check(License::entitlement()==='expired','cached known expiration enforced during outage too');
 $s['expires_at']=null;update_option(License::OPTION,$s,false);update_option('home','https://www.rental.example.test');cl_check(!License::allows_new(),'copied installation at changed site cannot reuse cached entitlement');update_option('home','https://rental.example.test');
 $s=License::state();$s['next_check_at']=time()-1;update_option(License::OPTION,$s,false);$before=$calls;License::scheduled();cl_check($calls===$before+1,'due daily validation executes');
 cl_check(!is_wp_error(cl_admin('deactivate')),'final deactivation succeeds');
 echo "$checks client/two-installation integration checks passed. Real controller REST/DB through subprocess transport bridge; HTTPS network deployment still pending.\n";
}finally{
 remove_filter('pre_http_request',$transport,10);remove_filter('brp_license_enforcement_enabled',$enforce);update_option('home',$saved_home);update_option(License::OPTION,$saved_license,false);if($saved_install){update_option(License::INSTALLATION,$saved_install,false);}else{delete_option(License::INSTALLATION);}wp_set_current_user(1);update_option(Settings::OPTION,$saved_settings);\BikeRentalPlugin\Fleet::set_capacity($saved_capacity);
}
ob_end_flush();
