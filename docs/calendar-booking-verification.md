# Calendar-day investigation — 0.5.2

Corrective Milestone 5 release on **main**, version **0.5.2**, schema **1** unchanged. Milestone 6 has not started. No remote deployment or live-site browser verification is claimed.

## Root cause and limits of the finding

The confirmed code defect was in `PublicBooking::times()`: every `BookingSchedule::calculate()` error was discarded by `continue`. If all candidates failed final-day pickup validation, the response still said “No available start times for this date. Choose another date.” This hid schedule/configuration failures as an inventory shortage.

The 0.5.1 date arithmetic was already correct. Before editing production code, a real local WooCommerce 3-day product with all days open 08:00–18:00 and a 30-minute increment produced:

| Pickup setting | Monday start date | Offered starts | Direct 09:00 candidate |
|---|---|---:|---|
| 17:00 | 2026-09-14 | 20, from 08:00 to 17:30 | Wednesday 2026-09-16 17:00 |
| 05:00 | 2026-09-14 | 0 | Rejected: outside pickup hours |

The revised endpoint preserves the safe pickup-hours explanation. A seven-weekday test reproduces zero starts for every date with 05:00 pickup and restores 20 starts for every date with 17:00 pickup. An hourly package remains bookable with either setting because it derives its end from elapsed hours.

**The deployed site's specific trigger is not confirmed.** Its actual pickup time, weekly hours, test date/page, and inventory were not supplied or read. The AM/PM mismatch above is a demonstrated reproduction, not a claim about the site's saved values. Final-day closures and multi-day inventory conflicts can also correctly exclude calendar-day rentals while hourly rentals fit. Do not change business settings blindly or claim an arithmetic fix that the evidence does not support.

## Investigation of the ten suspected causes

| Suspected cause | Finding |
|---|---|
| Off-by-one day | Already uses `start date + (duration amount - 1)` in local calendar days; 3-day Monday ends Wednesday and 5-day Monday ends Friday. |
| Pickup time validation | Can reject all candidates for a date; errors were hidden. A pickup outside every open day's hours reproduces every-date failure. |
| Final-day hours | Uses the weekday of the computed local end; closed final days and pickup outside that day's hours are rejected. |
| Start-day hours | Candidates begin at that day's opening and advance by the configured increment. Starts at closing remain excluded. |
| Entire rental must fit first day | No such restriction exists for calendar-day rentals; tests include a Monday closing at noon with Wednesday pickup at 17:00. |
| Hourly logic applied to calendar days | Separate branches already exist. Unknown duration types are now explicitly rejected instead of implicitly taking the calendar branch. |
| Buffers | Applied only to occupied UTC endpoints after the customer schedule passes validation. Protected diagnostics distinguish buffer-only inventory failure. |
| Timezone | Local calendar-date arithmetic and WordPress timezone conversion verified across both DST transitions and a fixed offset. |
| Intermediate closed days | Not checked; a closed Tuesday is allowed between Monday and Wednesday. |
| Package metadata | Real WooCommerce CRUD-loaded `calendar_days` and string duration `3` normalize correctly; malformed metadata is rejected with a protected reason code. Site-specific metadata remains uninspected. |

## Exact changes

- Keep existing inclusive date arithmetic and endpoint rules. Preserve hourly behavior and full occupied-interval capacity checks.
- Give scheduling errors stable internal reason codes. When every candidate lacks a valid schedule, return a safe scheduling explanation rather than the generic unavailable-inventory message. No private error data is included in public responses.
- Add `PublicBooking::diagnose_times($input)` for administrators/shop managers and tests. It runs the same candidate generator and locked inventory service and returns candidate reasons. Buffer-only diagnosis performs an extra unbuffered check only in this protected mode; it never changes allocation results.
- Warn on the settings page when calendar pickup falls outside an open day's hours. Clarify final included day, final-day-only validation, and AM/PM entry. Valid configurations with differing weekday hours remain permitted; no automatic setting changes occur.
- Bump plugin header, internal version, README, WordPress readme, and changelog to 0.5.2. No schema, checkout, payment, waiver, or Active-policy changes.

## Protected diagnostics

Call from trusted PHP while logged in as an authorized administrator/shop manager, or through an administrator's local WP-CLI session. This is not a public endpoint or a browser query flag:

```php
$result = \BikeRentalPlugin\PublicBooking::diagnose_times(
    array( 'package_id' => $package_id, 'date' => 'YYYY-MM-DD' )
);
// Inspect in the protected test/admin context only; never echo into a public page.
```

Success-shaped results include `diagnostics.candidates` with a time and reason for every candidate. Reasons include `available`, `closed_final_day`, `pickup_outside_final_hours`, `invalid_pickup_time`, `invalid_start_time`, `booking_horizon`, `minimum_notice`, `availability`, and `buffer_conflict`. Early failures return `WP_Error` with `get_error_data()['reason']`, including `closed_start_day`, `invalid_package_metadata`, and `invalid_settings`. Successful schedules include customer/occupied endpoints for inspection; no customer records, hashes, signatures, order data, or reservation identities are loaded into the diagnostic output.

Anonymous access is denied. REST does not expose this method; extra debug flags are rejected. Runtime diagnostics produce no automatic logs, and public requests do not perform the extra diagnostic capacity query. The disposable CLI test log identifies which rule was exercised, including the all-weekday pickup mismatch reproduction.

## Verification and reproduction

Local fixture: PHP 8.3.33, WordPress 6.8.3, WooCommerce 10.2.2, MariaDB 11.4.8 with real InnoDB. No customer database was modified.

`tests/calendar-booking.php` adds **65 passing checks** for all requested calendar rules, public explanations, protected diagnostics, metadata, candidate generation, endpoint boundaries, multi-day inventory, buffer conflicts, DST/fixed offsets, settings warnings, and persisted hold/snapshot agreement. All **744** prior checks also passed, for **809 total**: 208 foundation/package, 159 storage, 96 editing, 98 availability, 54 Active policy, 45 independent-process concurrency, 74 public booking, 65 calendar booking, and 10 missing-WooCommerce/persistence checks. All **33 PHP files** passed syntax validation; Git whitespace checks passed. No JavaScript changed. WooCommerce was restored after the missing-dependency check.

Run only against the strict disposable fixture documented in [storage verification](reservations-verification.md), with `BRP_TEST_WP_ROOT` and `BRP_ALLOW_DISPOSABLE_TESTS=1`. Database suites must run sequentially:

```powershell
& $phpPath -n tests/packages.php
& $phpPath -c $testIni tests/active-reservations.php
& $phpPath -c $testIni tests/availability-concurrency.php
& $phpPath -c $testIni tests/public-booking.php
& $phpPath -c $testIni tests/calendar-booking.php
# Rebuild the required fixtures before the missing-WooCommerce check.
& $phpPath -c $testIni tests/reservation-editing.php
```

Check every exit code. Then deactivate WooCommerce in the disposable installation, run `tests/reservations-without-woocommerce.php`, and restore WooCommerce in `finally`.

## Files changed

Runtime: `src/BookingSchedule.php`, `src/PublicBooking.php`, `src/settings-page.php`, `src/Plugin.php`, plugin header, and `readme.txt` inside the inner plugin directory. Tests: `tests/calendar-booking.php` and version assertions in `tests/foundation.php`. Documentation: root `README.md`, `CHANGELOG.md`, and this report.

## Manual browser checks and upload

1. Upload the complete inner `bike-rental-plugin/` directory into the test site's **`wp-content/plugins/bike-rental-plugin/`** using the existing backup/complete-folder SFTP procedure. Confirm version 0.5.2 and unchanged schema 1. Do not upload tests or local tooling.
2. In Bike Rentals → Settings, inspect the saved **Calendar-day pickup time**, its AM/PM, WordPress timezone, and weekly hours. A warning lists open days whose hours exclude that pickup. Retain business-approved values; choose a valid final-day pickup for the test fixture.
3. On a clear future Monday with Monday and Wednesday open 08:00–18:00, choose a 3-day package and a 09:00 start. With pickup 17:00, verify Wednesday 17:00 in the form summary and temporary-hold receipt. Confirm candidates from Monday 08:00 through 17:30 at a 30-minute increment.
4. Repeat with a 5-day package: Monday must end Friday. Confirm late Monday starts may span overnight; changing Monday closing to noon must not invalidate Wednesday's 17:00 pickup for earlier Monday starts.
5. Close intermediate Tuesday: the 3-day Monday rental should still work. Close final Wednesday: it should fail with the closed-final-day explanation. Restore the test hours afterward.
6. Set pickup before Wednesday opening or after closing: no start times, a useful public pickup-hours message, and a settings warning. Test pickup exactly at opening/closing as valid. Restore the intended pickup setting.
7. Verify the 4-hour package still offers its previous start times. Test with preparation/turnaround buffers and later-day blocks: availability should use the full occupied period while the displayed end remains Wednesday 17:00.

Recommended commit message: `Fix calendar-day rejection reporting and diagnostics in 0.5.2`.

Manual browser/Divi verification and confirmation of the deployed configuration trigger remain outstanding. Milestone 6 has **not** started.
