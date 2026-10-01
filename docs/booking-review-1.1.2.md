# Bike Rental Plugin 1.1.2 release verification

Runtime **1.1.2**; schema **2**, unchanged. No migration. Work on `main`; no push or live deployment.

## Customer review

The public pre-reservation heading is **5. Review Your Reservation**. Its review now shows the selected rental's product name, friendly rental date or date range, quantity with bike/bikes wording, rider count, entered Drop Off / Pick Up Location and notes, and the existing price per bike. Product name remains the primary duration description; internal duration fields are not shown. Quantity/rider counts and address details update with form edits. An invalid/reset date clears the review.

Start datetime, Pickup / end datetime and Timezone are removed from this review. Dates come from the existing server-calculated local endpoints, formatted in English with full month names. A same-day rental shows one date; a multi-day rental shows both full dates separated by a dash. Formatting uses a UTC anchor only to prevent the browser's timezone from shifting already-local calendar dates; it never recalculates rental times or duration.

The draft location is not yet stored, so the browser previews its existing form values as text without a new endpoint. State selects display their readable option label, multiline notes are retained, and country/internal metadata are omitted. The existing server-side address formatter remains authoritative for saved confirmations, administration, orders and email. Customer input is added using text nodes, never HTML. Long review text wraps within the layout.

The duplicate disclaimer is not introduced: the configured notice remains prominent immediately after valid date selection. The refund policy remains immediately above Reserve Bikes. Labels and expiry/session messages no longer use “Temporary Reservation”; the separate checkout receipt is labeled Your Reservation. Hold semantics, checkout countdown and expiry handling are unchanged.

The requested timing-field removal is limited to the **pre-reservation review**. The separate post-reservation checkout receipt, WooCommerce order metadata/displays, reservation administration and admin calendar retain their existing scheduling details. No timing payload, UTC value, timezone conversion, derived opening-time rule, pickup rule, inclusive calendar-day duration, buffer, half-open interval, cutoff, allocation check or concurrency behavior changes. Only public message text changes in the reservation service/API.

## Verification

**2,275 distinct PHP checks passed**, excluding nested suite repetitions:

| Suite | Checks |
| --- | ---: |
| Booking flow/address/content | 78 |
| Foundation/packages | 209 |
| Public booking | 75 |
| Calendar scheduling/duration matrix | 667 |
| Checkout lifecycle | 96 |
| Store API checkout/address metadata | 31 |
| Waivers/refinements | 345 |
| Real concurrent allocation | 70 |
| Licensing integration | 56 |
| Enforcement configuration | 12 |
| Product cards | 37 |
| Branding | 81 |
| Admin calendar/deep links | 93 |
| Storage/editing/availability/active status | 425 |

**280 browser checks passed:** 75 booking-review/flow, 80 product-card, 48 branding, 41 calendar/deep-link and 36 rider checks. Real PHP-rendered markup and shipped assets run with mocked REST. New coverage includes heading/wording, hidden review timing, package/quantity/rider count, live address and notes, safe text rendering, quantity changes, single/multiple dates across month/year boundaries, and devices using Honolulu, Kiritimati or Los Angeles timezones. Existing checks cover date reset, keyboard flow, overflow, disclaimer, policy placement, hold submission and checkout navigation. Desktop/tablet/mobile were exercised; the mobile screenshot was visually reviewed.

**74 PHP files and 13 JS/CJS files passed syntax checks.** Final diff/whitespace checks passed. Provider payment/signature evidence uses doubles; no live charge, signature or email delivery was performed. Stored timestamps and allocation remain covered by integration/concurrency regressions; admin timing and order timing code is unchanged.

## Live QA

1. Open Reserve; select an hourly package and valid date. Confirm the configured disclaimer appears.
2. Enter rental location, notes and riders; choose bike quantity. Confirm **Review Your Reservation** shows the correct product, friendly date, quantity, rider count and readable location/notes. Edit fields and verify the summary updates.
3. Confirm the review has no exact start/end times, raw date strings or timezone, and that Refund & Returns Policy is directly above Reserve Bikes.
4. Repeat with a multi-day package, including a month boundary; verify the date range matches the rental's inclusive dates. Check mobile/tablet/desktop and keyboard navigation in the installed Divi/theme.
5. Submit successfully; complete Square sandbox checkout. Check stored reservation/order timestamps, admin calendar, normal waiver signing and actual email delivery.

Known limits: this is an English-language draft preview, not a new address-validation or pricing service. Server validation still governs the saved address and booking. Rider count reflects the currently required rider entries, not signed waivers. Live provider/theme behavior requires the checks above. Clear page/asset caches after upload and refresh open booking forms.

## Packaging and source control

ZIP: `.release/production/bike-rental-plugin-1.1.2.zip`, built with `python tools/package.py`. All **50 runtime files** were byte-verified against source. Tests, development artifacts and NT License Controller are excluded; no runtime files were removed. SHA256: `532495a1b8cd4707f27151973cb388902e14554463123b6fc19d9e2292d4a954`.

SFTP: **`/wp-content/plugins/bike-rental-plugin/`**, relative to the site's WordPress root; upload the inner plugin folder contents. No controller update or schema migration.

Commit message: `fix: simplify customer booking review in 1.1.2`. The actual hash is reported in the completion message. Waiver, Square/payment, availability/capacity and licensing behavior remain unchanged. Stop at 1.1.2; do not push or deploy.

## Files changed

16 files, all related to this release:

```text
CHANGELOG.md
README.md
bike-rental-plugin/assets/css/booking.css
bike-rental-plugin/assets/js/booking.js
bike-rental-plugin/bike-rental-plugin.php
bike-rental-plugin/readme.txt
bike-rental-plugin/src/Plugin.php
bike-rental-plugin/src/PublicBooking.php
bike-rental-plugin/src/Reservations.php
bike-rental-plugin/src/booking-form.php
docs/booking-review-1.1.2.md
tests/availability-ui.php
tests/booking-flow-browser.cjs
tests/booking-flow.php
tests/foundation.php
tests/licensing-client.php
```
