/* Local Chromium tests: real PHP-rendered views + shipped CSS/JS, mocked booking transport. */
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const dir = process.env.BRP_M7_FIXTURE_DIR;
if (!dir) throw new Error('Run milestone7a.php with BRP_M7_FIXTURE_DIR first');
const read = (name) => fs.readFileSync(path.join(dir, name), 'utf8');
const packages = JSON.parse(read('packages.json'));
const base = 'http://127.0.0.1:33319';
const runtime = path.join(__dirname, '../bike-rental-plugin');
const css = fs.readFileSync(path.join(runtime, 'assets/css/booking.css'), 'utf8');
const calendarCss = fs.readFileSync(path.join(runtime, 'assets/css/calendar.css'), 'utf8');
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; console.log('PASS:', label); };
(async () => {
    const browser = await chromium.launch({ headless: true, channel: process.env.BRP_BROWSER_CHANNEL || 'chrome' });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const calls = [], errors = [];
        let catalog = packages, restored = false;
        page.on('pageerror', (e) => errors.push(e.message));
        await page.route('**/*', async (route) => {
            const req = route.request(), url = new URL(req.url());
            if (url.pathname.startsWith('/api/')) {
                const name = url.pathname.split('/').pop();
                const input = req.method() === 'POST' ? req.postDataJSON() : Object.fromEntries(url.searchParams);
                calls.push({ name, input });
                const item = packages.find((p) => String(p.product_id) === input.package_id) || packages[0];
                let data;
                if (name === 'packages') data = { packages: catalog, min_date: '2032-03-01', max_date: '2032-12-01' };
                if (name === 'times') data = { times: [{ time: '09:00' }, { time: '13:30' }], message: 'Choose a start time.' };
                if (name === 'availability') data = { available_quantity: 3, rental_start: '2032-03-08T09:00', rental_end: '2032-03-10T17:00', timezone: 'America/New_York', package: item, message: '3 bikes available.' };
                if (name === 'session') data = { token: 'local-only-token' };
                if (name === 'holds' || name === 'hold-status') data = { reserved: true, reservation_status: 'hold', reference: name === 'hold-status' ? 'BRP-PRIOR-HOLD' : 'BRP-NEW-HOLD', package: item, quantity: 2, rental_start: '2032-03-08T09:00', rental_end: '2032-03-10T17:00', timezone: 'America/New_York', expires_at: new Date(Date.now() + 900000).toISOString(), server_time: new Date().toISOString(), message: 'Your bikes are temporarily reserved.' };
                if (name === 'checkout') data = { checkout_url: base + '/checkout' };
                return route.fulfill({ json: data });
            }
            if (req.resourceType() === 'stylesheet' || req.resourceType() === 'image') return route.fulfill({ body: '' });
            if (url.pathname === '/checkout') return route.fulfill({ body: '<p>Checkout destination fixture</p>', contentType: 'text/html' });
            const admin = url.pathname === '/calendar';
            const markup = read(admin ? 'calendar.html' : url.searchParams.get('rental') === 'invalid' ? 'invalid-link.html' : 'deep-link.html').replace(/data-api="[^"]+"/, `data-api="${base}/api/"`);
            return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px system-ui;margin:20px;background:#f0f0f1;color:#1d2327}.button{display:inline-block;padding:8px;border:1px solid #2271b1}a{color:#135e96}' + (admin ? calendarCss : css) + '</style></head><body>' + markup + '</body></html>' });
        });
        const load = async (value = packages[1].slug) => {
            await page.goto(base + '/reserve/?rental=' + value);
            await page.evaluate((restore) => {
                sessionStorage.clear();
                if (restore) sessionStorage.setItem('brp-booking:http://127.0.0.1:33319/api//reserve/', 'previous-owned-key');
            }, restored);
            await page.addScriptTag({ path: path.join(runtime, 'assets/js/booking.js') });
            await page.waitForFunction(() => !document.querySelector('.brp-status').textContent.includes('Loading'));
        };
        await load();
        check(await page.locator('.brp-card:visible').count() === 1, 'deep link initially shows one card');
        check(await page.locator('[name=package_id]').inputValue() === String(packages[1].product_id), 'URL package remains selected after live catalog check');
        check(await page.locator('.brp-select[aria-pressed=true]').count() === 1, 'one accessible selected card');
        check(await page.locator('.brp-details').isVisible() && await page.locator('[name=date]').isEnabled(), 'date/time/quantity controls revealed');
        check(await page.locator('.brp-booking').getAttribute('data-selection-source') === 'url', 'URL analytics attribute');
        for (const width of [1280, 800, 375]) {
            await page.setViewportSize({ width, height: 1000 });
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `deep link ${width}px no overflow`);
        }
        await page.screenshot({ path: path.join(dir, 'deep-link-375.png'), fullPage: true });
        await page.locator('.brp-change-rental').focus(); await page.keyboard.press('Enter');
        check(await page.locator('.brp-card:visible').count() === 3, 'Change Rental reveals full validated grid');
        check(await page.locator('.brp-change-rental').getAttribute('aria-expanded') === 'true', 'expanded state exposed');
        check(await page.locator('.brp-card:visible .brp-select').first().evaluate((e) => e === document.activeElement), 'change action moves focus into grid');
        check(await page.locator('.brp-booking').getAttribute('data-change-rental-clicked') === 'true', 'change analytics attribute');
        const other = page.locator(`[data-package-id="${packages[2].product_id}"] .brp-select`);
        await other.focus(); await page.keyboard.press('Space');
        check(await page.locator('[name=package_id]').inputValue() === String(packages[2].product_id), 'manual selection replaces URL package');
        check(new URL(page.url()).searchParams.get('rental') === packages[2].slug, 'History API updates slug without reload');
        check(await page.locator('.brp-booking').getAttribute('data-selection-source') === 'manual', 'manual analytics attribute');
        await page.locator('[name=date]').fill('2032-03-08'); await page.locator('[name=date]').dispatchEvent('change');
        await page.waitForFunction(() => !document.querySelector('[name=time]').disabled);
        check(calls.filter((c) => c.name === 'times').at(-1).input.package_id === String(packages[2].product_id), 'start times use new package');
        check(await page.locator('[name=time] option').last().textContent() === '1:30 PM', '12-hour dropdown retained');
        await page.locator('[name=time]').selectOption('09:00'); await page.waitForFunction(() => !document.querySelector('.brp-submit').disabled);
        check(calls.filter((c) => c.name === 'availability').at(-1).input.package_id === String(packages[2].product_id), 'availability uses new package');
        await page.locator('[name=quantity]').fill('2'); await page.locator('.brp-submit').click(); await page.waitForURL('**/checkout');
        const hold = calls.filter((c) => c.name === 'holds').at(-1), transfer = calls.filter((c) => c.name === 'checkout').at(-1);
        check(hold.input.package_id === String(packages[2].product_id) && hold.input.quantity === '2', 'hold uses correct selection');
        check(transfer.input.request_key === hold.input.request_key, 'checkout transfer preserves hold identity');
        await load('invalid');
        check(await page.locator('.brp-card:visible').count() === 3 && await page.locator('[name=package_id]').inputValue() === '', 'invalid URL normal grid');
        check(!await page.locator('.brp-details').isVisible(), 'invalid link requires normal selection');
        await load(packages[0].slug);
        check(await page.locator('[name=package_id]').inputValue() === '' && await page.locator('.brp-card:visible').count() === 3, 'cache that ignores query strings cannot silently select the wrong rental');
        catalog = [packages[0], packages[2]]; await load();
        check(await page.locator('.brp-card:visible').count() === 2 && await page.locator('[name=package_id]').inputValue() === '', 'stale cached deep link fails safely when catalog no longer includes it');
        check(!await page.locator('.brp-details').isVisible(), 'stale preselection cannot enable booking');
        catalog = packages; restored = true; await load();
        await page.waitForFunction(() => document.querySelector('.brp-receipt').textContent.includes('BRP-PRIOR-HOLD'));
        check(await page.locator('.brp-result').isVisible() && !await page.locator('form').isVisible(), 'owned existing hold restoration takes precedence');
        check(calls.filter((c) => c.name === 'hold-status').at(-1).input.request_key === 'previous-owned-key', 'preselection does not erase recoverable hold');
        await page.goto(base + '/calendar');
        for (const width of [1440, 1024, 768, 375]) {
            await page.setViewportSize({ width, height: 1000 });
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `admin ${width}px page contains calendar overflow`);
            check(await page.locator('.brp-calendar-timeline').evaluate((e) => e.getBoundingClientRect().width >= 1260), `admin ${width}px keeps readable columns`);
        }
        check(await page.locator('.brp-calendar-scroll').evaluate((e) => e.scrollWidth > e.clientWidth && getComputedStyle(e).overflowX === 'auto'), 'narrow calendar scrolls within its container');
        await page.setViewportSize({ width: 1440, height: 1000 });
        check(await page.locator('.brp-calendar-bar').count() === 12, 'one timeline bar per reservation / block');
        await page.locator('.brp-calendar-label details').first().locator('summary').focus(); await page.keyboard.press('Enter');
        check(await page.locator('.brp-calendar-label details').first().getAttribute('open') !== null, 'native details keyboard operable');
        check(await page.locator('.brp-calendar-bar').first().getAttribute('aria-label') !== '', 'timeline bars have descriptive accessible names');
        check(await page.locator('.brp-calendar-filter [name=status] option').count() === 8, 'all status filter options present');
        await page.screenshot({ path: path.join(dir, 'calendar-1440.png'), fullPage: true });
        await page.setViewportSize({ width: 768, height: 1000 });
        await page.screenshot({ path: path.join(dir, 'calendar-768.png'), fullPage: true });
        check(errors.length === 0, 'no browser JavaScript exceptions');
        console.log(`Milestone 7A browser: ${checks} checks passed.`);
    } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
