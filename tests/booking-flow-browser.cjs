/* Real PHP markup and runtime JS; mocked REST transport, no live bookings/payment. */
const { chromium } = require('playwright');
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const dir = process.env.BRP_FLOW_FIXTURE_DIR;
const fixture = JSON.parse(fs.readFileSync(path.join(dir, 'fixture.json'), 'utf8'));
const markup = fs.readFileSync(path.join(dir, 'booking.html'), 'utf8').replace(/data-api="[^"]+"/, 'data-api="http://127.0.0.1:33319/api/"');
const css = fs.readFileSync(path.join(__dirname, '../bike-rental-plugin/assets/css/booking.css'), 'utf8');
const js = fs.readFileSync(path.join(__dirname, '../bike-rental-plugin/assets/js/booking.js'), 'utf8');
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS:', label); }
(async () => {
 const browser = await chromium.launch({headless:true, channel:process.env.BRP_BROWSER_CHANNEL || 'chrome'});
 try {
  for (const [name,width] of [['desktop',1280],['tablet',768],['mobile',390]]) {
   const page = await browser.newPage({viewport:{width,height:1000}}), errors=[], requests=[];
   let rejectDate=false;
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*',async route=>{
    const request=route.request(), url=new URL(request.url());
    if(url.pathname==='/reserve/') return route.fulfill({contentType:'text/html',body:'<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>'+css+'</style>'+markup+'<script>'+js+'</script>'});
    if(url.pathname==='/checkout/') return route.fulfill({contentType:'text/html',body:'<h1>Checkout fixture</h1>'});
    if(!url.pathname.startsWith('/api/'))return route.abort();
    const action=url.pathname.split('/').pop(),input=request.method()==='POST'?request.postDataJSON():Object.fromEntries(url.searchParams);requests.push({action,input});
    const schedule={valid:true,rental_start:fixture.date+'T08:00',rental_end:fixture.end+'T17:00',timezone:'America/New_York',package:fixture.package,available_quantity:3,message:'3 bikes are available.'};
    if(action==='packages')return route.fulfill({json:{packages:[fixture.package],min_date:'2026-01-01',max_date:'2035-12-31'}});
    if(action==='availability')return route.fulfill({status:rejectDate?400:200,json:rejectDate?{message:'Please choose an open start day.'}:schedule});
    if(action==='session')return route.fulfill({json:{valid:true,token:'fixture'}});
    if(action==='holds')return route.fulfill({json:{...schedule,reserved:true,reference:'BRP-FIXTURE',quantity:Number(input.quantity),rental_location:'Example Guest\n123 Example Street\nExample City',expires_at:new Date(Date.now()+900000).toISOString(),server_time:new Date().toISOString(),message:'Continue to checkout.',reservation_status:'hold'}});
    if(action==='checkout')return route.fulfill({json:{valid:true,checkout_url:'http://127.0.0.1:33319/checkout/'}});
    return route.fulfill({status:400,json:{message:'Fixture unavailable'}});
   });
   await page.goto('http://127.0.0.1:33319/reserve/?rental=flow-fixture');
   await page.locator('[name=date]:enabled').waitFor();
   check(await page.locator('.brp-card:visible').count()===1,name+': deep link selects package');
   check(await page.locator('[name=time], input[type=time], .brp-time-picker').count()===0,name+': no customer time selector');
   check(!await page.locator('.brp-time-disclaimer').isVisible(),name+': disclaimer hidden before date');
   await page.locator('[name=date]').fill(fixture.date);await page.locator('[name=date]').dispatchEvent('change');
   await page.locator('.brp-submit:enabled').waitFor();
   check(await page.locator('.brp-time-disclaimer').isVisible(),name+': disclaimer revealed after valid date');
   check(!requests.some(r=>r.action==='times')&&!requests.find(r=>r.action==='availability').input.time,name+': date-only availability request');
   check(await page.locator('.brp-summary').textContent().then(v=>v.includes('8:00 AM')),name+': derived schedule shown in 12-hour time');
   const positions=await page.evaluate(()=>{const q=s=>document.querySelector(s).getBoundingClientRect().top;return {date:q('[name=date]'),notice:q('.brp-time-disclaimer'),address:q('.brp-location'),policy:q('.brp-policy'),button:q('.brp-submit')};});
   check(positions.date<positions.notice&&positions.notice<positions.address&&positions.address<positions.policy&&positions.policy<positions.button,name+': notice/address/policy/button order');
   check(await page.locator('.brp-submit').evaluate(b=>b.previousElementSibling.classList.contains('brp-policy')),name+': policy immediately above Reserve Bikes');
   check(await page.locator('[name=location_country], [data-location=country], [autocomplete="section-rental country"]').count()===0,name+': no editable or hidden country control');
   check(await page.locator('[name=location_state]').evaluate(e=>e.tagName==='SELECT'&&e.required&&Array.from(e.options).some(o=>o.value==='FL')),name+': store country initializes required state selector');
   check(await page.locator('[name=location_postcode]').evaluate(e=>e.required),name+': postcode requirement follows store country');
   await page.locator('.brp-submit').click();check(!requests.some(r=>r.action==='holds'),name+': required location blocks incomplete submit');
   for(const [key,value] of Object.entries({name:'Example Guest',address_1:'123 Example Street',city:'Example City',postcode:'34205',notes:'Front desk'}))await page.locator('[name=location_'+key+']').fill(value);
   await page.locator('[name=location_state]').selectOption('FL');
   await page.locator('[data-field=legal_name]').fill('Example Rider');await page.locator('[data-field=age]').fill('25');await page.locator('[data-field=email]').fill('rider@example.test');
   await page.locator('.brp-policy-content').focus();for(let i=0;i<5&&!await page.locator('.brp-submit').evaluate(e=>e===document.activeElement);i++)await page.keyboard.press('Tab');check(await page.locator('.brp-submit').evaluate(e=>e===document.activeElement),name+': keyboard reaches reserve after policy');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),name+': no horizontal page overflow');
   await page.locator('.brp-time-disclaimer').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(dir,name+'-booking.png'),fullPage:true});
   await page.locator('.brp-submit').click();await page.locator('.brp-result:visible').waitFor();
   const sent=requests.find(r=>r.action==='holds').input;
   check(!('country' in sent.rental_location)&&sent.rental_location.address_1==='123 Example Street'&&!('time'in sent)&&sent.riders['1'].legal_name==='Example Rider',name+': hold includes address and riders with no client time');
   check(await page.locator('.brp-receipt').textContent().then(v=>v.includes('Drop Off / Pick Up Location')),name+': receipt shows service address');
   await page.locator('.brp-checkout').click();await page.waitForURL('**/checkout/');check(true,name+': Continue to checkout works');
   check(errors.length===0,name+': no JavaScript errors');await page.close();
  }
  // Failed dates must clear a previously shown disclaimer and disable reservation.
  const page=await browser.newPage();let fail=false;
  await page.route('**/*',route=>{const url=new URL(route.request().url());if(url.pathname==='/reserve/')return route.fulfill({contentType:'text/html',body:'<style>'+css+'</style>'+markup+'<script>'+js+'</script>'});if(url.pathname.endsWith('packages'))return route.fulfill({json:{packages:[fixture.package],min_date:'2026-01-01',max_date:'2035-12-31'}});if(url.pathname.endsWith('availability'))return route.fulfill({status:fail?400:200,json:fail?{message:'Please choose an open start day.'}:{valid:true,package:fixture.package,available_quantity:1,rental_start:fixture.date+'T08:00',rental_end:fixture.date+'T17:00',timezone:'America/New_York',message:'Available'}});return route.abort();});
  await page.goto('http://127.0.0.1:33319/reserve/?rental=flow-fixture');await page.locator('[name=date]:enabled').waitFor();await page.locator('[name=date]').fill(fixture.date);await page.locator('[name=date]').dispatchEvent('change');await page.locator('.brp-submit:enabled').waitFor();
  fail=true;await page.locator('[name=date]').dispatchEvent('change');await page.getByRole('status').filter({hasText:'open start day'}).waitFor();check(!await page.locator('.brp-time-disclaimer').isVisible()&&await page.locator('.brp-submit').isDisabled(),'invalid date clears notice and prevents booking');await page.close();
  const unavailable=await browser.newPage(), errors=[];
  unavailable.on('pageerror',e=>errors.push(e.message));
  await unavailable.route('**/*',route=>new URL(route.request().url()).pathname==='/reserve/'?route.fulfill({contentType:'text/html',body:markup.replace(/<fieldset class="brp-location"[\s\S]*?<\/fieldset>/,'')+'<script>'+js+'</script>'}):route.fulfill({json:{packages:[],min_date:'2026-01-01',max_date:'2035-12-31'}}));
  await unavailable.goto('http://127.0.0.1:33319/reserve/');
  await unavailable.getByRole('status').filter({hasText:'No rental packages'}).waitFor();
  check(errors.length===0&&await unavailable.locator('.brp-submit').isDisabled(),'missing Woo location form fails gracefully with booking disabled');
  await unavailable.close();
  console.log(checks+' booking-flow browser checks passed. Mocked REST, no live payment.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
