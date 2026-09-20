/* Real PHP-rendered markup + shipped CSS/JS. Local-only mock booking transport. */
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const dir = process.env.BRP_BRANDING_FIXTURE_DIR;
if (!dir) throw new Error('Run booking-branding.php with BRP_BRANDING_FIXTURE_DIR first');
const read = (name) => fs.readFileSync(path.join(dir, name), 'utf8');
const packages = JSON.parse(read('brand-packages.json'));
const runtime = path.join(__dirname, '../bike-rental-plugin');
const css = fs.readFileSync(path.join(runtime, 'assets/css/booking.css'), 'utf8');
const script = path.join(runtime, 'assets/js/booking.js');
const base = 'http://127.0.0.1:33319';
let checks = 0;
const check = (ok, label) => { assert.ok(ok, label); checks++; console.log('PASS:', label); };
(async () => {
    const browser = await chromium.launch({ headless: true, channel: process.env.BRP_BROWSER_CHANNEL || 'chrome' });
    try {
        const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
        const errors = [], calls = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.route('**/*', async (route) => {
            const req = route.request(), url = new URL(req.url());
            if (url.pathname.startsWith('/api/')) {
                const name = url.pathname.split('/').pop(), input = req.method() === 'POST' ? req.postDataJSON() : Object.fromEntries(url.searchParams);
                calls.push({ name, input });
                const item = packages.find((p) => String(p.product_id) === input.package_id) || packages[0];
                let data;
                if (name === 'packages') data = { packages, min_date: '2032-03-01', max_date: '2032-12-01' };
                if (name === 'times') data = { times: [{ time: '09:00' }, { time: '13:30' }], message: 'Choose a start time.' };
                if (name === 'availability') data = { available_quantity: 3, rental_start: '2032-03-08T09:00', rental_end: '2032-03-10T17:00', timezone: 'America/New_York', package: item, message: '3 bikes available.' };
                if (name === 'session') data = { token: 'fixture-token' };
                if (name === 'holds') data = { reserved: true, reservation_status: 'hold', reference: 'BRP-BRANDING', package: item, quantity: input.quantity, rental_start: '2032-03-08T09:00', rental_end: '2032-03-10T17:00', timezone: 'America/New_York', expires_at: new Date(Date.now() + 900000).toISOString(), server_time: new Date().toISOString(), message: 'Your bikes are temporarily reserved.' };
                if (name === 'checkout') data = { checkout_url: base + '/checkout' };
                return route.fulfill({ json: data });
            }
            if (req.resourceType() === 'image') return route.fulfill({ contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAAE0lEQVR4nGO8/ugeAwwwwVnoHABgaAKd72wTXQAAAABJRU5ErkJggg==', 'base64') });
            if (req.resourceType() === 'stylesheet') return route.fulfill({ body: '' });
            if (url.pathname === '/checkout') return route.fulfill({ body: '<p>Checkout fixture</p>', contentType: 'text/html' });
            const file = url.pathname.includes('default') ? 'brand-default.html' : url.searchParams.has('rental') ? 'brand-deep.html' : 'brand-normal.html';
            const markup = read(file).replace(/data-api="[^"]+"/, `data-api="${base}/api/"`);
            return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px Georgia,serif;margin:16px}h2,h3{font-family:Georgia,serif}.outside{color:rgb(40,50,60);letter-spacing:0}.outside button{background:rgb(230,230,230);color:rgb(20,20,20)}' + css + '</style></head><body><div class="outside"><button>Unrelated theme button</button><p class="brp-intro">Unrelated content</p></div>' + markup + '</body></html>' });
        });
        const load = async (url) => { await page.goto(url); await page.evaluate(() => sessionStorage.clear()); await page.addScriptTag({ path: script }); await page.waitForFunction(() => !document.querySelector('.brp-status').textContent.includes('Loading')); };
        await load(base + '/default');
        check(await page.locator('.brp-card:visible').count() === 3, 'default grid remains functional');
        check(await page.locator('.brp-select:visible').first().textContent() === 'Select Rental', 'default label unchanged');
        check(await page.locator('.brp-booking').getAttribute('style') === '', 'untouched settings have no CSS overrides');
        await load(base + '/reserve/?rental=' + packages[1].slug);
        check(await page.locator('.brp-card:visible').count() === 1, 'branded deep link shows one package');
        check((await page.locator('.brp-select[aria-pressed=true]').textContent()).includes('Your choice'), 'custom selected text retained after initialization');
        check(await page.locator('.brp-change-rental').textContent() === 'Choose another', 'custom Change Rental label');
        check(await page.locator('.brp-card.brp-selected').evaluate((e) => getComputedStyle(e).borderColor) === 'rgb(52, 86, 120)', 'configured selected border applied');
        check(await page.locator('.brp-card:visible').evaluate((e) => getComputedStyle(e).backgroundColor) === 'rgb(254, 254, 254)', 'configured card background applied');
        check(await page.locator('.brp-select:visible').evaluate((e) => getComputedStyle(e).backgroundColor) === 'rgb(18, 52, 86)', 'configured button background applied');
        check(await page.locator('.brp-select:visible').evaluate((e) => getComputedStyle(e).color) === 'rgb(255, 255, 255)', 'configured button text applied');
        check(await page.locator('.brp-card:visible').evaluate((e) => getComputedStyle(e).borderRadius) === '12px', 'radius preset applied');
        check(await page.locator('.brp-intro').last().evaluate((e) => getComputedStyle(e).letterSpacing) === '1px', 'scoped custom CSS applies inside booking wrapper');
        check(await page.locator('.outside .brp-intro').evaluate((e) => getComputedStyle(e).letterSpacing) === 'normal', 'custom CSS does not reach unrelated matching class');
        check(await page.locator('.outside button').evaluate((e) => getComputedStyle(e).backgroundColor) === 'rgb(230, 230, 230)', 'theme/Woo-style buttons outside wrapper unaffected');
        check(await page.locator('.brp-select:visible').evaluate((e) => getComputedStyle(e).fontFamily.includes('Georgia')), 'buttons inherit active theme font family');
        check(await page.locator('.brp-card:visible h3').evaluate((e) => getComputedStyle(e).fontFamily.includes('Georgia')), 'card headings inherit theme heading font family');
        await page.locator('.brp-change-rental').focus();
        check(await page.locator('.brp-change-rental').evaluate((e) => getComputedStyle(e).outlineStyle !== 'none' && getComputedStyle(e).outlineColor === 'rgb(170, 187, 204)'), 'keyboard focus remains visible with accent');
        await page.keyboard.press('Enter');
        check(await page.locator('.brp-card:visible').count() === 3, 'custom Change Rental button reveals grid');
        const other = page.locator(`[data-package-id="${packages[2].product_id}"] .brp-select`);
        check(await other.textContent() === 'Book this', 'unselected custom label retained');
        await other.focus(); await page.keyboard.press('Space');
        check(await other.getAttribute('aria-pressed') === 'true' && (await other.textContent()).includes('Your choice'), 'keyboard changes selection with custom text and pressed state');
        check(await page.locator(`[data-package-id="${packages[1].product_id}"] .brp-select`).textContent() === 'Book this', 'previous card restores configured select text');
        for (const [width, columns] of [[1280, 3], [800, 2], [375, 1], [320, 1]]) {
            await page.setViewportSize({ width, height: 1000 });
            check(await page.locator('.brp-grid').evaluate((e) => getComputedStyle(e).gridTemplateColumns.split(' ').length) === columns, `${width}px preserves ${columns}-column grid`);
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width}px has no horizontal overflow`);
            check(await page.locator('.brp-logo').evaluate((e) => e.getBoundingClientRect().width <= e.parentElement.getBoundingClientRect().width), `${width}px logo stays within container`);
            check((await other.boundingBox()).height >= 48, `${width}px button tap target retained`);
            if ([1280, 375].includes(width)) await page.screenshot({ path: path.join(dir, `branding-${width}.png`), fullPage: true });
        }
        check(await page.locator('.brp-logo').getAttribute('alt') === 'Generic rental mark', 'logo existing alternative text preserved');
        await page.locator('[name=date]').fill('2032-03-08'); await page.locator('[name=date]').dispatchEvent('change');
        await page.waitForFunction(() => !document.querySelector('[name=time]').disabled);
        check(await page.locator('[name=time] option').last().textContent() === '1:30 PM', 'start-time labels unchanged');
        await page.locator('[name=time]').selectOption('09:00'); await page.waitForFunction(() => !document.querySelector('.brp-submit').disabled);
        await page.locator('[name=quantity]').fill('2'); await page.locator('.brp-submit').click(); await page.waitForURL('**/checkout');
        const hold = calls.filter((c) => c.name === 'holds').at(-1), checkout = calls.filter((c) => c.name === 'checkout').at(-1);
        check(hold.input.package_id === String(packages[2].product_id) && hold.input.quantity === '2', 'branded form holds the newly selected package and quantity');
        check(checkout.input.request_key === hold.input.request_key, 'checkout handoff keeps existing hold identity');
        check(errors.length === 0, 'no browser JavaScript errors');
        console.log(`Booking branding browser: ${checks} checks passed.`);
    } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
