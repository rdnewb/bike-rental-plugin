# Milestone 6A UX — 0.6.1 product grid

Version **0.6.1**, schema **1**. Work is directly on `main`. This enhancement changes the public shortcode presentation and package-selection controls only. Payment policy, Full Payment/Deposit behavior, Square integration, reservations, availability, guest sessions, order linkage, and database schema are unchanged. Milestone 7 has not started.

## Card content and interaction

The existing `[bike_rental_booking]` shortcode server-renders active, published, valid rental packages. Each semantic article contains a product heading, WooCommerce featured attachment image, short description, formatted price, friendly duration, optional promotional label, and a real Select Rental button. There are no product-page or add-to-cart links.

- Images use `wp_get_attachment_image(..., 'woocommerce_thumbnail')`, attachment alt text, responsive `srcset`/`sizes`, lazy loading, and a restricted image-attribute allowlist. Missing/invalid image attachments render a same-size fallback.
- Descriptions use WooCommerce short descriptions, strip shortcodes, and allow only paragraph, break, emphasis, and list formatting. Long descriptions have a 9rem scrollable region with keyboard focus. Full product descriptions are not duplicated.
- Prices use the product's `get_price_html()` with safe markup; WooCommerce controls sale/tax presentation. No price is calculated in the card code or trusted from the browser.
- Duration labels use the package duration/type with singular/plural Hours/Days. The existing promotional metadata is escaped and omitted entirely when empty. No package content is hard-coded.
- A hidden `package_id` input replaces the visible select. Card selection updates it, clears stale review/availability, highlights exactly one card, reveals date/time/quantity/review, and focuses the start date. Existing request generation, hold, session, expiry, and checkout transfer remain intact.
- Native buttons support Tab, Enter, and Space. `aria-pressed` is the valid selected-state attribute for these toggle buttons; `aria-selected` is not applied to an incompatible button role. Text/checkmark, border emphasis, and a visible focus outline accompany the programmatic state. No H1 is introduced, and all instance heading IDs are unique.
- The existing public catalog request still verifies current eligibility and date bounds before enabling buttons. It is not an extra request for card presentation. Inactive cards from cached markup are hidden. If new packages are absent from cached markup, a refresh may be required. Server-side validation remains authoritative.

## Responsive and Divi behavior

All CSS is scoped to `.brp-booking`, with no dependency on Divi's DOM. Neutral system colors preserve the reusable plugin's no-brand-color convention. CSS Grid auto-fits within a 72rem wrapper: up to three columns where space allows, two in medium containers, and one on narrow screens. Image aspect ratios, flexible text wrapping, minimum 48px buttons, and contained descriptions prevent overflow. Normal Woo product styling is unaffected.

Shortcode HTML is available before JavaScript for crawling. JavaScript is still required to book. Later booking controls remain hidden until package selection, and buttons are disabled during catalog initialization or failure. Empty catalogs show a clear customer message. Clear booking-page/CDN caches after deployment or catalog changes.

## Automated verification

Disposable WordPress 7.0 / WooCommerce 11.1.0 / PHP 8.3.33 / MariaDB 11.4.8; local headless Chrome through Playwright for UI checks. No remote site changes, emails, or Square charges were performed.

| Suite | Passing checks |
|---|---:|
| Foundation and packages | 208 |
| Storage, editing, availability, Active timing | 407 |
| Real multiprocess concurrency | 59 |
| Calendar scheduling and duration matrix | 667 |
| Public booking REST integration | 74 |
| Missing WooCommerce / persistence | 10 |
| Checkout lifecycle — CPT + HPOS | 96 + 96 |
| Rental Store API — CPT + HPOS | 21 + 21 |
| Normal-product Store API — CPT + HPOS | 4 + 4 |
| New server-rendered product cards | 23 |
| New Chromium interaction/layout | 38 |
| **Total passing check executions** | **1,728** |

There are **1,607 distinct check cases**; 121 checkout cases run under both storage modes. Setup reruns and the historical deposit compatibility test are excluded. All **42 PHP files** pass syntax checks; both booking JavaScript and the browser test pass Node syntax checks. `git diff --check` passes.

The browser suite consumes real PHP-rendered card HTML and runs the shipped JavaScript/CSS. Existing REST responses are mocked for UI testing; separate PHP/Store API suites exercise real WordPress/Woo services. Browser assertions cover keyboard selection, hidden-field synchronization, selected state, changing packages, stale async response protection, one event handler per button, correct package IDs for times/availability/holds, quantity three, checkout transfer, restored expired holds, restart, empty/error catalogs, and no JavaScript errors.

Layouts were measured at **1280px (3 columns), 800px (2), 375px (1), and 320px (1)**, with no horizontal overflow and minimum 48px buttons. Desktop and mobile screenshots were visually inspected. Images in this test are synthetic fixtures; actual customer product photography was not assessed.

Run existing PHP tests with `BRP_ALLOW_DISPOSABLE_TESTS=1` and `BRP_TEST_WP_ROOT` pointing at the guarded disposable fixture. For the new pair:

```powershell
$env:BRP_GRID_FIXTURE_DIR = Join-Path (Get-Location) '.release/m61-tests'
# Use the existing disposable PHP binary/configuration:
php tests/product-grid.php
# Make Playwright available through the local Node runtime; Chrome is optional:
$env:BRP_BROWSER_CHANNEL = 'chrome'
node tests/product-grid-browser.cjs
```

The browser test intercepts all network requests; it never contacts the test site. PHP tests remove their synthetic products and attachment files. Browser output and screenshots remain ignored local artifacts.

## Remaining test-site acceptance and limitations

No SFTP deployment or authenticated Divi/test-site browser testing was performed. Real Square sandbox acceptance from 0.6.0 remains pending. The previously diagnosed active deposit-extension guard is deliberately unchanged: selecting a card will not bypass it.

After deployment:

1. Clear caches and verify 0.6.1 with schema 1, retaining existing settings and Full Payment mode.
2. Open the shortcode in its actual Divi 5 module. Check product images, attachment alt text, short descriptions, prices, promotions, and missing-image fallback.
3. Test desktop/tablet/mobile and a narrow Divi column. Verify reflow, no overflow, readable long content, visible keyboard focus, and easy tap targets.
4. Use Tab/Enter/Space to choose a rental, then switch to another. Verify only one selected state and refreshed availability for the new package.
5. Complete the hourly, 3-day, and 7-day booking flows through quantity, review, hold, and checkout. Verify AM/PM labels and the correct package/quantity in Woo checkout.
6. Check empty/inactive catalog behavior and hold refresh/expiry/restart. Confirm ordinary Woo products retain their normal behavior.
7. Once test-site payment configuration permits checkout, finish the existing Square sandbox acceptance separately. No claim of live or sandbox payment success is made by these UI tests.

Theme styling and installed third-party image/price filters may affect actual rendering; local neutral-page tests cannot substitute for the real Divi page. Cached card content is presentation only, and stale prices/invalid packages continue to be rejected by existing server validation.

## Files, release, and source control

Changed runtime: `bike-rental-plugin.php`, `src/Plugin.php` (version only), `src/PublicBooking.php` (shortcode rendering only), `src/booking-form.php`, `assets/css/booking.css`, `assets/js/booking.js`, and `readme.txt`, all under the inner plugin folder.

Changed documentation: `README.md`, `CHANGELOG.md`, and this report. Tests: version assertions in `tests/foundation.php`; new `tests/product-grid.php` and `tests/product-grid-browser.cjs`. No unrelated files are included.

Commit message: `feat: replace rental dropdown with responsive product grid in 0.6.1`. Commit directly to `main`; no branch and no push. Obtain the actual commit hash from the completion report or Git history.

Upload the repository's **inner `bike-rental-plugin/` folder** to **`wp-content/plugins/bike-rental-plugin/`**. The main file must be `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`. Do not upload the repository root, tests, reports, fixtures, or `.git`.

Local release archive: `.release/milestone6a/bike-rental-plugin-0.6.1.zip`, containing only the inner plugin folder. All 32 archived file hashes match the runtime files. Archive SHA-256: `F39DFF120F129BDEC3EA4A5B776A658ADE3915B41291DF3C8A5EFAC902D51774`. The archive is an ignored local artifact and has not been uploaded.
