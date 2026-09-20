/* Native WordPress admin styles, PHP-rendered panels, navigation without JavaScript. */
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const dir = process.env.BRP_TABS_FIXTURE_DIR, wp = process.env.BRP_TEST_WP_ROOT;
if (!dir || !wp) throw new Error('Run settings-tabs.php with BRP_TABS_FIXTURE_DIR and set BRP_TEST_WP_ROOT first');
const css = ['wp-includes/css/dashicons.css', 'wp-includes/css/buttons.css', 'wp-admin/css/common.css', 'wp-admin/css/forms.css'].map(file => fs.readFileSync(path.join(wp, file), 'utf8')).join('\n');
let checks = 0;
const check = (ok, label) => { assert.ok(ok, label); checks++; console.log('PASS:', label); };
(async () => {
    const browser = await chromium.launch({ headless: true, channel: process.env.BRP_BROWSER_CHANNEL || 'chrome' });
    try {
        const page = await browser.newPage({ javaScriptEnabled: false });
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.pathname !== '/wp-admin/admin.php') return route.abort();
            const tab = url.searchParams.get('tab') === 'branding' ? 'branding' : 'general';
            return route.fulfill({ contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>' + css + '</style><body class="wp-admin wp-core-ui">' + fs.readFileSync(path.join(dir, `tabs-${tab}.html`), 'utf8') + '</body>' });
        });
        for (const width of [1280, 782, 375]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('http://127.0.0.1:33319/wp-admin/admin.php?page=brp-settings');
            check(await page.locator('.nav-tab-active').textContent() === 'General', `${width}: General default`);
            check(await page.locator('[name="brp_settings[business_name]"]').count() === 1 && await page.locator('[name="brp_settings[branding][heading]"]').count() === 0, `${width}: General panel isolation`);
            const tabs = await page.locator('.nav-tab').all();
            for (const tab of tabs) { const box = await tab.boundingBox(); check(box.x >= 0 && box.x + box.width <= width, `${width}: tab link fits viewport`); }
            await page.getByRole('link', { name: 'Booking Form Branding', exact: true }).focus();
            await page.keyboard.press('Enter');
            await page.waitForURL('**tab=branding');
            check(await page.locator('.nav-tab-active').textContent() === 'Booking Form Branding', `${width}: keyboard navigation works without JavaScript`);
            check(await page.locator('[name="brp_settings[business_name]"]').count() === 0 && await page.locator('[name="brp_settings[branding][heading]"]').count() === 1, `${width}: Branding panel isolation`);
            check(await page.locator('form').getAttribute('action').then(value => value.endsWith('/wp-admin/options.php')), `${width}: native POST form retained`);
            await page.screenshot({ path: path.join(dir, `settings-tabs-${width}.png`), fullPage: true });
        }
        console.log(`Settings tabs browser: ${checks} checks passed.`);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
