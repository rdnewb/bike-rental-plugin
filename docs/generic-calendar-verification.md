# Generic calendar-day rules — 0.5.3

Milestone 5 corrective release on **main**. Version **0.5.3**, database schema **1** unchanged. No Milestone 6 work or remote deployment.

## Approved rule and implementation

For `duration_type = calendar_days`, calculate `end date = start date + (duration_amount - 1)` in the WordPress timezone, then apply the configured Calendar-Day Pickup Time. The same code handles every valid configured duration; the package editor's existing supported range is 1–365 days. No new weekday or package-specific restrictions exist.

Any weekday can be a start provided its operating schedule is open, the start is in hours/on the opening-relative increment, minimum notice/horizon are met, and sufficient inventory exists. Intermediate days need no delivery hours. Final-day pickup is business-controlled and is independent of customer start/delivery hours, including closed final days. One-day pickup must remain later than the selected start; impossible/ambiguous local timestamps still fail validation.

The correction scopes the existing final-day hours check in `BookingSchedule::calculate()` to hourly packages. The generic calendar arithmetic is retained. The settings page no longer warns that a calendar pickup outside delivery hours prevents booking. Protected diagnostics remain available for actual start/date/metadata/interval/inventory failures. No public debug output is introduced.

Existing settings, package metadata, reservations, historical snapshots, and buffer policies are retained. New holds capture the computed local/UTC period and separate occupied buffers. Calendar bookings accepted under this policy may use a final pickup day/time which previous releases rejected. Hourly endpoint rules and the 0.5.1 Active/overdue policy are unchanged.

## Automated matrix

`tests/calendar-duration-matrix.php` runs the calendar suite, then tests all **49 duration/start combinations**: durations 1–7 crossed with Monday, Tuesday, Wednesday, Thursday, Friday, Saturday, and Sunday. Each combination uses a real WooCommerce product with that configured duration, opens only the selected start weekday, and sets pickup to 19:00 outside the 08:00–18:00 delivery hours.

For each combination, the matrix verifies candidate generation, inclusive final date and pickup time, local-to-UTC interval, 30-minute preparation and 60-minute turnaround, rejection of closed/out-of-hours/misaligned starts, notice/horizon validation, persisted hold/snapshot agreement, full occupied inventory claim and release boundary, and rejection of an additional allocation when the fleet is full. All three-day starts across all seven weekdays are explicitly exercised through the same matrix and the retained public REST calendar suite.

Additional cases cover 8, 14, 30, 90 and 365 days; invalid duration inputs; one-day pickup before start; one-day pickup after delivery closing; and hourly rejection of closed/out-of-hours endpoints. Existing calendar tests were updated to assert the newly approved collection policy instead of the superseded final-day restrictions. Existing DST, fixed-offset, public security, buffer-conflict, and calendar-hold tests remain.

Run only in the guarded disposable WordPress/MariaDB fixture, with `BRP_ALLOW_DISPOSABLE_TESTS=1` and `BRP_TEST_WP_ROOT` set. Database suites must run sequentially:

```powershell
& $phpPath -n tests/packages.php
& $phpPath -c $testIni tests/active-reservations.php
& $phpPath -c $testIni tests/availability-concurrency.php
& $phpPath -c $testIni tests/public-booking.php
& $phpPath -c $testIni tests/calendar-duration-matrix.php
# Rebuild fixtures, then run the missing-WooCommerce check with restoration in finally.
& $phpPath -c $testIni tests/reservation-editing.php
```

The matrix invocation includes 65 existing calendar checks and 602 additional checks, for 667 checks. Do not count the inherited calendar checks twice.

Local verification passed **1,411 checks**: 208 foundation/package, 159 storage, 96 editing, 98 availability, 54 Active timing, 45 independent-process concurrency, 74 public booking, 65 calendar booking, 602 duration matrix, and 10 missing-WooCommerce/persistence checks. All **34 PHP files** passed syntax validation; Git whitespace checks passed. Tested with PHP 8.3.33, WordPress 6.8.3, WooCommerce 10.2.2, and MariaDB 11.4.8 on the disposable loopback fixture. WooCommerce was restored after the dependency check. No live-site state was modified.

## Manual test-site steps

1. Upload the full inner `bike-rental-plugin/` folder to `wp-content/plugins/bike-rental-plugin/`, following the existing backup and complete-folder replacement process. Confirm 0.5.3 and unchanged schema 1.
2. Configure a test start day open 08:00–18:00, 30-minute increments and pickup 19:00. Other days may be closed. For each duration 1–7, verify the public form's date is the Nth included calendar day and pickup remains 19:00. Repeat on Monday, Wednesday, Friday, Saturday and Sunday; include Tuesday and Thursday for three-day packages.
3. Specifically test three days starting Saturday with Sunday and Monday closed for starts: pickup must be Monday at the configured time. A closed start Saturday must still reject the booking.
4. Test a one-day package: starts before pickup are valid; a pickup at/before start is rejected. Recheck a four-hour package to confirm its previous closing-hour restriction remains.
5. Verify a temporary hold's displayed end, occupied-buffer allocation and quantity against the admin availability tester. Restore intended business settings after testing.

Files changed: `BookingSchedule.php`, settings view, plugin header/constant/readme, version assertions, calendar/public tests, the new duration matrix, README/CHANGELOG, and current/historical verification documentation. No JavaScript or database schema changed. Browser/Divi acceptance remains pending deployment.

Recommended commit message: `Support generic calendar durations and independent pickup in 0.5.3`.
