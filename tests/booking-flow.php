<?php
/** 1.1.1 date-only booking, service address, safe content, ownership and snapshot integration. */
ob_start();
require __DIR__ . '/inventory-test-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
use BikeRentalPlugin\{BookingSchedule, BookingContent, RentalLocation, Settings, Packages, Database, Reservations, GuestSession, PublicBooking, DataAdmin, ReservationCleanup};
$checks = 0;
function flow_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $message ); } ++$GLOBALS['checks']; echo "PASS: $message\n"; }
function flow_request( $route, $input, $token = '' ) {
 $r = new WP_REST_Request( in_array( $route, array( 'availability', 'packages' ), true ) ? 'GET' : 'POST', '/' . PublicBooking::API . '/' . $route );
 $r->set_header( 'origin', home_url() ); $r->set_header( 'x-brp-request', '1' ); $r->set_header( 'x-brp-token', $token ); foreach ( $input as $k => $v ) { $r->set_param( $k, $v ); }
 return rest_get_server()->dispatch( $r );
}
$saved = Settings::get(); $saved_zone = get_option('timezone_string'); $saved_policy = get_option('woocommerce_refund_returns_page_id'); $page = 0; $product = null; $saved_country = get_option('woocommerce_default_country');
add_filter('pre_wp_mail','__return_false');
try {
 update_option('timezone_string','America/New_York'); update_option('woocommerce_default_country','US:FL');
 $s=Settings::defaults(); foreach($s['weekly_hours'] as &$h){$h=array('open'=>1,'start'=>'08:00','end'=>'18:00');}unset($h);
 $s['preparation_buffer']=30;$s['turnaround_buffer']=45;
 $s['dropoff_disclaimer']='<p>Delivery <strong>window</strong><br><a href="https://example.test/info" onclick="bad()">Details</a></p><script>bad()</script><style>bad</style>';
 $_POST=array('brp_settings_tab'=>'general'); $clean=(new Settings())->sanitize($s);update_option(Settings::OPTION,$clean);
 flow_check(str_contains($clean['dropoff_disclaimer'],'<strong>window</strong>')&&!str_contains($clean['dropoff_disclaimer'],'bad'), 'disclaimer saves safe formatting without scripts, events or styles');
 flow_check(Settings::defaults()['dropoff_disclaimer']==='', 'disclaimer has no seeded customer wording');
 $old=$s;unset($old['dropoff_disclaimer']);flow_check(!Settings::validate($old)['errors'], 'older saved settings remain valid');
 $s=$clean;
 flow_check(!str_contains(\BikeRentalPlugin\Branding::intro('<p>Choose a rental below, then select your date, start time, and number of bikes.</p>'),'start time'), 'old default introduction no longer asks customer to select time');
 $product=new WC_Product_Simple();$product->set_name('Flow fixture');$product->set_slug('flow-fixture');$product->set_status('publish');$product->set_regular_price('30');
 foreach(array(Packages::ENABLED=>'yes',Packages::ACTIVE=>'yes',Packages::TYPE=>'calendar_days',Packages::AMOUNT=>3) as $k=>$v){$product->update_meta_data($k,$v);}$product->save();
 $package=BookingSchedule::package($product->get_id());
 foreach(array('2030-03-09'=>'2030-03-09 13:00:00','2030-03-10'=>'2030-03-10 12:00:00','2030-11-03'=>'2030-11-03 13:00:00') as $date=>$utc){
  $r=BookingSchedule::for_date($package,$date,$s,substr($date,0,7).'-01 00:00:00');
  flow_check(!is_wp_error($r)&&$r['start_utc']===$utc, 'configured opening uses WordPress timezone and DST: '.$date);
  $end=(new DateTimeImmutable($date.' 12:00',wp_timezone()))->modify('+2 days')->format('Y-m-d').'T17:00';
  flow_check($r['local_end']===$end,'inclusive three-day endpoint: '.$date);
  flow_check($r['occupied_start_utc']===\BikeRentalPlugin\RentalTime::shift($utc,-30)&&$r['occupied_end_utc']===\BikeRentalPlugin\RentalTime::shift($r['end_utc'],45),'buffers affect occupancy only: '.$date);
 }
 $gap=$s;$gap['weekly_hours']['sunday']['start']='02:30';flow_check(is_wp_error(BookingSchedule::for_date($package,'2030-03-10',$gap,'2030-03-01 00:00:00')), 'nonexistent DST opening rejected rather than normalized');
 $closed=$s;$closed['weekly_hours']['monday']['open']=0;flow_check(is_wp_error(BookingSchedule::for_date($package,'2030-06-17',$closed,'2030-06-01 00:00:00')), 'closed start day rejected');
 $closed=$s;$closed['weekly_hours']['tuesday']['open']=0;$closed['weekly_hours']['wednesday']['open']=0;flow_check(!is_wp_error(BookingSchedule::for_date($package,'2030-06-17',$closed,'2030-06-01 00:00:00')), 'closed intermediate and pickup days do not invalidate calendar rentals');
 flow_check(is_wp_error(BookingSchedule::for_date($package,'2030-02-30',$s,'2030-02-01 00:00:00')), 'invalid date rejected');
 $notice=$s;$notice['minimum_notice']=60;flow_check(is_wp_error(BookingSchedule::for_date($package,'2030-06-17',$notice,'2030-06-17 11:30:00')), 'minimum notice applies to fixed opening, not a later selectable time');
 flow_check(is_wp_error(BookingSchedule::for_date($package,'2031-06-17',$s,'2030-06-01 00:00:00')), 'booking horizon retained');
 $addr=brp_test_location();$location=RentalLocation::validate($addr);
 flow_check(!is_wp_error($location)&&$location['company']===''&&$location['notes']==='', 'optional service address fields may be empty');
 foreach(array('name','address_1','city','state','postcode') as $key){$bad=$addr;unset($bad[$key]);flow_check(is_wp_error(RentalLocation::validate($bad)), 'required US service field: '.$key);}
 foreach(array(array('country'=>'ZZ'),array('state'=>'XX'),array('postcode'=>'invalid'),array('name'=>array()),array('notes'=>str_repeat('x',2001))) as $change){flow_check(is_wp_error(RentalLocation::validate(array_replace($addr,$change))), 'invalid address input rejected');}
 update_option('woocommerce_default_country','AE'); $hk=array_replace($addr,array('state'=>'','postcode'=>''));flow_check(!is_wp_error(RentalLocation::validate($hk)), 'country without state/postcode requirements accepted');
 update_option('woocommerce_default_country','US:FL');
 flow_check($location['country']==='US'&&RentalLocation::base_country()==='US','country derives from store base including state suffix');
 flow_check(is_wp_error(RentalLocation::validate($addr+array('country'=>'US'))),'even matching customer-supplied country is rejected');
 update_option('woocommerce_default_country','CA:ON');
 $canada=array_replace($addr,array('state'=>'ON','postcode'=>'K1A 0B1'));
 $ca=RentalLocation::validate($canada);
 flow_check(!is_wp_error($ca)&&$ca['country']==='CA','non-US store country is derived without hard-coding');
 flow_check(is_wp_error(RentalLocation::validate($addr)),'US state rejected for Canadian store');
 flow_check(is_wp_error(RentalLocation::validate(array_replace($canada,array('postcode'=>'34205')))),'Canadian postal validation rejects US postcode');
 ob_start();RentalLocation::form('canada');$ca_form=ob_get_clean();
 flow_check(str_contains($ca_form,'value="ON"')&&!str_contains($ca_form,'value="FL"'),'state list follows configured Canadian store');
 $legacy=RentalLocation::from_row(array('snapshot'=>wp_json_encode(array('rental_location'=>$location))));
 flow_check($legacy['country']==='US'&&str_contains(RentalLocation::html($legacy),'United States'),'1.1.0 stored country still renders after store country changes');
 foreach(array('', 'ZZ', array('US')) as $invalid){update_option('woocommerce_default_country',$invalid);flow_check(is_wp_error(RentalLocation::validate($addr)),'missing or invalid store country blocks location without inventing default');}
 update_option('woocommerce_default_country','US:FL');
 $dirty=array_replace($addr,array('name'=>'<b>Fixture</b>','notes'=>"<script>alert(1)</script>Gate\nFront desk"));$sanitized=RentalLocation::validate($dirty);flow_check($sanitized['name']==='Fixture'&&!str_contains($sanitized['notes'],'<')&&str_contains($sanitized['notes'],"\n"), 'address sanitized with multiline notes retained');
 flow_check(!str_contains(RentalLocation::html(array_replace($location,array('notes'=>'<script>bad</script>'))),'<script>'), 'formatted location output escaped');
 $page=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'Fixture policy','post_content'=>'<h2>Returns</h2><p>Published <strong>policy</strong><a href="javascript:bad()" onclick="bad()">Link</a></p><script>bad()</script>[bike_rental_booking]'));
 update_option('woocommerce_refund_returns_page_id',$page);$policy=BookingContent::policy();
 flow_check(str_contains($policy,'<strong>policy</strong>')&&!str_contains($policy,'<script')&&!str_contains($policy,'onclick')&&!str_contains($policy,'javascript:')&&!str_contains($policy,'brp-booking'),'configured Woo policy preserves formatting without code/recursive shortcode');
 wp_update_post(array('ID'=>$page,'post_status'=>'draft'));flow_check(BookingContent::policy()==='', 'unpublished policy is not exposed');
 wp_update_post(array('ID'=>$page,'post_status'=>'publish','post_password'=>'secret'));flow_check(BookingContent::policy()==='', 'password-protected policy is not exposed');
 wp_update_post(array('ID'=>$page,'post_password'=>''));
 $_GET=array('rental'=>'flow-fixture');$html=PublicBooking::shortcode();
 flow_check(!str_contains($html,'location_country')&&!str_contains($html,'data-location="country"')&&!str_contains($html,'Select country'),'no country input, label or hidden control');
 flow_check(!str_contains($html,'name="time"')&&str_contains($html,'name="date"'),'public date remains and customer time selector is absent');
 flow_check(str_contains($html,'data-preselected="'.$product->get_id().'"'), 'product deep link still preselects');
 foreach(array_diff(array_keys(RentalLocation::labels()),array('country')) as $key){flow_check(str_contains($html,'name="location_'.$key.'"'),'public location control: '.$key);}
 flow_check(strpos($html,'name="date"')<strpos($html,'brp-time-disclaimer')&&strpos($html,'brp-time-disclaimer')<strpos($html,'brp-location"'), 'hidden disclaimer sits immediately after date before remaining fields');
 flow_check(strpos($html,'brp-policy"')<strpos($html,'class="brp-submit"')&&!str_contains($html,'type="checkbox"'),'policy immediately precedes reserve button without acceptance checkbox');
 $blank=$s;$blank['dropoff_disclaimer']='';update_option(Settings::OPTION,$blank);flow_check(!str_contains(PublicBooking::shortcode(),'brp-time-disclaimer'),'blank disclaimer omits notice');update_option(Settings::OPTION,$s);
 update_option('woocommerce_refund_returns_page_id',-1);flow_check(!str_contains(PublicBooking::shortcode(),'brp-policy"'),'missing policy omits section without failure');update_option('woocommerce_refund_returns_page_id',$page);
 $wpdb->query('DELETE FROM '.Database::table('reservations'));$wpdb->query('DELETE FROM '.Database::table('availability').' WHERE id<>1');\BikeRentalPlugin\Fleet::set_capacity(3);
 $date=(new DateTimeImmutable('today',wp_timezone()))->modify('+10 days')->format('Y-m-d');$input=array('package_id'=>$product->get_id(),'date'=>$date);
 wp_set_current_user(0);$session=flow_request('session',array())->get_data();$token=$session['token'];
 $a=flow_request('availability',$input)->get_data();flow_check($a['available_quantity']===3&&$a['rental_start']===$date.'T08:00','public availability derives configured opening');
 flow_check(flow_request('availability',$input+array('time'=>'12:00'))->get_status()===400,'client cannot override fixed time');
 $hold=$input+array('quantity'=>2,'riders'=>brp_test_riders(2),'request_key'=>bin2hex(random_bytes(16)),'rental_location'=>$addr);
 $bad=$hold;unset($bad['rental_location']);flow_check(flow_request('holds',$bad,$token)->get_status()===400,'missing service location blocks hold');
 $response=flow_request('holds',$hold,$token);$receipt=$response->get_data();flow_check($response->get_status()===200&&$receipt['reserved'],'date-only Reserve Bikes creates hold');
 $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE request_key=%s',Database::table('reservations'),$hold['request_key']),ARRAY_A);
 flow_check(RentalLocation::from_row($row)===$location&&RentalLocation::from_row($row)['country']==='US','structured location persists in existing reservation snapshot');
 flow_check(str_contains($receipt['rental_location'],$addr['address_1']),'owned receipt shows human-readable location');
 flow_check(flow_request('availability',$input)->get_data()['available_quantity']===1,'derived occupied interval consumes fleet capacity');
 $changed=$hold;$changed['rental_location']['address_1']='456 Other Road';flow_check(flow_request('holds',$changed,$token)->get_status()===400,'same idempotency key cannot silently change location');
 flow_check(flow_request('holds',$hold,$token)->get_data()['reference']===$receipt['reference'],'identical address retry reuses reservation');
 unset($_COOKIE[GuestSession::cookie_name()]);$other=flow_request('session',array())->get_data();$unauthorized=flow_request('hold-status',array('request_key'=>$hold['request_key']),$other['token']);flow_check($unauthorized->get_status()===400&&!str_contains(wp_json_encode($unauthorized->get_data()),$addr['address_1']),'another guest cannot obtain rental address');
 wp_set_current_user(1);$_GET=array('id'=>$row['id']);ob_start();(new DataAdmin())->reservations();$admin=ob_get_clean();flow_check(str_contains($admin,'Drop Off / Pick Up Location')&&str_contains($admin,$addr['address_1']),'reservation admin displays formatted location');
 $updated=Reservations::update($row['id'],array('issue_code'=>'reviewed','quantity'=>2,'start'=>$date.'T08:00','end'=>json_decode($row['snapshot'],true)['local_end']),$row['revision']);flow_check(!is_wp_error($updated)&&RentalLocation::from_row($updated)===$location,'existing edit preserves rental location');
 $replacement=clone $product;$replacement->set_id(0);$replacement->set_name('Replacement fixture');$replacement->save();
 $updated=Reservations::update($row['id'],array('package_product_id'=>$replacement->get_id(),'quantity'=>2,'start'=>$date.'T08:00','end'=>json_decode($row['snapshot'],true)['local_end']),$updated['revision']);flow_check(!is_wp_error($updated)&&RentalLocation::from_row($updated)===$location,'package replacement retains service location');$replacement->delete(true);
 flow_check(RentalLocation::html(RentalLocation::from_row(array('snapshot'=>'{}')))==='', 'older reservation without location remains displayable');
 $cancel=Reservations::cancel($row['id'],$updated['revision']);$wpdb->update(Database::table('reservations'),array('updated_at'=>'2000-01-01 00:00:00'),array('id'=>$row['id']));ReservationCleanup::retention();flow_check(RentalLocation::from_row(Reservations::read($row['id']))===array(),'abandoned PII cleanup removes location while retaining reservation');
 flow_check(Database::VERSION==='2','schema remains 2');
 if($dir=getenv('BRP_FLOW_FIXTURE_DIR')){$_GET=array('rental'=>'flow-fixture');file_put_contents($dir.'/booking.html',PublicBooking::shortcode());file_put_contents($dir.'/fixture.json',wp_json_encode(array('id'=>$product->get_id(),'package'=>array('product_id'=>$product->get_id(),'name'=>'Flow fixture','price_html'=>'$30.00'),'date'=>$date,'end'=>(new DateTimeImmutable($date.' 12:00',wp_timezone()))->modify('+2 days')->format('Y-m-d'))));}
 echo "$checks booking-flow integration checks passed; WooCommerce ".WC_VERSION.".\n";
} finally { update_option('woocommerce_default_country',$saved_country); update_option(Settings::OPTION,$saved);update_option('timezone_string',$saved_zone);update_option('woocommerce_refund_returns_page_id',$saved_policy);if($page){wp_delete_post($page,true);}if($product){$product->delete(true);}wp_set_current_user(1); }
ob_end_flush();
