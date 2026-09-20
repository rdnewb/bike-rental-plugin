# Milestone 7A — dedicated booking links (0.7.0)

## Site setup / Divi buttons

1. Create one dedicated WordPress booking page, for example `/reserve/`.
2. Place `[bike_rental_booking]` once on that page using the existing Divi shortcode/text module.
3. Find the published WooCommerce Simple rental product's slug in its permalink settings. It must have the rental flag enabled, Active enabled, a valid configured duration, and a valid selling price.
4. Set a Divi button's Link URL to `/reserve/?rental=3-day-rental` (substitute the actual page and product slug). No source-page JavaScript is needed.
5. Keep the canonical URL set to the plain booking page permalink. WordPress's normal singular-page canonical uses the permalink; this plugin adds no canonical tags, page titles, H1s, pages, or indexable package landing pages. Verify any SEO plugin's canonical configuration independently.
6. Exclude the booking page from full-page caching, or configure the cache to vary by the `rental` query parameter. Keep existing REST/session/checkout cache exclusions. Purge old CSS/JS after upload.

Preferred format: `?rental=product-slug`. Optional fallback: `?rental=123` for a WooCommerce product ID. Exact matching product slugs take precedence, even when numeric. Leading-zero IDs, arrays, overlong strings, invalid characters, missing products, inactive/non-rental/draft/unsupported products and invalid package metadata do not select anything. No reservation ID is accepted as a booking identifier.

## Behavior

The shortcode resolves against the server-validated active catalog, displays only the selected card, marks it Selected / `aria-pressed=true`, and reveals booking controls. Live catalog loading enables the date input. All schedule, availability, hold, session and checkout validation still runs on the server.

**Change Rental** reveals the full active grid and moves keyboard focus to its first available selection button. It retains the current selection until another card is chosen. Enter/Space selects a replacement, clears stale availability/review state, and uses its ID for subsequent times/availability/hold calls. The History API replaces the `rental` value with its slug without reloading or adding a history entry; failure to synchronize the URL does not block booking.

Invalid links show the ordinary grid, with no technical error or 404. A package removed between page rendering and live catalog loading is deselected. If a cache serves another query variant, the browser rejects the mismatched preselection and offers normal selection; configure cache variation for full preselection behavior.

An owned existing hold restored from session storage takes precedence over a new URL selection. Preselection must not erase its request key, create another hold, or alter checkout. Cancelled/expired receipt recovery keeps the existing cleanup behavior. Availability and checkout remain protected, and direct Woo rental purchases remain blocked.

Stable future analytics hooks (no analytics scripts added): `.brp-change-rental`, `.brp-select`, card `data-package-id` / `data-package-slug`, root `data-selection-source="url|manual|none"`, `data-change-rental-clicked="true"`, and `data-filtered`.

## Automated verification

Release verification passed **2,064 executions / 1,806 distinct checks**, including **90 new PHP checks in each Woo order-storage mode** and **41 new browser checks** shared across this milestone's two features. See the [full verification matrix](admin-calendar-verification.md#local-release-verification--september-20-2026).

- `tests/milestone7a.php`: real WordPress/WooCommerce/MariaDB tests covering slug/ID resolution, numeric-slug precedence, malformed and ineligible input, SSR selected/filtered/accessible state, revealed controls, and absence of extra H1/title/canonical markup; also includes calendar coverage. Run under CPT and HPOS.
- `tests/milestone7a-browser.cjs`: local Chrome tests using real PHP-rendered markup and shipped CSS/JS with mocked REST transport. Tests Change Rental, keyboard focus, URL state, request package IDs, checkout handoff, stale catalog/cache fallback, preserved existing holds, and responsive layouts; also includes calendar coverage.
- Existing product grid, hourly/calendar-day schedule matrix, public booking, checkout/Store API, cart cleanup, payment-evidence and concurrent inventory suites remain regression gates. No live Square transaction is represented by these browser mocks.

Run integration tests only in the guarded disposable database described in the existing verification reports. Set `BRP_M7_FIXTURE_DIR` to an ignored local output directory to export markup and package fixtures; use the same variable for the browser suite. Browser fixtures and screenshots are excluded from the release ZIP and Git.

## Dedicated-site acceptance still required

After uploading the complete release directory:

1. Open a valid 3-day rental link in a fresh browser session. Confirm exactly that card is selected, controls are visible, start times display AM/PM, and the chosen date/quantity proceed through the existing checkout flow.
2. Click Change Rental using both mouse and keyboard. Choose hourly and 7-day packages; confirm date/time availability refreshes, review uses the new package, the URL updates, and checkout contains only the selected rental with its correct quantity.
3. Open missing, inactive and ordinary-product links. Confirm the normal grid and no error page. Repeat without a query parameter.
4. Follow an actual Divi homepage button. Verify the correct card on desktop and mobile, including cached and uncached visits. Inspect the rendered canonical: it must be the plain dedicated page URL.
5. Restore a live hold, remove its Woo cart item, and revisit/back-navigate to the booking page. Confirm existing restoration/cleanup behavior, no duplicate hold and no stale checkout.
6. Retain the established Square sandbox acceptance checklist. This milestone does not enable a previously blocked payment configuration.

Limitations: one shortcode instance on the dedicated production page; JavaScript required for booking; no theme-specific Divi styling changes; no analytics installation; no new SEO landing pages; test-site browser/Divi/Square acceptance is pending deployment and authenticated access.

Upload the inner `bike-rental-plugin/` folder to `wp-content/plugins/bike-rental-plugin/`. Runtime is 0.7.0; database schema remains 1. Payment code and deposit architecture are unchanged. Waiver integration has not started.
