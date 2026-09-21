/* PHP-rendered customer roster/settings in Chrome; transport isolated, not live WPForms signing. */
const { chromium } = require('playwright');
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const dir = process.env.BRP_WAIVER_FIXTURE_DIR, wp = process.env.BRP_TEST_WP_ROOT;
if (!dir || !wp) throw Error('Run waivers.php with BRP_WAIVER_FIXTURE_DIR first.');
let checks = 0;
const check = (ok, label) => { assert.ok(ok, label); checks++; console.log('PASS:', label); };
(async () => {
 const browser = await chromium.launch({headless:true, channel:process.env.BRP_BROWSER_CHANNEL || 'chrome'});
 try {
  const page = await browser.newPage();
  const css = ['wp-includes/css/dashicons.css','wp-includes/css/buttons.css','wp-admin/css/common.css','wp-admin/css/forms.css'].map(p => fs.readFileSync(path.join(wp,p),'utf8')).join('\n');
  await page.route('**/*', route => {
   if (route.request().method() !== 'GET') return route.abort();
   const settings = new URL(route.request().url()).pathname === '/settings';
   const body = fs.readFileSync(path.join(dir, settings ? 'waiver-settings.html' : 'waiver-roster.html'),'utf8');
   return route.fulfill({contentType:'text/html',body:settings ? '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+css+'</style><body class="wp-admin wp-core-ui">'+body+'</body>' : body});
  });
  for (const width of [1280,782,375]) {
   await page.setViewportSize({width,height:900}); await page.goto('http://127.0.0.1:33319/riders');
   check(await page.locator('fieldset').count() === 2, `${width}: one fieldset per bike`);
   check(await page.getByRole('status').innerText().then(s=>s.includes('not confirmed') && s.includes('0 of 2')), `${width}: incomplete notice and progress`);
   check(await page.locator('[name="riders[1][legal_name]"]').inputValue() === 'Adult One', `${width}: pre-checkout rider name retained`);
   check(await page.locator('[name="riders[1][email]"]').inputValue() === 'adult.one@example.test', `${width}: pre-checkout adult email retained`);
   check(await page.locator('[name="riders[1][age]"]').inputValue() === '18', `${width}: pre-checkout age retained`);
   await page.locator('[name="riders[2][legal_name]"]').focus(); await page.keyboard.press('Tab');
   check(await page.locator('[name="riders[2][age]"]').evaluate(e=>e === document.activeElement), `${width}: keyboard moves to age`);
   check(await page.locator('form').getAttribute('action').then(s=>s.endsWith('/wp-admin/admin-post.php')), `${width}: native POST endpoint`);
   check(await page.locator('input').evaluateAll(inputs=>inputs.filter(e=>e.type !== 'hidden').every(e=>{const r=e.getBoundingClientRect();return r.left>=0 && r.right<=innerWidth;})), `${width}: inputs fit viewport`);
   check(await page.locator('img').count() === 0, `${width}: no signature images in purchaser view`);
   await page.screenshot({path:path.join(dir,`waiver-roster-${width}.png`),fullPage:true});
   await page.goto('http://127.0.0.1:33319/settings');
   check(await page.locator('.nav-tab-active').innerText() === 'Waivers', `${width}: new settings tab active`);
   check(await page.locator('[name="brp_settings[waivers][wpforms][form_id]"]').inputValue() === '77', `${width}: configured form ID editable`);
   check(await page.locator('[name="brp_settings[business_name]"]').count() === 0, `${width}: operational fields excluded from waiver tab`);
   await page.screenshot({path:path.join(dir,`waiver-settings-${width}.png`),fullPage:true});
  }
  console.log(`Waiver browser: ${checks} checks passed. Local rendered fixtures only.`);
 } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
