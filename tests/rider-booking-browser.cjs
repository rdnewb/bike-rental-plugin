/* Real booking markup and JS; isolated API fixtures, no remote checkout or emails. */
const { chromium } = require('playwright');
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const dir = process.env.BRP_GRID_FIXTURE_DIR;
if (!dir) throw Error('Generate product-grid fixtures first.');
const fixture = JSON.parse(fs.readFileSync(path.join(dir, 'cards.json'), 'utf8'));
const markup = fs.readFileSync(path.join(dir, 'cards.html'), 'utf8').replace(/data-api="[^"]+"/, 'data-api="http://127.0.0.1:33319/api/"');
const css = fs.readFileSync(path.join(__dirname, '../bike-rental-plugin/assets/css/booking.css'), 'utf8');
let checks = 0;
const check = (v, text) => { assert.ok(v, text); checks++; console.log('PASS:', text); };
(async () => {
 const browser = await chromium.launch({headless:true,channel:process.env.BRP_BROWSER_CHANNEL || 'chrome'});
 try {
  for (const width of [1280,375]) {
   const page = await browser.newPage({viewport:{width,height:1000}}); const calls=[], errors=[]; page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/*', route => {
    const req=route.request(), url=new URL(req.url());
    if(url.pathname.startsWith('/api/')) {
     const name=url.pathname.split('/').pop(), input=req.method()==='POST'?req.postDataJSON():Object.fromEntries(url.searchParams);calls.push({name,input});
     let data={}; const item=fixture.packages[0];
     if(name==='packages') data={packages:fixture.packages,min_date:'2032-09-01',max_date:'2032-12-01'};
     if(name==='times') data={times:[{time:'09:00'}],message:'Choose start.'};
     if(name==='availability') data={available_quantity:3,rental_start:'2032-09-20T09:00',rental_end:'2032-09-20T13:00',timezone:'America/New_York',package:item,message:'Available'};
     if(name==='session') data={token:'fixture'};
     if(name==='holds') data={reserved:true,reservation_status:'hold',reference:'BRP-RIDERS',quantity:input.quantity,package:item,rental_start:'2032-09-20T09:00',rental_end:'2032-09-20T13:00',timezone:'America/New_York',expires_at:new Date(Date.now()+900000).toISOString(),server_time:new Date().toISOString(),message:'Temporary reservation'};
     if(name==='checkout') data={checkout_url:'http://127.0.0.1:33319/checkout'};
     return route.fulfill({json:data});
    }
    if(url.pathname==='/checkout') return route.fulfill({contentType:'text/html',body:'Checkout fixture'});
    if(req.resourceType()==='image') return route.abort();
    return route.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px system-ui;margin:16px}'+css+'</style>'+markup});
   });
   await page.goto('http://127.0.0.1:33319/booking');await page.addScriptTag({path:path.join(__dirname,'../bike-rental-plugin/assets/js/booking.js')});
   await page.locator(`[data-package-id="${fixture.ids[0]}"] .brp-select`).click();await page.locator('[name=date]').fill('2032-09-20');await page.locator('[name=date]').dispatchEvent('change');
   await page.waitForFunction(()=>!document.querySelector('[name=time]').disabled);await page.locator('[name=time]').selectOption('09:00');await page.waitForFunction(()=>!document.querySelector('.brp-submit').disabled);
   check(await page.locator('.brp-riders fieldset').count()===1,`${width}: one bike one rider`);
   await page.locator('.brp-submit').click();check(!calls.some(c=>c.name==='holds'),`${width}: missing rider blocks hold`);
   await page.locator('[name=quantity]').fill('3');check(await page.locator('.brp-riders fieldset').count()===3,`${width}: three bikes three riders`);
   const rider=i=>page.locator('.brp-riders fieldset').nth(i), input=(i,key)=>rider(i).locator(`[data-field=${key}]`);
   for(let i=0;i<3;i++) { await input(i,'legal_name').fill(i===1?'Minor Rider':'Adult '+i);await input(i,'age').fill(i===1?'17':'18'); }
   check(await input(0,'email').evaluate(e=>e.required),`${width}: 18 requires adult email`);
   check(await input(0,'guardian_name').isHidden(),`${width}: adult guardian fields hidden`);
   check(await input(1,'guardian_name').isVisible() && await input(1,'guardian_name').evaluate(e=>e.required),`${width}: 17 shows required guardian name`);
   check(!await input(1,'email').evaluate(e=>e.required),`${width}: minor email optional`);
   await input(0,'email').fill('adult@example.test');await input(2,'email').fill('other@example.test');
   await page.locator('.brp-submit').click();check(!calls.some(c=>c.name==='holds'),`${width}: guardian missing prevents hold`);
   for(const [key,value] of Object.entries({guardian_name:'Guardian',guardian_email:'guardian@example.test',guardian_relationship:'Parent'})) await input(1,key).fill(value);
   await input(1,'age').fill('18');check(await input(1,'guardian_name').isHidden() && await input(1,'email').evaluate(e=>e.required),`${width}: classification updates at 18`);await input(1,'age').fill('17');
   check(await input(1,'guardian_name').inputValue()==='Guardian',`${width}: age edit preserves current unsaved guardian draft`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${width}: roster fits viewport`);
   await input(1,'age').blur();
   await page.screenshot({path:path.join(dir,`precheckout-riders-${width}.png`),fullPage:true});
   check(await page.locator('.brp-form').evaluate(f=>f.checkValidity()), 'complete roster is valid: '+await page.locator('.brp-form').evaluate(f=>Array.from(f.elements).filter(e=>e.willValidate&&!e.checkValidity()).map(e=>e.name+':'+e.validationMessage).join(',')));
   await page.locator('.brp-submit').click();await page.locator('.brp-result').waitFor({state:'visible'});
   check(calls.filter(c=>c.name==='holds').length===1,`${width}: one hold submission`);
   const saved=calls.find(c=>c.name==='holds').input;
   check(Object.keys(saved.riders).length===3 && saved.riders[2].guardian_email==='guardian@example.test',`${width}: numbered roster sent with hold`);
   check(!calls.some(c=>c.name==='checkout'),`${width}: review precedes checkout`);
   check(!await page.evaluate(()=>JSON.stringify(sessionStorage).includes('example.test')),`${width}: no rider PII in session storage`);
   await page.locator('.brp-checkout').click();await page.waitForURL('**/checkout');check(calls.filter(c=>c.name==='checkout').length===1,`${width}: explicit continue opens checkout`);
   check(errors.length===0,`${width}: no browser errors`);await page.close();
  }
  console.log(`Pre-checkout rider browser: ${checks} checks passed.`);
 } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
