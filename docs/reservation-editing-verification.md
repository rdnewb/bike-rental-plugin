# Verification — corrective Milestone 3 version 0.3.1

## Change and scope

The 0.3.0 detail page restricted schedule edits to hold/confirmed records, omitted package and issue-code editors, and retained its original snapshot on edit. Version **0.3.1** replaces those restrictions with one full administrator/shop-manager edit form for package, quantity, local start/end, any of the six valid statuses, and issue code/short internal note.

Reference, created time, WooCommerce order relationships, internal IDs, request/session fields, and raw snapshot JSON remain protected. A package replacement captures its current WooCommerce selling price and package details. Other edits retain agreed package pricing/duration while updating the snapshot's schedule, timezone, quantity, and status. No other reservation or WooCommerce order is rewritten. Previous snapshot revisions are not retained in this version; future audit/history functionality may preserve them.

Schema version stays **1**. There are no added columns, tables, migrations, or availability conflict checks. The detail page displays the required development-only warning. **Milestone 4 has not started.**

## Automated verification

Verified locally with PHP **8.3.33**, WordPress **6.8.3**, WooCommerce **10.2.2**, and MariaDB **11.4.8**. The disposable database is bound to localhost with prefix `m3_`. No dedicated-site deployment or browser acceptance is claimed.

**477 passing checks:**

| Suite | Count | Notes |
|---|---:|---|
| Foundation | 113 | Existing API-double regression checks; expected version updated |
| Packages | 95 | Existing API-double regression checks; regular-price package contract unchanged |
| Reservation storage | 159 | Real WordPress/WooCommerce/MariaDB; two obsolete behavior assertions updated for the requested current-snapshot/admin-edit contract |
| Reservation editing | 100 | New real integration checks, including real nonces and a competing database connection |
| Missing WooCommerce | 10 | Separate PHP process with WooCommerce deactivated; data still available |

All **20 PHP files** pass syntax checking; `git diff --check` passes.

The editing suite verifies authorized administrator/shop-manager edits, denied unauthorized edits, missing/incorrect/record-mismatched nonce handling, incomplete forms, package replacement and actual sale-price capture, all required snapshot details, quantity/date/UTC persistence, invalid fields with atomic rejection, all six valid administrative statuses, issue-code save/clear/limits, revisions, modified timestamps, stale forms, and no-op preservation—including a legacy 0.3.0 snapshot.

It also verifies preserved reference/created time/IDs/order relationships/hashes despite forged payloads, unchanged other reservations and WooCommerce product prices, escaped/read-only controls, visible warning, success/error notices, and unchanged schema version. A second real database connection commits a status change after the initial revision read; the losing edit is rejected by the SQL revision predicate and its rollback does not overwrite the winning row/snapshot.

The earlier storage suite still exercises regular-price creation, fleet/block operations, UTC/DST conversion, lifecycle helpers, data retention, unique constraints, migration repair, and transaction/lock failures. The replaced assertions now require current schedule/quantity snapshots with preserved agreed package pricing, and allow an administrative edit of a retained cancelled record. Ordinary lifecycle-helper transition restrictions still pass.

## Reproduce locally

Use the isolated setup and safety guards in [the storage verification record](reservations-verification.md). `reservation-editing.php` runs `reservations.php` first; do not double-count those 159 checks. These scripts create persistent fixtures and must never target business data.

```powershell
$phpPath = 'C:\path\to\php.exe'
$testIni = 'C:\path\to\integration-php.ini'
& $phpPath -n .\tests\packages.php
if ($LASTEXITCODE -ne 0) { throw 'Foundation/package tests failed' }
$env:BRP_TEST_WP_ROOT = 'C:\path\to\disposable-wordpress'
$env:BRP_ALLOW_DISPOSABLE_TESTS = '1'
& $phpPath -c $testIni .\tests\reservation-editing.php
if ($LASTEXITCODE -ne 0) { throw 'Editing/storage tests failed' }
# Deactivate WooCommerce on the disposable installation.
& $phpPath -c $testIni .\tests\reservations-without-woocommerce.php
if ($LASTEXITCODE -ne 0) { throw 'Missing-WooCommerce tests failed' }
# Reactivate WooCommerce if continuing tests; stop the disposable DB when finished.
```

## Manual dedicated-site steps — pending

1. Back up the dedicated test site and retain the 0.3.0 plugin folder. Upload the complete inner plugin folder. Confirm **0.3.1**, schema **1**, and retained settings/packages/fleet/reservations.
2. Open **Bike Rentals → Reservations → View / edit** for a hold, active, and cancelled/completed reservation. Confirm the edit form appears for each and contains package, quantity, start, end, status, and issue code. Confirm the visible availability-warning notice.
3. Record reference, created/updated time, revision, current snapshot, and any order relationship. Leave the form unchanged and save. Confirm success with identical snapshot, revision, and modified time.
4. Change package to another active rental package with a distinct price, duration, and promotional label. If testing a sale, ensure it is currently active in WooCommerce. Change quantity and future local start/end, choose a valid status, enter a short issue note, and save.
5. Confirm all submitted values persist, revision increments once, modified time reflects the save, and the snapshot matches the replacement's selling price, currency, package details, local schedule, timezone, quantity, and status. Reference, created time, and order relationship must remain unchanged. There is no raw snapshot editor.
6. Change only dates/quantity/status and save. Confirm current snapshot values follow those edits and agreed package price/duration remain. Change the catalog price separately; confirm merely viewing or saving an unchanged reservation does not reprice it.
7. Open the same reservation in two tabs. Save a real change in the first, then submit the second without reloading. Confirm a clear changed-elsewhere/reload error and no overwritten data.
8. Try quantity 0, negative/decimal or above fleet, equal/reversed end time, invalid/non-rental package, arbitrary status through test tooling, and an issue note above 64 UTF-8 bytes. Confirm rejection without partial updates. Confirm required nonce failures and customer/subscriber access cannot mutate the record.
9. Repeat a valid edit as a shop manager. Clear the issue code and confirm it stays cleared. An existing inactive package may be retained; a replacement must be active/published/priced. A product that is no longer a rental package must be replaced before the full form can save.
10. Confirm other reservations and WooCommerce orders are unchanged. Edits may overlap other reservations in this development version: do not interpret a successful save as available capacity or fulfillment approval. Review host PHP/database logs for unexpected errors.

Current schema/API details: [reservation storage](reservation-storage.md). The existing one-second datetime precision means two saves within one second can share a displayed modified time; revision still increments separately for each real change.

## Release handoff

Commit message: `Fix administrative reservation editing for version 0.3.1`

Exact SFTP source folder:

`C:\Users\RandyNewby\Documents\GitHub\bike-rental-plugin\bike-rental-plugin\`

Destination: `wp-content/plugins/bike-rental-plugin/`

Main file: `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`

Upload the entire inner folder, not the repository root or tests/docs. No build or schema migration is needed. Avoid returning to 0.3.0 for normal use: it shares the schema but labels snapshots as original and restores the old editing restrictions; rolling back code cannot recover overwritten snapshot revisions.

Price accessor reference: [WooCommerce WC_Product::get_price()](https://woocommerce.github.io/code-reference/classes/WC-Product.html#method_get_price).
