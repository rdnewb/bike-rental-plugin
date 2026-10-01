# Bike Rental Plugin 1.1.3 release verification

Runtime **1.1.3**, schema **2**, unchanged. No migration. Work on `main`; no push or live deployment.

## Customer presentation and preserved data

The Checkout / Your Reservation receipt keeps Reference, Package, Bikes, price per bike and the existing formatted Drop Off / Pick Up Location. Start, Pickup / end and Timezone are replaced with Rental Date. It reuses the 1.1.2 JavaScript date formatter: same-day rentals show one friendly date; multi-day rentals show both inclusive dates, correctly across month/year boundaries. The browser timezone cannot shift these store-local dates.

The existing disclaimer moves from the hidden form into the receipt, and returns to its original position on Start Over. Only one copy exists. The refund policy and pre-reservation review remain unchanged. The checkout deadline/countdown remains visible; it describes the payment window, not rental delivery timing.

`CustomerSummary` provides equivalent PHP date-only formatting for server-rendered cart and order/email output. Cart item details use the reservation snapshot's agreed local dates and the existing safe location formatter. Woo order item formatted metadata is filtered for customer views, including legacy orders, thank-you/account views and the Order Confirmation Block. Customer display removes Rental Start, Rental End and Delivery Time and adds Rental Date from the order's saved snapshot. It never writes or removes stored item/order metadata, and does not re-read a later edited reservation to describe an older order.

Staff order administration retains original precise item metadata. Woo email before/after-order-table hooks track the intended audience, so a customer email resent from admin still hides timing, while staff emails retain it. HTML and plain-text templates use the same formatted metadata filter. Unrelated retail items are unaffected. Billing/shipping sections and authorization rules remain unchanged.

Waiver invitation `{rental_start}` and `{rental_end}` now expand to individual friendly dates, without times or timezone. New `{rental_dates}` expands to the full range; settings explain the date-only meaning. Saved templates and waiver evidence are not rewritten, and invitation/signature/payment workflows are unchanged.

All actual start/end/occupied timestamps, buffers, opening-time derivation, inclusive calendar durations, cutoff rules, availability/capacity, concurrency, holds, payment, licensing and administrative timing remain unchanged. This is display filtering, not a data-redaction or scheduling change.

## Runtime string/output audit

| Occurrence | Result | Reason |
| --- | --- | --- |
| `assets/js/booking.js`, receipt `Start`, `Pickup / end`, `Timezone` | Removed/replaced with Rental Date | Customer summaries use friendly dates. The former time-formatting helper was removed; existing date-range helper is reused. |
| `Checkout::item_data()`, Rental Start / Rental End / Delivery Time | Rewritten for customers | Cart/Checkout Block receives friendly dates and formatted location through `CustomerSummary::details()`. |
| `Checkout::details()` and saved item metadata with those three labels | Intentionally retained in storage and staff displays | Staff/order operations retain exact time and timezone. Customer formatted metadata filters those keys without modifying them. |
| Woo thank-you, account/order details and Order Confirmation Block item data | Rewritten | Existing and new rental orders display Rental Date from the order snapshot. Standard billing/shipping remains untouched. |
| Woo customer HTML/plain-text email item details | Rewritten | Same date-only filter, even when sent from admin. Staff emails intentionally retain operational times. |
| `WaiverEmail::compose()`, rental_start / rental_end placeholders | Rewritten | Friendly date-only substitutions; rental_dates is available for ranges. Default templates already contained no exact timing. Merchant-authored literal prose is preserved. |
| `RentalLocation` confirmation/admin/email callbacks | Retained | Already contain only customer-safe formatted address/notes, without scheduling timestamps. |
| `WaiverUI`, WPForms adapter | Retained | Rider/waiver progress, signatures and identifiers do not render operational rental times. Staff invitation/completion timestamps remain audit evidence. |
| `calendar-page.php`, `DataAdmin.php`, `settings-general.php` timezone labels; reservation and availability admin timing | Intentionally retained | Staff scheduling, calendar, block editing and timezone configuration require precision. |
| `BookingSchedule`, `RentalTime`, `Availability`, `Reservations`, `PublicBooking` timing fields/diagnostics, Checkout raw order metadata | Intentionally retained | Calculation, persistence, concurrency, payload compatibility and internal diagnostics. Payload values are not rendered as customer rental times. |
| `booking.js` Hold expires / Temporary hold remaining | Intentionally retained | An actionable checkout expiry deadline/countdown, not rental start/end. Hold semantics are unchanged. |
| Temporary Reservation wording | No current customer runtime occurrence | Removed in 1.1.2. Historical readme changelog mentions remain documentation only. |

The audit covers plugin-generated output and standard Woo rendering hooks. It cannot rewrite arbitrary merchant text or third-party templates that bypass Woo's formatted-meta APIs.

## Verification

**2,300 distinct PHP checks passed**, with nested tests counted once. Another **55 checks passed under HPOS** (24 summary + 31 Store API), giving **2,355 including storage-mode repetition**.

| Suite | Checks |
| --- | ---: |
| New customer summary / real Woo templates | 24 |
| Booking flow/address/content | 78 |
| Foundation/packages | 209 |
| Public booking | 75 |
| Calendar scheduling/duration matrix | 667 |
| Checkout lifecycle | 96 |
| Store API checkout | 31 |
| Waivers/refinements | 346 |
| Real concurrent allocation | 70 |
| Licensing integration | 56 |
| Enforcement configuration | 12 |
| Product cards | 37 |
| Branding | 81 |
| Admin calendar/deep links | 93 |
| Storage/editing/availability/active status | 425 |

**292 browser checks passed:** 87 booking/receipt, 80 product-card, 48 branding, 41 calendar/deep-link and 36 rider checks. Tests use actual PHP markup and shipped assets with mocked REST. They cover desktop/tablet/mobile, receipt identity/location/date display, absence of operational timing, shared review/receipt date formatting, single disclaimer, date boundaries and different device timezones. The mobile receipt screenshot was visually reviewed.

**76 PHP files and 13 JS/CJS files passed syntax checks.** Diff/whitespace checks passed. Real Woo HTML/plain-text email templates were rendered for customer and staff audiences, including customer rendering from admin; tests verified context restoration, unchanged raw timestamps, legacy metadata and Order Confirmation Block item data. Provider payment/signature evidence remains simulated; no live charge, signature or email delivery occurred.

## Live QA and limitations

1. Open Reserve; select an hourly package/date and enter location/riders. Confirm disclaimer and refund policy remain correct; create the reservation.
2. In Your Reservation verify Reference, Package, Bikes, friendly Rental Date and readable location. Confirm Start, Pickup / end and Timezone are absent; one disclaimer is visible.
3. Repeat for a multi-day package, including month/year boundaries. Test receipt reload/recovery, expiry/Start Over and mobile/tablet/desktop in the installed Divi theme.
4. Complete Square sandbox checkout. Inspect cart/checkout, thank-you/Order Confirmation Block and customer account order details; verify friendly dates and existing billing/shipping/location sections.
5. Check delivered HTML/plain-text customer emails and resend one from order administration. Verify no precise rental timing. Check customized waiver invitation templates, including the new date-range placeholder.
6. Verify staff Woo order metadata, reservation administration and calendar still show precise operational times. Confirm normal waiver signing and booking readiness.

Dates follow the existing English-language convention. Missing/invalid historical snapshot dates are omitted instead of exposing raw timestamps; stored operational data remains available to staff. Merchant-written literal timing text or third-party renderers that bypass standard Woo hooks require manual review. Clear page/asset caches and refresh open forms after upload. Already-delivered emails are not altered.

## Packaging and source control

ZIP: `.release/production/bike-rental-plugin-1.1.3.zip`, built with `python tools/package.py`. All **51 runtime files** were byte-verified against source. Tests, development artifacts and NT License Controller are excluded; no runtime files were removed. SHA256: `086ced3d6f361962dacd9dcf179202e3bc4c401d08b2d3b6ec605b8bb2ad7457`.

SFTP destination: **`/wp-content/plugins/bike-rental-plugin/`**, relative to the site's WordPress root. Upload the inner plugin folder contents. No controller update or schema migration is required.

Commit message: `fix: simplify customer reservation timing output in 1.1.3`. Actual hash is reported in the completion message. Stop after 1.1.3; do not push or deploy.

## Files changed

17 files, all related to this release:

```text
CHANGELOG.md
README.md
bike-rental-plugin/assets/js/booking.js
bike-rental-plugin/bike-rental-plugin.php
bike-rental-plugin/readme.txt
bike-rental-plugin/src/Checkout.php
bike-rental-plugin/src/CustomerSummary.php
bike-rental-plugin/src/Plugin.php
bike-rental-plugin/src/WaiverEmail.php
bike-rental-plugin/src/WaiverSettings.php
docs/customer-summary-1.1.3.md
tests/availability-ui.php
tests/booking-flow-browser.cjs
tests/checkout.php
tests/customer-summary.php
tests/foundation.php
tests/licensing-client.php
```
