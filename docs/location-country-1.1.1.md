# Bike Rental Plugin 1.1.1 release verification

Runtime **1.1.1**, schema **2**, no migration. Work directly on `main`; no push or live deployment.

## Country handling

The public Drop Off / Pick Up Location form contains name, optional company/property, address lines, city, state/province, postcode and optional notes. There is no country select, input, hidden control, country label or country-selection JavaScript. State options and required/hidden state/postcode controls are rendered server-side before JavaScript runs. Remaining fields retain labels, autocomplete and tab order.

`RentalLocation::base_country()` uses `WC()->countries->get_base_country()`, backed by WooCommerce's `woocommerce_default_country` option and standard base-country filter. It first requires an explicit stored setting so Woo's implicit fallback cannot invent a country. When Woo is unavailable, the helper reads only the country portion of that same existing `COUNTRY:STATE` setting. There is no separate plugin geography setting, locale inference or hard-coded runtime country. Missing/malformed/unknown configuration blocks new rental-location validation with a generic shop-contact message. Address validation still requires WooCommerce's country catalog and validation services; the configuration fallback does not bypass that dependency.

Validation derives country first, rejects a submitted `country` key, and validates the remaining address using Woo's country-specific state lists and postcode rules. Tests cover US, Canada, and a country without required state/postcode fields. A known state list remains a select; countries without a state list retain the supported text field. Required street, city and applicable state/postcode checks remain enforced.

Normalized country remains in the existing structured reservation snapshot and is copied to `_brp_rental_location` using the existing Woo order flow. No storage format or historical record is rewritten. Existing 1.1.0 addresses retain their saved country even if the store country subsequently changes. Reservation administration, order administration, authorized customer confirmation and both email formats retain formatted location output. Empty location components are omitted. Woo billing and shipping countries are not assigned from the rental location.

Fixed opening-time starts, inclusive calendar-day pickup, buffers, availability/capacity, rider/waiver workflow, disclaimer, refund policy, Square/payment mode and licensing enforcement are unchanged.

## Verification

**1,856 distinct PHP checks passed**; another **31 Store API checks passed under HPOS** (1,887 including that repeat). Counts exclude nested suite repetitions.

| Suite | Checks |
| --- | ---: |
| Foundation/packages | 209 |
| Public booking | 75 |
| Calendar scheduling/duration matrix | 667 |
| Checkout lifecycle | 96 |
| Store API checkout, address storage and display | 31 |
| Waivers/refinements | 345 |
| Concurrent inventory allocation | 70 |
| Licensing integration | 56 |
| Enforcement configuration | 12 |
| Product cards | 37 |
| Branding | 81 |
| Calendar/deep links | 93 |
| Booking flow and country validation | 77 |
| Country fallback without Woo | 7 |

**261 browser checks passed:** 56 booking-flow, 80 product-card, 48 branding, 41 calendar/deep-link and 36 rider-collection checks. These use real PHP-rendered markup and shipped assets with mocked REST. Desktop, tablet and mobile cover country-control absence, initialized state options, required postcode, payload without country, successful booking/checkout navigation, disclaimer/policy placement, keyboard flow and overflow. The mobile screenshot was visually reviewed.

**74 PHP files and 13 JS/CJS files passed syntax checks.** Final whitespace/diff review passed. Real disposable WooCommerce checkout tests used a Canadian store/rental location with US billing and shipping; the country separation passed in CPT and HPOS. Provider payments/signatures remain doubles; no live charge or signing was performed.

## Live QA and limitations

1. Review WooCommerce's configured store country; open Reserve and confirm there is no Country field, including hidden country inputs.
2. Choose a package/date. Verify the disclaimer appears, the fixed start is correct, and Refund & Returns Policy remains immediately above Reserve Bikes.
3. Enter the rental location. Confirm state options and required postcode match the store country; invalid values must prevent submission. Enter riders and reserve.
4. Complete WooCommerce/Square sandbox checkout. Verify billing country and any shipping country retain the customer's checkout values.
5. Open the reservation and Woo order. Verify readable rental location and the derived country in the stored reservation snapshot and `_brp_rental_location` metadata through authorized inspection.
6. Check authorized customer confirmation and actual order email delivery. Open an older 1.1.0 reservation to confirm its original country still displays.
7. Verify the normal live waiver/signing flow and the installed Divi/theme at mobile, tablet and desktop sizes.

Clear page/asset caches and refresh open forms after upload; 1.1.0 forms that still submit country are rejected. If the store country changes while a page is open, reload so state options match the server configuration. This remains a single-store-country location flow, without geocoding or deliverability checks. Invalid/missing store configuration must be corrected before new bookings; existing records remain readable. Live Square, WPForms, email delivery and installed-theme behavior require the checks above.

## Packaging and source control

Build with `python tools/package.py`. ZIP: `.release/production/bike-rental-plugin-1.1.1.zip`. The ZIP contains 50 runtime files; SHA256 `35823a66d7cbbc2dd98c4d4c7b11ace9587b03cf249f4e935d0acd58198a4dfe`. The packager verifies runtime-only contents and source-byte equality; tests, development artifacts and NT License Controller are excluded. No runtime files were removed by this release.

SFTP destination: **`/wp-content/plugins/bike-rental-plugin/`**, relative to the WordPress root. Upload the inner plugin directory's contents. No controller upload or reactivation is required.

Commit message: `fix: simplify rental location country handling in 1.1.1`. Actual commit hash is reported in the completion message. Stop after 1.1.1; do not push or deploy.

## Files changed

17 files, all related to this release:

```text
CHANGELOG.md
README.md
bike-rental-plugin/assets/js/booking.js
bike-rental-plugin/bike-rental-plugin.php
bike-rental-plugin/readme.txt
bike-rental-plugin/src/Plugin.php
bike-rental-plugin/src/RentalLocation.php
docs/location-country-1.1.1.md
tests/availability-ui.php
tests/booking-browser-helpers.cjs
tests/booking-flow-browser.cjs
tests/booking-flow.php
tests/checkout-store-api.php
tests/foundation.php
tests/inventory-test-bootstrap.php
tests/licensing-client.php
tests/location-country-fallback.php
```
