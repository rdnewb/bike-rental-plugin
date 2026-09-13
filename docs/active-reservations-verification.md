# Active reservation timing correction — 0.5.1

Version **0.5.1**, schema **1** unchanged, on **main**. This is a corrective Milestone 5 release. Milestone 6 has not started. No remote deployment or live-site browser verification is claimed.

## Rules

| State | Inventory and timing |
|---|---|
| Hold | Temporary 15-minute pre-booking allocation; only unexpired holds claim their occupied interval. |
| Confirmed | Booked rental; claims its occupied interval only. |
| Active, on time | Allowed only when database UTC now is at or after scheduled `start_utc`. Claims the normal occupied interval. |
| Active, overdue | Once database UTC now is strictly later than `occupied_end_utc`, claims from occupied start indefinitely until completed. |
| Completed | Ended rental or staff-recorded actual return from Active, including early return. The reservation has no inventory claim; an actual-return turnaround block can remain. |
| Cancelled / Expired | No inventory claim. |

Occupied end includes the captured turnaround buffer. At the exact occupied-end second the interval is still bounded; on the next second it is overdue. This comparison uses the database clock captured after acquiring the shared inventory lock, never the requested booking date or the browser clock. No background status update is required. Stored schedule endpoints remain unchanged.

Creation, status helpers, and full administrative edits enforce activation timing against the proposed schedule, including attempts to move an already Active record into the future. Preparation time does not permit activation. Rejections preserve reference, revision, timestamps, snapshot, quantity, and allocation. The exact message is:

> This reservation cannot be marked Active before its scheduled start time.

Creating or editing a future rental as Completed is rejected. An unfinished Confirmed rental cannot be marked Completed. Selecting Completed for an Active rental confirms actual return and may occur before scheduled end; the existing actual-return turnaround mechanism remains in place. Repeated completion is a no-op, and an early-return record may retain its schedule on later corrections.

Pre-upgrade future Active records are retained as bounded scheduled claims. Staff should correct them to Confirmed; no automatic data rewrite occurs. An overdue rental can conflict with future bookings already accepted while it was on time. Those commitments remain intact, availability reports the resulting shortage, and new conflicting allocations are rejected. Staff must resolve the late return and affected commitments.

## Automated verification

Local environment: PHP 8.3.33, WordPress 6.8.3, WooCommerce 10.2.2, MariaDB 11.4.8, real disposable InnoDB tables on loopback.

| Suite | Passed checks |
|---|---:|
| Foundation and package doubles | 208 |
| Storage | 159 |
| Administrative editing | 96 |
| Existing availability | 98 |
| Active timing policy | 54 |
| Independent-process concurrency | 45 |
| Public booking REST/scheduling/security | 74 |
| Missing WooCommerce and cross-process persistence | 10 |
| **Total** | **744** |

All 32 PHP files passed syntax validation. The new suite uses MariaDB's connection-local `SET timestamp` to exercise exact boundaries through real services, SQL, and public REST dispatch. Its `finally` resets the clock; no production test-clock hook was added. Existing fixtures that deliberately put future rentals into Active/Completed now use valid schedules/statuses. Existing security, revision, snapshot, lock-failure, rollback, and independent-process oversell tests continue to pass. No JavaScript changed.

The new 54 checks cover early activation through helpers, direct creation, idempotent creation and nonce-authorized admin edits; unchanged rejected records; start-time equality and timezone conversion; proposed Active rescheduling; partial and full fleet usage; normal end versus buffered occupied end; exact end and overdue boundaries; guest start-time results before overdue/after overdue/after completion; future allocations; actual return buffers; early return and duplicate completion; permissions/nonces; completion timing; legacy future Active correction; capacity reduction; and retained commitments when a return becomes overdue.

Use only the guarded disposable fixture documented in [storage verification](reservations-verification.md). Database suites mutate their fixtures and must run sequentially:

```powershell
$phpPath = 'C:\path\to\php.exe'
$testIni = 'C:\path\to\verification\php.ini'
$env:BRP_TEST_WP_ROOT = 'C:\path\to\disposable-wordpress'
$env:BRP_ALLOW_DISPOSABLE_TESTS = '1'
& $phpPath -n tests/packages.php
& $phpPath -c $testIni tests/active-reservations.php
& $phpPath -c $testIni tests/availability-concurrency.php
& $phpPath -c $testIni tests/public-booking.php
# Rebuild storage/editing fixtures before the missing-WooCommerce test.
& $phpPath -c $testIni tests/reservation-editing.php
```

`active-reservations.php` includes the 353 existing storage/editing/availability checks, for 407 checks in that invocation. Check each process exit code. Deactivate WooCommerce only in the disposable installation, run `tests/reservations-without-woocommerce.php`, and restore WooCommerce in `finally`. That restoration was verified locally.

## Manual test-site checklist

1. Upload the repository's inner `bike-rental-plugin/` folder to the test site's `wp-content/plugins/bike-rental-plugin/`. Confirm version 0.5.1 and unchanged schema 1. Follow the existing complete-folder replacement/backup process. Do not upload `tests/` or local database tooling.
2. Use a clear test interval and valid package. Create a future Confirmed rental. Try changing it to Active; confirm the exact validation message and unchanged revision/data. Try creating it as Active and moving an existing Active rental to a future start; both must fail.
3. Create a rental whose start has arrived and whose occupied end is still ahead. Mark it Active. Check its own interval and a later date using **Bike Rentals → Availability test** and the public booking form. Only its normal occupied interval should lose its bike quantity.
4. Let that test rental's occupied end pass while it remains Active. Its quantity should now remain unavailable for later dates. Use a partial fleet quantity to verify other bikes remain available.
5. Mark the returned rental Completed. Confirm future start times become available and any configured actual-return turnaround remains protected until its block ends. Re-save Completed and confirm no duplicate block/revision.
6. Record an actual early return from Active. Confirm it can become Completed, while a future/unfinished Confirmed rental cannot. Correct any pre-upgrade future Active test records to Confirmed.

Browser/Divi and hosting-specific checks above remain pending deployment. The patch does not add checkout, payments, deposits, waivers, or fulfillment authorization.
