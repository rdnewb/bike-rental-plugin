# Bike Rental Plugin 1.1.0 — booking flow and release verification

Runtime **1.1.0**, schema **2**. Work is on `main`; no branch, push or live deployment.

## Architecture and behavior

- **Billing:** WooCommerce checkout remains authoritative, including guest billing, email and phone. The plugin neither duplicates billing nor overwrites Woo billing/shipping. A store may still display shipping fields according to its Woo configuration.
- **Service address:** `RentalLocation` collects name, optional company/property, address lines, city, state/province, postcode, country and optional multiline notes. Country-dependent requirements use `WC_Countries::get_address_fields()` and `get_states()`. Country codes are checked against Woo's catalog; postcode formatting/validation uses `wc_format_postcode()` and `WC_Validation::is_postcode()`. Required fields and lengths are enforced server-side. WordPress sanitizes text and multiline notes; displays escape stored content. No geography is hard-coded.
- **Reservation storage:** structured `rental_location` data is added to the existing reservation snapshot. No column/table change or migration is needed. The location participates in the idempotency intent hash and existing checkout fingerprint, survives package replacements and ordinary admin edits, and cannot be replaced through submitted snapshot JSON. Older reservations without location remain readable and checkout-eligible under existing rules.
- **Order storage:** Checkout copies the structured address to `_brp_rental_location` using Woo CRUD. The existing `_brp_snapshot` also retains agreed terms. Billing/shipping are not repurposed. Order metadata retains the checkout snapshot; subsequent reservation edits do not silently rewrite the order.
- **Display and privacy:** reservation detail, Woo order administration, authorized thank-you/account order views, the owned hold receipt, and HTML/plain-text order emails show a separate Drop Off / Pick Up Location heading and notes. Guest confirmation checks the order key; account views check ownership; staff views require management capability. Location is absent from public package/availability responses and is not logged or put in browser storage.
- **Retention:** existing guarded cleanup removes location with abandoned unprotected rider data after 30 terminal days, preserving reservation audit fields and incrementing revision when the snapshot changes. Order-linked and protected waiver evidence remain excluded. No new deletion policy is introduced.
- **Fixed start:** `BookingSchedule::for_date()` derives the selected weekday's configured opening time in the WordPress timezone and calls the existing calculator. No new start setting is needed. Opening, closing and pickup settings use explicit AM/PM labels and retain saved minute values. Closed starts, insufficient notice, horizon violations and ambiguous/nonexistent local datetimes remain rejected. After today's opening passes, customers cannot select a later start that day.
- **End and occupancy:** hourly packages retain elapsed duration and end-hours validation. Calendar-day end remains start date + (N - 1) at Calendar-Day Pickup Time. Intermediate/final days may be closed for new starts. Buffers affect inventory occupancy, not the displayed schedule. Half-open intervals, blocks, fleet limits, locking and final allocation checks remain intact.
- **Public contract:** availability accepts package/date; holds accept package/date/quantity/riders/rental_location/request_key. REST rejects a client-supplied time. Internal time calculations, protected diagnostics and the legacy read-only `times` lookup remain; the form never calls it and cannot use it to select another time. Refresh old open forms after deployment.
- **Disclaimer:** General includes Drop Off / Pick Up Time Disclaimer, blank by default. Safe HTML supports paragraphs, emphasis, lists and links. A bordered region appears immediately after a server-validated date, before remaining fields; date reset/error hides it. Blank content creates no box. It uses system contrast colors, branding accent/radius and responsive layout. Customized introductions remain intact; only the exact previous stock introduction loses time-selection wording.
- **Refund policy:** `BookingContent::policy()` uses `wc_get_page_id('refund_returns')` and `get_post()`. Only a published, non-password-protected page is displayed. It does not guess from title/slug, substitute Terms/Privacy, create another editor or show Woo's unpublished sample. `strip_shortcodes()`, `wpautop()` and an explicit `wp_kses()` allowlist preserve static formatting without executing scripts, shortcodes or dynamic blocks. A heading and keyboard-focusable scrollable panel appear immediately above Reserve Bikes. Missing/empty/unpublished/protected content is omitted. There is no consent checkbox or assertion of explicit acceptance.

Verified locally with WooCommerce **11.1.0**, WordPress **7.0**, PHP **8.3.33**, MariaDB **11.4.8** and Chrome. This Woo installer's source creates `refund_returns` using the standard page-ID setting. APIs were checked against the official [page API](https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-page-functions.html) and [country/address API](https://woocommerce.github.io/code-reference/classes/WC-Countries.html). No signed-in live browser was available; the live site's installed version and published policy were not independently verified this turn.

## Automated results

**2,573 distinct PHP checks passed.** Nested foundation/storage/calendar runs are counted once. Checkout's 124 cases also passed under the other Woo storage mode (CPT and HPOS): **2,697 including that repetition**.

| Suite/group | Distinct checks |
| --- | ---: |
| Foundation/packages | 209 |
| Settings tabs | 91 |
| Public booking | 75 |
| Calendar scheduling/duration matrix | 667 |
| Storage/editing/availability/active policy | 425 |
| Checkout lifecycle | 96 |
| Complete Store API checkout and billing/location separation | 28 |
| Cart holds | 47 |
| Waivers/refinements/retention | 345 |
| Real concurrent allocation | 70 |
| Calendar administration/deep links | 93 |
| Cross-repository licensing | 56 |
| Enforcement configuration | 12 |
| New booking flow | 68 |
| Product cards | 37 |
| Branding | 81 |
| Production administration cleanup | 35 |
| Availability administration UI | 86 |
| Menu placement, excluding repeated foundation | 52 |

**258 browser checks passed:** 53 new booking-flow cases across desktop/tablet/mobile, 80 product-card/recovery/race cases, 48 branding, 41 calendar/deep-link and 36 rider-collection cases. They use real PHP markup and shipped CSS/JS with mocked REST. Checks cover keyboard navigation, country-dependent fields, invalid-date reset, stale responses, address/rider payloads, receipt/checkout navigation and horizontal overflow. Screenshots were visually reviewed.

All **73 PHP** and **13 JavaScript** runtime/test files passed syntax checks; diff whitespace checks passed. Ordinary inventory fixtures explicitly disable licensing after disposable-database guards; licensing integration uses normal enforcement. Archived unsupported deposit compatibility is outside scope. Local tests do not establish live Square payments, WPForms signatures or email delivery.

## Packaging and deployment

Build: `python tools/package.py`. ZIP: `.release/production/bike-rental-plugin-1.1.0.zip`. The packager allowlists production paths and byte-compares every entry. The ZIP contains 50 runtime files. SHA256: `ab0ef418dae3e3c0a89471c4a3693e246fe77763e1ba471134af4543c5818ebe`. Only the inner plugin runtime ships; tests, fixtures, dependencies, reports, NT License Controller and development artifacts are excluded. No obsolete runtime files were removed by this release.

SFTP: **`/wp-content/plugins/bike-rental-plugin/`**, relative to the rental site's WordPress root. Upload the inner plugin folder's contents, not the repository. No controller update is required. Clear booking-page/asset caches and refresh old forms because the request payload changed. Existing records persist; no migration runs.

Commit message: `feat: add rental location and fixed-time booking flow in 1.1.0`. The actual hash is reported in the completion message and Git history. No push or deployment was performed.

## Required live-site QA

1. **Reserve:** select normally and through a package deep link; choose a valid date; verify no time selector and prominent disclaimer. Check an unavailable date prevents submission. Enter address/notes/riders, read the actual policy immediately above Reserve Bikes and submit. Retry must not duplicate the reservation.
2. **Checkout:** verify guest billing and any configured Woo shipping flow. Test Square sandbox capture and decline/retry, cart removal/recovery and bounded holds.
3. **Order/confirmation/email:** billing must remain correct; service location must appear separately in staff and authorized customer views and both email formats. Check actual delivery. Incorrect order keys/unrelated customers must not reveal the location.
4. **Reservation administration:** open new and older records. Verify formatted address, revision-safe edits, package replacements and unchanged order links. Reservation edits must not rewrite historical order details.
5. **Waivers:** complete required adult/guardian WPForms signing, invitation delivery and payment/waiver readiness. Provider signing code is unchanged.
6. **Availability:** verify configured opening time/local-UTC conversion, inclusive three-day pickup, buffers, blocks, last-bike concurrency and calendar intervals. Verify license rejection/grace and existing-record access.
7. **Mobile/tablet/desktop:** check the actual Divi/theme's inputs, notice contrast/prominence, long-policy scrolling, keyboard flow, readable errors and Reserve Bikes visibility.

## Limits and preserved behavior

No geocoding, deliverability guarantee, delivery-zone restrictions, location editor, consent checkbox, duplicate billing, deposit integration or provider changes were added. Builder shortcodes/dynamic blocks are not executed in the policy. Publish/edit Woo's existing refund page and verify its configured page ID; no alternate page is inferred. Review merchant-written branding introductions for obsolete time-selection wording.

Waiver policy/provider behavior, Square/payment mode, availability/capacity algorithms and licensing enforcement/grace remain unchanged. The required scheduling change is the source of new public booking start datetimes. Existing admin access and active/completed records remain intact. Stop at **1.1.0**.

## Files changed

41 files, all part of this release:

```text
CHANGELOG.md
README.md
bike-rental-plugin/assets/css/booking.css
bike-rental-plugin/assets/js/booking.js
bike-rental-plugin/bike-rental-plugin.php
bike-rental-plugin/readme.txt
bike-rental-plugin/src/BookingContent.php
bike-rental-plugin/src/BookingSchedule.php
bike-rental-plugin/src/Branding.php
bike-rental-plugin/src/Checkout.php
bike-rental-plugin/src/Plugin.php
bike-rental-plugin/src/PublicBooking.php
bike-rental-plugin/src/RentalLocation.php
bike-rental-plugin/src/ReservationCleanup.php
bike-rental-plugin/src/Reservations.php
bike-rental-plugin/src/Settings.php
bike-rental-plugin/src/booking-form.php
bike-rental-plugin/src/reservations-page.php
bike-rental-plugin/src/settings-general.php
docs/booking-flow-1.1.0.md
tests/availability-ui.php
tests/booking-branding-browser.cjs
tests/booking-browser-helpers.cjs
tests/booking-flow-browser.cjs
tests/booking-flow.php
tests/calendar-booking.php
tests/calendar-duration-matrix.php
tests/checkout-store-api.php
tests/checkout.php
tests/foundation.php
tests/inventory-test-bootstrap.php
tests/inventory-worker.php
tests/licensing-client.php
tests/milestone7a-browser.cjs
tests/product-grid-browser.cjs
tests/production-cleanup.php
tests/public-booking.php
tests/reservations.php
tests/rider-booking-browser.cjs
tests/waiver-refinements.php
tests/waivers.php
```
