<?php
require __DIR__ . '/license-controller-bootstrap.php';
use NTLicenseController\{Store, Licenses, Api, Admin};
$checks = 0;
function lc_check( $ok, $text ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $text ); } ++$GLOBALS['checks']; echo "PASS: $text\n"; }
function lc_input( $plan = 'lifetime' ) { return array( 'product_slug' => 'bike-rental-plugin', 'plan_type' => $plan, 'status' => 'active', 'expires_at' => 'lifetime' === $plan ? '' : gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 'activation_limit' => 1, 'customer_name' => 'Private customer', 'customer_email' => 'private@example.test', 'notes' => 'Private notes' ); }
function lc_payload( $key, $id = null ) { return array( 'license_key' => $key, 'product_slug' => 'bike-rental-plugin', 'installation_id' => $id ?? bin2hex( random_bytes(32) ), 'site_url' => 'https://example.test/', 'plugin_version' => '0.9.0', 'wordpress_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION ); }
function lc_api( $p, $action = 'validate' ) { $r = new WP_REST_Request( 'POST', '/nt-license/v1/' . $action ); $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode($p) ); $_SERVER['REMOTE_ADDR']='192.0.2.10'; return rest_get_server()->dispatch($r); }
foreach ( array('events','activations','licenses') as $kind ) { $wpdb->query('TRUNCATE TABLE '.Store::table($kind)); }
update_option('ntlc_settings',array('ip_limit'=>10000,'key_limit'=>10000));
lc_check(Store::healthy() && get_option('ntlc_schema')==='1','separate controller schema installed with InnoDB');
lc_check(!class_exists('WooCommerce') && !class_exists('BikeRentalPlugin\\Plugin'),'controller operates independently of Woo and licensed plugin');
foreach (Licenses::PLANS as $plan) {
 $input=lc_input($plan);$created=Licenses::save($input);lc_check(!is_wp_error($created),"create $plan");$row=Licenses::read($created['id']);
 lc_check(($plan==='lifetime')===($row['expires_at']===null),"$plan expiry semantics");
 lc_check($row['license_key_hash']===hash('sha256',$created['key']) && !str_contains(wp_json_encode($row),$created['key']),"$plan key only hashed");
 lc_check($row['billing_provider']===null && $row['billing_customer_id']===null && $row['billing_subscription_id']===null,'nullable billing fields without invented identifiers');
 $payload=lc_payload($created['key']);$result=lc_api($payload,'activate');lc_check($result->get_status()===200 && $result->get_data()['valid'],"$plan REST activation");
 lc_check(lc_api($payload)->get_data()['code']==='license_valid',"$plan REST validation");
 if($plan!=='lifetime') { $input['expires_at']='2020-01-01 00:00:00';Licenses::save($input,$row['id']);lc_check(lc_api($payload)->get_data()['code']==='license_expired',"$plan expiration authoritative"); }
}
$input=lc_input();$created=Licenses::save($input);$id=$created['id'];$key=$created['key'];$payload=lc_payload($key);$p2=lc_payload($key);
$a=lc_api($payload,'activate')->get_data();$b=lc_api($payload,'activate')->get_data();lc_check($a['valid']&&$b['valid']&&Licenses::used($id)===1,'duplicate activation idempotent');
lc_check(lc_api($p2,'activate')->get_data()['code']==='activation_limit_reached','activation limit blocks second installation');
lc_check(lc_api(array_replace($payload,array('site_url'=>'https://www.example.test')))->get_data()['code']==='site_mismatch','www site is distinct');
lc_check(lc_api(array_replace($payload,array('product_slug'=>'other-product')))->get_data()['code']==='product_mismatch','product license cannot cross products');
$row=Licenses::read($id);$response=lc_api($payload)->get_data();foreach(array('customer_name','customer_email','notes','license_key_hash','billing_customer_id') as $field) {lc_check(!array_key_exists($field,$response),"response excludes $field");}
lc_check(count($response)===12,'response allowlist stable');
foreach(array('suspended','revoked','expired') as $status) {$input['status']=$status;Licenses::save($input,$id);lc_check(lc_api($payload)->get_data()['code']==='license_'.$status,"$status rejected");}
$input['status']='active';Licenses::save($input,$id);lc_check(lc_api($payload)->get_data()['valid'],'admin reactivation restores validation');
lc_check(lc_api($payload,'deactivate')->get_data()['code']==='installation_deactivated'&&Licenses::used($id)===0,'deactivation frees slot');
lc_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE license_id=%d',Store::table('activations'),$id))===1,'historical activation retained');
lc_check(lc_api($payload)->get_data()['code']==='installation_not_activated','inactive installation cannot validate');
lc_check(lc_api($p2,'activate')->get_data()['valid'],'freed slot reusable');
$input['activation_limit']=0;Licenses::save($input,$id);lc_check(lc_api($payload,'activate')->get_data()['valid']&&Licenses::used($id)===2,'zero means unlimited');
lc_check(lc_api(lc_payload('NTL1-'.implode('-',array_fill(0,8,'AB123456'))))->get_data()['code']==='license_not_found','unknown well-formed key safely rejected');
foreach(array('license_key'=>array(),'installation_id'=>'x','site_url'=>'http://example.test','product_slug'=>array(),'plugin_version'=>str_repeat('a',41)) as $field=>$bad) { $response=lc_api(array_replace($payload,array($field=>$bad)));lc_check($response->get_status()===400&&$response->get_data()['code']==='invalid_request',"invalid $field rejected"); }
foreach(array('https://EXAMPLE.com:443/shop/'=>'https://example.com/shop','https://example.com'=>'https://example.com','https://www.example.com/'=>'https://www.example.com','https://example.com/Shop/'=>'https://example.com/Shop') as $raw=>$normalized){lc_check(Licenses::site($raw)['url']===$normalized,'site normalization '.$raw);}
foreach(array('http://example.com','https://u:p@example.com','https://example.com?key=x','https://example.com/#x','https://example.com/a/../b','https://example.com/%2e') as $raw){lc_check(false===Licenses::site($raw),'ambiguous/insecure site rejected');}
$rate='fixture:'.bin2hex(random_bytes(10));lc_check(Api::rate($rate,2)&&Api::rate($rate,2)&&!Api::rate($rate,2),'rate limit thresholds enforced');
$_SERVER['HTTPS']='off';lc_check(lc_api($payload)->get_status()===403,'REST requires HTTPS');$_SERVER['HTTPS']='on';
$nonce=wp_create_nonce('ntlc_save');$post=lc_input()+array('operation'=>'save','id'=>0,'timezone'=>wp_timezone_string(),'expiry_local'=>'','_wpnonce'=>$nonce);
lc_check(!is_wp_error(Admin::dispatch($post)),'authorized nonce-protected create');
lc_check(is_wp_error(Admin::dispatch(array_replace($post,array('_wpnonce'=>'bad')))),'invalid nonce denied');
wp_set_current_user(0);lc_check(is_wp_error(Admin::dispatch($post)),'unauthorized admin action denied');wp_set_current_user(1);
lc_check(is_wp_error(Licenses::save(array_replace(lc_input('annual'),array('expires_at'=>'')))),'annual requires expiry');
lc_check(is_wp_error(Licenses::save(array_replace(lc_input(),array('expires_at'=>'2028-01-01 00:00:00')))),'lifetime rejects expiry');
$events=$wpdb->get_col('SELECT event_code FROM '.Store::table('events'));foreach(array('license_created','activated','validation_success','validation_failure','deactivated','license_expired','license_suspended','license_revoked','activation_denied','admin_status_changed') as $event){lc_check(in_array($event,$events,true),'audit event '.$event);}
lc_check(!str_contains(wp_json_encode($wpdb->get_results('SELECT * FROM '.Store::table('events'),ARRAY_A)),$key),'audit has no license secrets');
$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Store::table('events'));lc_api($payload);lc_api($payload);lc_check($before===(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Store::table('events')),'success validation logs bounded to one daily observation');
// Independent concurrent PHP/WordPress connections contend for a single license slot.
$race=Licenses::save(lc_input());$workers=array();
for($i=0;$i<3;$i++){
 $process=proc_open(array(PHP_BINARY,'-c',php_ini_loaded_file(),__DIR__.'/license-controller-worker.php'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
 fwrite($pipes[0],wp_json_encode(array('operation'=>'activate','input'=>lc_payload($race['key']))));fclose($pipes[0]);$workers[]=array($process,$pipes);
}
$wins=0;$denials=0;foreach($workers as [$process,$pipes]){$result=json_decode(stream_get_contents($pipes[1]),true);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);lc_check(proc_close($process)===0&&isset($result['body']['code']),'concurrent worker returns structured result');$wins+=!empty($result['body']['valid']);$denials+=($result['body']['code']??'')==='activation_limit_reached';}
lc_check($wins===1&&$denials===2&&Licenses::used($race['id'])===1,'concurrent activations cannot exceed one slot');
// Lost transaction token prevents a write from surviving outside its original transaction.
$result=Store::locked(static function()use($wpdb,$id){$wpdb->query('SET @ntlc_transaction = NULL');return Store::write('licenses',array('notes'=>'Must not persist'),array('id'=>$id));});
lc_check(is_wp_error($result)&&Licenses::read($id)['notes']!=='Must not persist','lost transaction cannot mutate entitlement');
$failure=static function($query){return str_contains($query,'license_key_hash=')&&str_contains($query,'FOR UPDATE')?'SELECT * FROM ntlc_missing_fixture_table':$query;};
add_filter('query',$failure);$response=lc_api($payload);remove_filter('query',$failure);
lc_check($response->get_status()===503&&$response->get_data()['code']==='controller_unavailable','database read failure is temporary error, never definitive missing license');
lc_check(!str_contains(wp_json_encode($response->get_data()),'ntlc_missing'),'database errors never disclosed');
foreach(array('ntlc','ntlc-add','ntlc-activations','ntlc-events','ntlc-settings') as $page){$_GET=array('page'=>$page);ob_start();Admin::render();$html=ob_get_clean();lc_check(str_contains($html,'NT Licenses')&&!str_contains($html,$key),'admin page renders without raw keys: '.$page);}
echo "$checks controller checks passed. Separate disposable WordPress, real REST and InnoDB.\n";
