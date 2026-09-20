# Rental-card presentation — 0.7.3

Plugin **0.7.3**, schema **1**. This release changes the public card template and scoped card styles only, plus versioning, tests and documentation. Booking, duration metadata, availability, reservations, payments/deposits, deep-link logic and the admin calendar are unchanged. No database migration. Waiver integration has not started.

## Description source and rendering

The prior template already called WooCommerce `WC_Product::get_short_description()` and rendered it in the shortcode HTML. An empty short description intentionally produced no description wrapper; the main/long product description was never a fallback. Local inspection does not establish whether the live products have populated excerpts or stale cached pages. Use **Products > Edit product > Product short description**, save the product, and refresh/clear the booking-page cache to verify actual site content.

The card now displays image, optional existing promotion, semantic product title, short description, WooCommerce formatted price and selection button. The description remains server-rendered once, between title and price, without an extra request or JavaScript-only content. Safe paragraphs, line breaks, emphasis, unordered/ordered lists and list items are retained. Registered shortcodes are stripped, not executed. Script, style and embedded object/frame blocks are removed with their contents; `wp_kses()` then removes unsupported tags and attributes. Empty or unsafe-only excerpts omit the wrapper. The long product description is not duplicated.

Descriptions grow naturally instead of being limited to a 9rem scroll region. The redundant focusable scroll-region wrapper is removed; ordinary semantic text/list content remains available to assistive technology. Grid cards align at their tops and retain their own content height, avoiding artificial blank space inside shorter cards. Very long excerpts naturally make taller cards and a longer page; keep customer-facing excerpts concise when practical.

## Duration and image changes

The automatic `.brp-duration` Hours/Days paragraph and its display-only formatting are removed. A duration typed into the product title remains visible. `duration_type`, `duration_amount`, admin controls, public catalog data, scheduling, availability and reservation snapshots are untouched.

The image's previous `object-fit: contain` caused letterboxing for images whose proportions differed from the 4:3 wrapper. The wrapper now explicitly uses full available card width and the existing responsive **4:3** aspect ratio. The image uses `display: block`, 100% width/height, centered `object-fit: cover`, so it fills the area without stretching. This intentionally crops image edges when needed; it cannot remove whitespace baked into an uploaded photo. Review actual product photos for suitable framing.

`wp_get_attachment_image(..., 'woocommerce_thumbnail')` remains unchanged, preserving responsive `srcset`/`sizes`, appropriate generated image sizes, lazy loading, decoding and attachment alt text. No forced full-resolution download or new image request mechanism is introduced. The existing card clips content to its configurable radius, including both top image corners. Missing/invalid images retain the full-width “Rental photo coming soon” fallback without a broken image.

All styles remain scoped to `.brp-booking`. Existing background/border colors, radius, selected highlight, button colors, accent, scoped custom CSS and theme font inheritance are retained. Custom CSS can still intentionally override the default card presentation. Normal and deep-linked cards use the same template. Selection buttons retain keyboard access, focus outlines, `aria-pressed` and selected text/checkmark; Change Rental logic is unchanged.

## Automated verification

Disposable local WordPress 7.0 / WooCommerce 11.1.0 / PHP 8.3.33 / MariaDB 11.4.8, with local Chrome/Playwright browser checks. **2,339 passing check executions / 2,081 distinct checks**; 258 checks repeat under both CPT and HPOS. All **52 PHP files** pass syntax validation. Booking JavaScript and the two updated browser suites pass Node syntax validation. `git diff --check` passes.

| Suite | Passing checks |
| --- | ---: |
| Foundation and packages | 208 |
| Storage, editing, availability and active policy | 407 |
| Calendar scheduling and generic duration matrix | 667 |
| Public booking integration | 74 |
| Multiprocess inventory concurrency | 70 |
| Checkout, CPT + HPOS | 192 |
| Rental Store API, CPT + HPOS | 42 |
| Ordinary Store API checkout, CPT + HPOS | 8 |
| Cart hold cleanup, CPT + HPOS | 94 |
| Updated server-rendered product-card tests | 37 |
| Updated product-card browser tests | 76 |
| Missing WooCommerce / persistence | 13 |
| Deep links and calendar, CPT + HPOS | 180 |
| Deep links and calendar browser | 41 |
| Branding integration | 80 |
| Updated branding browser tests | 44 |
| Settings tabs integration + browser | 85 + 21 |

The card tests cover short-description rendering and source, safe/unsafe markup, empty/unsafe-only descriptions, content order, paragraph formatting, absence of standalone hourly/calendar duration lines, preserved duration metadata, responsive images/alt text, fallback markup, and deep-linked presentation. Scheduling/availability suites exercise hourly packages and calendar durations 1–7 on every weekday, including 3-day and 7-day holds and occupied intervals.

Browser tests use real PHP-rendered cards and shipped CSS/JavaScript, with local mocked booking transport. At **1280, 800, 375 and 320 pixels**, they check column counts, no horizontal overflow, at least 48px buttons, full-width image/fallback geometry, centered 4:3 cover cropping with a non-4:3 source image, natural description wrapping, shorter-card height, rounded clipping and readable prices. Existing keyboard selection, state, stale-response, hold recovery and checkout handoff checks remain. Added deep-link and branded-image checks verify shared presentation and Change Rental. Desktop and mobile screenshots were visually inspected; photos in the local browser fixture are synthetic, not customer photography.

Run guarded PHP suites with `BRP_ALLOW_DISPOSABLE_TESTS=1` and `BRP_TEST_WP_ROOT` pointing to the disposable fixture. Set `BRP_GRID_FIXTURE_DIR` for `tests/product-grid.php` to export cards for `tests/product-grid-browser.cjs`; the export includes the new `cards-deep.html`. Branding suites use `BRP_BRANDING_FIXTURE_DIR`. Browser scripts can use `BRP_BROWSER_CHANNEL=chrome`. Temporary fixtures, logs and screenshots are ignored local artifacts.

No remote deployment, customer-data changes or real Square charges were performed. Payment tests use gateway/evidence doubles; existing dedicated-site Square sandbox acceptance is still pending. The historical unsupported deposit-provider preflight is excluded from the passing Full Payment regression matrix.

## Manual browser acceptance still required

1. Upload the complete plugin folder, confirm version 0.7.3/schema 1, and clear relevant page/CDN caches.
2. Edit a rental product's **Product short description** with paragraphs, emphasis and a short list. Confirm image → title → description → price → selection button, with no automatic duration line. Any duration in the product title should remain.
3. Check products with empty excerpts, a long description only, and no featured image. Empty excerpts should show no blank description area; missing photos should show the existing full-width fallback.
4. Check actual landscape and portrait product photos. Confirm edge-to-edge image fill inside the border, reasonable centered crop, no stretching, and correctly rounded top corners. Check existing branding colors, radius and custom CSS under Divi.
5. Inspect at 1280, 800, 375 and 320 pixels. Confirm readable/wrapping text and prices, no page overflow, visible focus and easy-to-tap buttons. Long excerpts should be fully readable through normal page scrolling.
6. Open `/reserve/?rental=3-day-rental` using the site's actual product slug. Confirm one selected card with updated presentation, then use Change Rental and keyboard selection.
7. Smoke-test hourly, 3-day and 7-day selections, Full Payment checkout and the weekly calendar using the dedicated test site's existing test procedures. Their logic is unchanged.

## Changed files

- Runtime: `bike-rental-plugin/src/booking-form.php`, `bike-rental-plugin/assets/css/booking.css`, `bike-rental-plugin/bike-rental-plugin.php`, `bike-rental-plugin/src/Plugin.php`, `bike-rental-plugin/readme.txt`.
- Tests: `tests/product-grid.php`, `tests/product-grid-browser.cjs`, `tests/booking-branding.php`, `tests/booking-branding-browser.cjs`, `tests/foundation.php`.
- Documentation: `README.md`, `CHANGELOG.md`, `docs/product-grid-verification.md`, and this file.

## Release and SFTP

Release ZIP: `.release/milestone7a/bike-rental-plugin-0.7.3.zip`. Only the complete `bike-rental-plugin/` runtime folder is included, with no tests or local fixtures.

All **40 runtime files** were verified against source by SHA-256. ZIP SHA-256: `032B80AFB684FEC663334BD2A63B8ECB44746FA080697472FB78BA816CB71F6C`.

Upload the contents of:

`C:\Users\RandyNewby\Documents\GitHub\bike-rental-plugin\bike-rental-plugin`

to:

`wp-content/plugins/bike-rental-plugin/`

The main file must end at `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`. No reactivation or schema change is required.

Commit on `main`: `feat: refine rental product card content and images in 0.7.3`. No push or deployment is part of this task.
