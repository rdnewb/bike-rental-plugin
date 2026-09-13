# Milestone 4 verification — 0.4.0

Release: **0.4.0**. Database schema: **1**, unchanged. Work is on **main**. The user confirmed Milestones 1–3 on the dedicated WordPress test site; this report covers local 0.4.0 implementation and verification. No remote deployment or browser acceptance is claimed. Milestone 5 has not started.

## Availability contract

`BikeRentalPlugin\Availability::check($start, $end, $quantity = 1, $reservation_id = null, $block_id = null)` accepts occupied UTC SQL datetime strings and positive integer quantity. The optional exclusion IDs support trusted replacement checks. A null end is an indefinite internal interval. It returns aggregate `total_capacity`, `peak_existing_usage`, `available_quantity`, `requested_quantity`, and `fits`, or `WP_Error`. It requires rental management capability and exposes no public route or customer data.

One implementation in `Availability::evaluate()` loads relevant confirmed/active reservations, holds with future expiry, and active blocks. It clips each occupied interval to the query, emits quantity start/end events, sorts by UTC timestamp with ends before starts at ties, and sweeps for maximum simultaneous usage. Available quantity is fleet minus that peak, not the sum of every overlapping row. Sorting is O(n log n), with O(n) working memory for relevant consumers. Half-open intervals `[start, end)` allow exact back-to-back bookings when buffers are zero.

Confirmed reservations and live holds use their captured preparation/turnaround occupied interval. Indefinite blocks have no end. Active rentals use an indefinite effective end until staff records completion, even in future availability queries. Completing an active rental creates a dated quantity block for the captured turnaround minutes from the actual database UTC return time; zero buffer releases immediately. The completed reservation itself is ignored. This conservative policy can reject activation against already-full future commitments; it never assumes an active bike has returned.

All allocation changes use the same capacity row, including block enable/disable and fleet reductions. Replacement checks exclude the edited record; failed edits retain the entire original row and snapshot. Fleet reductions must accommodate combined peak consumption from now through all future commitments. Read-only test results are advisory snapshots; the writer always rechecks under lock.

## Transaction and hold policy

`Database::locked()` begins an explicit InnoDB transaction, locks `{prefix}brp_availability.id = 1` with `SELECT ... FOR UPDATE`, reads the database UTC clock, validates fresh allocation data, applies guarded mutations, and checks commit. Validation and SQL errors roll back. Product/snapshot preparation is outside the transaction. No network/gateway operations run inside it. The helpers own the transaction and must not be nested in another caller's transaction.

`Database::insert()` and `update()` include a per-connection session token predicate in the mutation SQL. If WordPress loses its database connection and automatically reconnects/retries a statement, the new connection lacks that token and cannot perform an unlocked allocation. The transaction also verifies the token around commit. A failed rollback closes the connection. There is one transaction attempt and no automatic statement retry in plugin code; `brp_retry` includes `retryable = true` and instructs the caller to reload/retry. A lost commit acknowledgement may have an uncertain outcome, so reuse the original creation request key and inspect the existing record.

New holds expire **15 minutes** after acquiring the inventory lock (`Reservations::HOLD_MINUTES`). This is a documented constant for M4. Same normalized request key, server-derived intent hash, and session hash return the existing result without extending expiry, including when that result is terminal. Changed intent/session is rejected. The admin form carries its request key and hashes the current administrator ID for its test identity; future customer/payment integration must supply a trusted session boundary.

Expiry at or before database UTC now immediately removes a hold from availability. `Reservations::confirm_hold()` rechecks capacity under lock, including expired holds and already-cleaned expired results. A matching revision on an already-confirmed result is a no-op. Revisions still reject stale edits and confirmations.

WP-Cron hook `brp_expire_holds` runs every **300 seconds** and marks at most **100** truly expired holds per invocation, updating status, revision, modified time, and current snapshot status. Registration uses a separate named database lock and fresh cron-option read to prevent duplicate recurring registration. This registration lock is not the allocation lock. Cleanup itself uses the capacity-row transaction and is safe to repeat. Deactivation removes the hook; normal initialization recreates it when needed. Delayed or disabled cron cannot make expired holds consume capacity.

Legacy M3 holds with NULL expiry are retained, ignored by availability, and not silently renewed or expired. Explicit confirmation rechecks capacity. A non-hold moved back to hold through the admin correction form starts a fresh 15-minute allocation; editing an already-expired hold does not renew it. Existing development overbooking is preserved and can be diagnosed through negative available quantity; no bulk rewrite occurs.

## Automated verification

Local environment: Windows, PHP **8.3.33**, WordPress **6.8.3**, WooCommerce **10.2.2**, and MariaDB **11.4.8**, with real InnoDB tables. This is one tested combination, not a full hosting/version compatibility matrix. Dependencies and test logs are outside Git; no customer database or site credentials were used.

| Suite | Checks | Evidence |
|---|---:|---|
| `tests/packages.php` including foundation | 208 | Existing settings/package validation and source-scope checks, using API doubles |
| `tests/reservations.php` | 159 | Real schema, storage, lifecycle, UTC conversion, permissions/nonces, and rollback checks |
| `tests/reservation-editing.php` additional checks | 96 | Full editing, snapshots, preserved identifiers, revisions, no-op behavior, validation, and permissions |
| `tests/availability.php` additional checks | 98 | Peak/boundary calculations, buffers, holds, expired confirmation, active returns, blocks, reductions, scheduler, SQL failures, and real connection loss |
| `tests/availability-concurrency.php` | 40 | Independent PHP processes and database connections with observed simultaneous InnoDB lock waits |
| `tests/reservations-without-woocommerce.php` | 10 | Data/settings preservation, dependency messaging, and usable fleet/record administration without WooCommerce |
| **Total** | **611** | **Passed** |

All **26 PHP files** pass `php -n -l`; `git diff --check` passes. The plugin header, internal version, and readme stable tag agree on 0.4.0. Table/index definitions and schema version remain unchanged. Four earlier editing concurrency assertions were replaced by the stronger independent-process suite; repeated regression runs are not counted twice.

### Concurrency method and outcomes

The coordinator opens a third transaction and locks capacity row 1. It starts one separate PHP worker, observes an actual InnoDB lock wait, then starts the second worker while the first is waiting. It observes at least two simultaneous wait entries in `information_schema.INNODB_LOCK_WAITS` before releasing its lock. Workers have distinct recorded `CONNECTION_ID()` values and invoke production services. This is real overlapping execution, not sequential mock calls.

- Three rounds competing for the last bike: exactly one successful allocation and one capacity conflict, with one saved reservation.
- Two different reservations edited into one interval: one fits, one conflicts, and the losing row retains its previous interval.
- Two edits of the same revision: one succeeds and the other receives a stale-revision error.
- Fleet reduction versus incompatible creation, in both enqueue orders: both cannot succeed; saved usage never exceeds capacity.
- Block creation versus incompatible reservation creation, in both enqueue orders: one succeeds and the other conflicts.
- Duplicate hold creation: both callers receive the same saved reservation; exactly one allocation exists.

The storage tests also exercise a real lock timeout with a competing connection. Failure tests verify a successful insert rolls back when commit fails, a failed insert creates no hold, explicit retries reuse the request result, and an actual killed/reconnected database connection cannot insert without the lock. Cleanup registration is tested while an independent connection owns its named lock, then after release. Scheduler recurrence, anonymous cron execution, direct-call authorization, and deactivation are covered.

### Reproduction

Use an isolated WordPress fixture only. The integration scripts require CLI, `BRP_ALLOW_DISPOSABLE_TESTS=1`, database name `brp_m3_disposable`, table prefix `m3_`, and loopback database host `127.0.0.1:33316`. They refuse other environments. They reset plugin fixture rows and perform destructive schema-repair tests inside that disposable database. Never adapt these guards to run against a business/test-site database containing records to preserve.

With that fixture prepared and WooCommerce active, run in this order (database suites must not run in parallel):

```powershell
$phpPath = 'C:\path\to\php.exe'
$testIni = 'C:\path\to\verification\php.ini'
$env:BRP_TEST_WP_ROOT = 'C:\path\to\disposable-wordpress'
$env:BRP_ALLOW_DISPOSABLE_TESTS = '1'
& $phpPath -n tests/packages.php
& $phpPath -c $testIni tests/availability.php
& $phpPath -c $testIni tests/availability-concurrency.php
# Rebuild the regression fixtures required by the missing-WooCommerce test.
& $phpPath -c $testIni tests/reservation-editing.php
```

Check each exit code before continuing. Then deactivate WooCommerce in this disposable installation, run `tests/reservations-without-woocommerce.php` with the same PHP/configuration, and restore WooCommerce in a `finally` block. `availability.php` already includes storage and editing regressions (353 checks combined). The concurrency runner uses `proc_open`, MariaDB lock-wait metadata access, and temporary job/log files outside Git. No such tooling is needed on the deployed WordPress site.

## Dedicated test-site acceptance still required

Upload only after a test-site backup. Use zero preparation/turnaround buffers for the numerical example below, a future date, a valid active rental package, and an otherwise empty test interval. Use separate intervals or cancel completed examples when testing later cases. Enter times in the WordPress timezone; the availability tester takes occupied intervals, including any buffers.

1. Confirm plugin 0.4.0 and schema 1 after replacing the complete inner plugin directory.
2. Confirm existing settings, packages, fleet quantity, reservations, snapshots, revisions, and blocks remain intact.
3. Set fleet quantity to 10 in Fleet.
4. Create a confirmed reservation for 6 bikes, 09:00–11:00.
5. Create another confirmed reservation for 6 bikes, 11:00–13:00.
6. Test occupied interval 09:00–13:00.
7. Confirm peak usage 6 and available quantity 4, rather than negative 2.
8. Create a confirmed reservation for 4 bikes across 09:00–13:00 and confirm success.
9. Attempt 5 bikes for that interval and confirm rejection. Cancel the 4-bike example, then verify 5 still fails while 4 fits.
10. Confirm an interval beginning exactly at 13:00 has no overlap with those bookings. Separately test positive preparation and turnaround buffers.
11. Create a quantity block in a free/partially free test interval and confirm usage increases.
12. Reject an excessive block; edit a block into conflict and confirm the original remains unchanged; disable it and confirm capacity returns.
13. Edit a reservation into conflict and confirm the capacity message.
14. Reload and verify its original quantity, dates, package snapshot, and revision are intact. Also submit two open copies of one edit form and verify the stale copy fails.
15. Cancel a confirmed reservation and verify capacity returns.
16. Create a hold and verify quantity is consumed and the detail page shows local expiry. Retry the same submitted form and verify one reservation.
17. Wait past the displayed 15-minute expiry, leaving cleanup delayed on the test site if practical; verify capacity returns before status cleanup. Do not edit protected expiry fields in the admin UI.
18. Run Availability test > Run expired-hold cleanup; confirm expired status, revision increment, and safe repeat. Test hold confirmation before expiry and after expiry with both free and consumed capacity.
19. On a separate interval without conflicting future commitments, mark a rental active, let its scheduled end pass, and verify it remains allocated. Complete it and verify immediate release at zero turnaround, or a dated block until positive turnaround ends.
20. Reject a fleet reduction below peak combined reservation/block usage.
21. Increase fleet quantity and verify availability increases; allow a reduction that still covers commitments.
22. The local independent-process suite already proves final-bike contention. Repeat only in an equivalent isolated fixture if host-specific concurrency evidence is required; do not run destructive fixture scripts on this site.
23. Deactivate/reactivate and confirm records/settings persist and only one cleanup event is registered.
24. Confirm no public booking form, availability endpoint, rental calendar, checkout/payment/deposit/waiver flow, customer email, or Square stock synchronization was introduced. Check administrator/shop-manager access and deny an unauthorized role.

## Issues and implementation limits

- No outstanding failure in the local automated suites. Test-site browser/SFTP acceptance and other host/database versions remain unverified.
- The WordPress reconnect behavior required guarding mutation SQL, not just checking errors after a reconnect. The actual connection-loss test verifies this protection.
- Active rentals deliberately block future availability until completion; staff return handling and captured turnaround blocks are part of this M4 policy.
- The duration is a 15-minute constant. Cleanup uses WP-Cron rather than requiring Action Scheduler/WooCommerce. Delayed cleanup can leave an expired timestamp with status hold temporarily; capacity remains correct.
- Manual dates remain explicit; package-duration generation, opening-hours/notice/horizon rules, public/customer authorization, payment verification, and fulfillment readiness belong to later milestones.
- Existing development conflicts and NULL-expiry legacy holds are retained, with the handling described above. No automatic audit-history retention, serialized-bike tracking, operations table, or schema migration was added.

## Files and release handoff

New runtime files: `src/Availability.php`, `src/HoldCleanup.php` inside the deployable plugin.

Changed runtime files: `bike-rental-plugin.php`, `readme.txt`, `src/Plugin.php`, `src/Database.php`, `src/Fleet.php`, `src/Reservations.php`, `src/DataAdmin.php`, `src/fleet-page.php`, and `src/reservations-page.php`.

New tests: `tests/availability.php`, `tests/availability-concurrency.php`, `tests/inventory-test-bootstrap.php`, `tests/inventory-worker.php`. Updated tests: `tests/foundation.php`, `tests/reservations.php`, `tests/reservation-editing.php`.

Documentation: `README.md`, `CHANGELOG.md`, `docs/reservation-storage.md`, and this report. Historical verification documents retain their original release counts and scope.

Recommended commit message: `Add shared fleet availability and allocation protection for version 0.4.0`.

Exact SFTP source folder:

```text
C:\Users\RandyNewby\Documents\GitHub\bike-rental-plugin\bike-rental-plugin\
```

Destination relative to the WordPress installation:

```text
wp-content/plugins/bike-rental-plugin/
```

Upload the complete inner folder, including both new PHP classes. Do not upload the repository root, tests, documentation, `.git`, logs, or local dependencies. No build is needed. No remote server absolute path is asserted because none was provided. Older 0.3.x code bypasses allocation protection; do not use it to modify current commitments during rollback.

**Stop point: Milestone 4 only. Milestone 5 has not started.**
