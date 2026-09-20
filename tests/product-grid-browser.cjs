/* Chromium UI regression using real PHP-rendered cards and mocked existing REST responses.
 * Run product-grid.php with BRP_GRID_FIXTURE_DIR first. No remote site or payments are used.
 */
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const fixtureDir = process.env.BRP_GRID_FIXTURE_DIR;
if (!fixtureDir) throw new Error('BRP_GRID_FIXTURE_DIR is required');
const fixture = JSON.parse(fs.readFileSync(path.join(fixtureDir, 'cards.json'), 'utf8'));
const markup = fs.readFileSync(path.join(fixtureDir, 'cards.html'), 'utf8').replace(/data-api="[^"]+"/, 'data-api="http://127.0.0.1:33319/api/"');
const deepMarkup = fs.readFileSync(path.join(fixtureDir, 'cards-deep.html'), 'utf8').replace(/data-api="[^"]+"/, 'data-api="http://127.0.0.1:33319/api/"');
const css = fs.readFileSync(path.join(__dirname, '../bike-rental-plugin/assets/css/booking.css'), 'utf8');
const script = path.join(__dirname, '../bike-rental-plugin/assets/js/booking.js');
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS:', label); }
(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.BRP_BROWSER_CHANNEL ? { channel: process.env.BRP_BROWSER_CHANNEL } : {}) });
    try {
        const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
        const calls = [], errors = [];
        let catalog = fixture.packages, catalogFailure = false, slowTimes = false, restoredStatus = 'expired';
        page.on('pageerror', (error) => errors.push(error.message));
        await page.route('**/*', async (route) => {
            const request = route.request(), url = new URL(request.url());
            if (url.pathname.startsWith('/api/')) {
                const name = url.pathname.split('/').pop();
                const input = request.method() === 'POST' ? request.postDataJSON() : Object.fromEntries(url.searchParams);
                calls.push({ name, input });
                let data;
                if (name === 'packages') {
                    if (catalogFailure) return route.fulfill({ status: 503, json: { valid: false, message: 'Online rental selection is temporarily unavailable. Please try again.' } });
                    data = { packages: catalog, min_date: '2032-09-01', max_date: '2032-12-01' };
                }
                if (name === 'times') {
                    if (slowTimes && input.package_id === String(fixture.ids[0])) await new Promise((resolve) => setTimeout(resolve, 250));
                    data = { times: [{ time: '09:00' }, { time: '13:30' }], message: 'Choose a start time.' };
                }
                if (name === 'availability') data = { available_quantity: 3, rental_start: '2032-09-20T09:00', rental_end: '2032-09-22T17:00', timezone: 'America/New_York', package: catalog.find((p) => String(p.product_id) === input.package_id), message: '3 bikes are available.' };
                if (name === 'session') data = { token: 'fixture-csrf' };
                if (name === 'holds') data = { reserved: true, reservation_status: 'hold', reference: 'BRP-FIXTURE', package: catalog.find((p) => String(p.product_id) === input.package_id), quantity: input.quantity, rental_start: '2032-09-20T09:00', rental_end: '2032-09-22T17:00', timezone: 'America/New_York', expires_at: new Date(Date.now() + 900000).toISOString(), server_time: new Date().toISOString(), message: 'Your bikes are temporarily reserved.' };
                if (name === 'hold-status') data = { reserved: restoredStatus === 'hold', reservation_status: restoredStatus, reference: 'BRP-RESTORED', package: fixture.packages[0], quantity: 1, rental_start: '2032-09-20T09:00', rental_end: '2032-09-20T13:00', timezone: 'America/New_York', expires_at: restoredStatus === 'cancelled' ? null : new Date(Date.now() + (restoredStatus === 'hold' ? 900000 : -1000)).toISOString(), server_time: new Date().toISOString(), message: 'Restored reservation status: ' + restoredStatus };
                if (name === 'checkout') data = { checkout_url: 'http://127.0.0.1:33319/checkout' };
                return route.fulfill({ json: data });
            }
            if (url.pathname === '/checkout') return route.fulfill({ contentType: 'text/html', body: '<p>Checkout transfer destination fixture</p>' });
            if (request.resourceType() === 'image') return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="400"><rect width="800" height="400" fill="#9bc6ce"/><circle cx="400" cy="200" r="100" fill="#244b54"/></svg>' });
            if (request.resourceType() === 'stylesheet') return route.fulfill({ contentType: 'text/css', body: '' });
            return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{font:16px system-ui;margin:16px}' + css + '</style></head><body>' + (url.searchParams.has('rental') ? deepMarkup : markup) + '</body></html>' });
        });
        const card = (index) => page.locator(`[data-package-id="${fixture.ids[index]}"]`);
        const button = (index) => card(index).locator('button');
        const load = async (clearHistory = true) => { await page.goto('http://127.0.0.1:33319/booking'); if (clearHistory) await page.evaluate(() => sessionStorage.clear()); await page.addScriptTag({ path: script }); await page.waitForFunction(() => !document.querySelector('.brp-status').textContent.includes('Loading')); };
        await load();
        check(await page.locator('.brp-card:visible').count() === 4, 'all active fixture cards visible');
        check(!await page.locator('.brp-details').isVisible(), 'controls hidden before selection');
        await card(0).scrollIntoViewIfNeeded();
        await page.waitForFunction((id) => { const img = document.querySelector(`[data-package-id="${id}"] img`); return img.complete && img.naturalWidth > 0; }, fixture.ids[0]);
        check(await card(0).locator('img').evaluate((img) => img.complete && img.naturalWidth > 0), 'featured image loads without breakage');
        check(await card(1).locator('.brp-image-fallback').isVisible(), 'missing image fallback visible');
        for (const [width, columns] of [[1280, 3], [800, 2], [375, 1], [320, 1]]) {
            await page.setViewportSize({ width, height: 1000 });
            check(await page.locator('.brp-grid').evaluate((grid) => getComputedStyle(grid).gridTemplateColumns.split(' ').length) === columns, `${width}px grid has ${columns} columns`);
            check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `${width}px has no horizontal overflow`);
            check((await button(0).boundingBox()).height >= 48, `${width}px tap target at least 48px`);
            check(await card(0).locator('.brp-card-image').evaluate(el => Math.abs(el.getBoundingClientRect().width - el.parentElement.clientWidth) < 1), `${width}px image wrapper fills card interior`);
            check(await card(0).locator('img').evaluate(el => { const a = el.getBoundingClientRect(), b = el.parentElement.getBoundingClientRect(), s = getComputedStyle(el); return Math.abs(a.width - b.width) < 1 && Math.abs(a.height - b.height) < 1 && Math.abs(a.width / a.height - 4 / 3) < .01 && s.objectFit === 'cover' && s.objectPosition === '50% 50%' && el.naturalWidth / el.naturalHeight === 2; }), `${width}px non-4:3 image fills centered 4:3 crop without gutters or stretching`);
            check(await card(1).locator('.brp-card-image').evaluate(el => Math.abs(el.getBoundingClientRect().width - el.parentElement.clientWidth) < 1), `${width}px fallback spans card width`);
            check(await card(2).locator('.brp-description').evaluate(el => el.scrollHeight <= el.clientHeight + 1 && el.scrollWidth <= el.clientWidth && getComputedStyle(el).maxHeight === 'none'), `${width}px description fully visible and wraps naturally`);
            check(await card(0).evaluate(el => getComputedStyle(el).overflow === 'hidden' && parseFloat(getComputedStyle(el).borderTopLeftRadius) > 0), `${width}px card clips image to rounded corners`);
            check((await card(1).boundingBox()).height < (await card(2).boundingBox()).height, `${width}px short card is not stretched to long description height`);
            check(await card(0).locator('.brp-card-price').evaluate(el => el.scrollWidth <= el.clientWidth), `${width}px price remains within card`);
            if ([1280, 375].includes(width)) await page.screenshot({ path: path.join(fixtureDir, `grid-${width}.png`), fullPage: true });
        }
        await page.setViewportSize({ width: 1280, height: 1000 });
        await button(0).focus();
        check(await button(0).evaluate((el) => getComputedStyle(el).outlineStyle !== 'none'), 'keyboard focus visibly outlined');
        await page.keyboard.press('Enter');
        check(await page.locator('[name=package_id]').inputValue() === String(fixture.ids[0]), 'Enter selects package and synchronizes hidden field');
        check(await button(0).getAttribute('aria-pressed') === 'true' && (await button(0).innerText()).includes('Selected'), 'selection conveyed programmatically and visibly');
        check(await page.locator('[name=date]').evaluate((el) => el === document.activeElement && !el.disabled), 'selection reveals and focuses date control');
        await button(2).focus(); await page.keyboard.press('Space');
        check(await page.locator('[name=package_id]').inputValue() === String(fixture.ids[2]), 'Space selects another package');
        check(await page.locator('.brp-select[aria-pressed=true]').count() === 1 && await button(0).getAttribute('aria-pressed') === 'false', 'exactly one package selected');
        check(await card(2).locator('.brp-description').evaluate((el) => el.scrollHeight <= el.clientHeight + 1 && !el.hasAttribute('tabindex')), 'long description readable without nested scrolling');
        await page.locator('[name=date]').fill('2032-09-20'); await page.locator('[name=date]').dispatchEvent('change');
        await page.waitForFunction(() => !document.querySelector('[name=time]').disabled);
        check(calls.filter((c) => c.name === 'times').at(-1).input.package_id === String(fixture.ids[2]), 'time request uses selected calendar package');
        check(await page.locator('[name=time] option').last().textContent() === '1:30 PM', '12-hour time labels preserved');
        await page.locator('[name=time]').selectOption('09:00'); await page.waitForFunction(() => !document.querySelector('.brp-submit').disabled);
        check(calls.filter((c) => c.name === 'availability').at(-1).input.package_id === String(fixture.ids[2]), 'availability uses selected package');
        await page.locator('[name=quantity]').fill('3');
        await page.addScriptTag({ path: script });
        const before = calls.filter((c) => c.name === 'times').length;
        slowTimes = true;
        await button(0).click(); await button(3).click();
        await page.waitForFunction(() => !document.querySelector('[name=time]').disabled);
        await page.waitForTimeout(350);
        check(calls.filter((c) => c.name === 'times').length === before + 2, 'reinitialization does not duplicate selection handlers');
        check(await page.locator('[name=package_id]').inputValue() === String(fixture.ids[3]) && await page.locator('.brp-select[aria-pressed=true]').count() === 1, 'rapid package switching keeps latest selection');
        check(await page.locator('.brp-summary').innerText() === '' && await page.locator('.brp-submit').isDisabled(), 'package change clears stale review and submission');
        await page.locator('[name=time]').selectOption('09:00'); await page.waitForFunction(() => !document.querySelector('.brp-submit').disabled);
        await page.locator('.brp-submit').click(); await page.waitForURL('**/checkout');
        const holds = calls.filter((c) => c.name === 'holds'), transfers = calls.filter((c) => c.name === 'checkout');
        check(holds.length === 1 && holds[0].input.package_id === String(fixture.ids[3]) && holds[0].input.quantity === '3', 'one hold uses selected package and quantity');
        check(transfers.length === 1 && transfers[0].input.request_key === holds[0].input.request_key, 'unchanged checkout transfer reuses hold key');
        check(!calls.some((c) => c.name.includes('add-to-cart')), 'selection never uses direct Woo purchase');
        await load(false);
        await page.locator('.brp-restart').waitFor({ state: 'visible' });
        check(await page.locator('.brp-form').isHidden(), 'expired hold receipt still restores from guest history');
        await page.locator('.brp-restart').click();
        check(await page.locator('.brp-form').isVisible() && await page.locator('[name=package_id]').inputValue() === '', 'start over returns to unselected grid');
        check(await page.locator('.brp-details').isHidden() && await page.locator('.brp-select[aria-pressed=true]').count() === 0, 'restart clears selected semantics and hides booking controls');
        const setPrevious = () => page.evaluate(() => sessionStorage.setItem('brp-booking:http://127.0.0.1:33319/api//booking', 'removed-hold-fixture'));
        restoredStatus = 'cancelled'; await setPrevious(); await load(false);
        await page.waitForFunction(() => document.querySelector('.brp-status').textContent.includes('cancelled'));
        check(await page.locator('.brp-form').isVisible() && await page.locator('.brp-result').isHidden(), 'removed hold returns directly to product grid without old receipt');
        check(await page.evaluate(() => sessionStorage.getItem('brp-booking:http://127.0.0.1:33319/api//booking')) === null, 'removed hold browser pointer is cleared');
        check(await page.locator('.brp-details').isHidden() && await page.locator('[name=package_id]').inputValue() === '', 'cancelled receipt resets selection and booking controls');
        await button(0).click(); await page.locator('[name=date]').fill('2032-09-20'); await page.locator('[name=date]').dispatchEvent('change');
        await page.waitForFunction(() => !document.querySelector('[name=time]').disabled); await page.locator('[name=time]').selectOption('09:00');
        await page.waitForFunction(() => !document.querySelector('.brp-submit').disabled); await page.locator('.brp-submit').click(); await page.waitForURL('**/checkout');
        check(calls.filter((c) => c.name === 'holds').length === 2 && calls.filter((c) => c.name === 'checkout').length === 2, 'fresh booking and checkout work after removed hold recovery');
        restoredStatus = 'hold'; await load(false); await page.locator('.brp-result').waitFor({ state: 'visible' });
        check(await page.locator('.brp-form').isHidden(), 'navigation alone restores live hold normally');
        restoredStatus = 'cancelled';
        await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
        await page.locator('.brp-form').waitFor({ state: 'visible' });
        check(await page.locator('.brp-result').isHidden(), 'Back/Forward-cache restoration rechecks and clears cancelled hold');
        check(await page.evaluate(() => sessionStorage.getItem('brp-booking:http://127.0.0.1:33319/api//booking')) === null, 'Back/Forward recovery clears only stale booking pointer');
        await page.goto('http://127.0.0.1:33319/booking?rental=' + fixture.ids[2]); await page.evaluate(() => sessionStorage.clear()); await page.addScriptTag({ path: script });
        await page.waitForFunction(() => !document.querySelector('.brp-change-rental').disabled);
        check(await page.locator('.brp-card:visible').count() === 1 && await card(2).locator('.brp-description').isVisible(), 'deep-linked card shows its description');
        check(await page.locator('.brp-duration').count() === 0 && await card(2).locator('img').evaluate(el => getComputedStyle(el).objectFit === 'cover'), 'deep-linked card shares full-width crop and omits duration');
        await page.locator('.brp-change-rental').click();
        check(await page.locator('.brp-card:visible').count() === 4, 'Change Rental reveals all updated cards');
        catalog = [];
        await load();
        check(await page.locator('.brp-card:visible').count() === 0 && await page.locator('.brp-empty').isVisible(), 'fresh catalog hides stale server-rendered packages');
        catalogFailure = true;
        await load();
        check(await page.locator('.brp-select:enabled').count() === 0 && (await page.locator('.brp-status').innerText()).includes('temporarily unavailable'), 'catalog failure keeps selection disabled with safe error');
        check(errors.length === 0, 'no browser JavaScript errors');
        console.log(`Product grid browser: ${checks} checks passed.`);
    } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
