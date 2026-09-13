# Verification — version 0.3.0

Milestone 3 implementation verified locally on **2026-09-12**. Milestones 1 and 2 were previously confirmed working by the user on the dedicated WordPress test site. Milestone 3's dedicated-site SFTP/browser acceptance is **pending**; no remote site was accessed or deployed to.

## Automated results

**377 checks pass:**

| Suite | Checks | Environment |
|---|---:|---|
| `tests/foundation.php` | 113 | WordPress API doubles, PHP 8.3.33 |
| `tests/packages.php` | 95 additional | WordPress/WooCommerce API doubles, PHP 8.3.33; includes the 113 foundation checks |
| `tests/reservations.php` | 159 | Real WordPress 6.8.3, WooCommerce 10.2.2, MariaDB 11.4.8, PHP 8.3.33 |
| `tests/reservations-without-woocommerce.php` | 10 | Separate PHP process against the same real database after WooCommerce deactivation |

Syntax checks cover all runtime and test PHP files; `git diff --check` passes. There is no browser automation claim or completed host/version compatibility matrix.

The integration environment was an isolated disposable WordPress installation under the Windows temporary directory, with MariaDB bound only to `127.0.0.1:33316`, a non-default `m3_` table prefix, and no production/customer data. The database archive was downloaded from the official MariaDB archive and matched its published SHA-256 checksum. No test dependencies, database files, credentials, logs, or WordPress/WooCommerce installations are included in the repository or SFTP upload.

WordPress 6.8.3 and WooCommerce 10.2.2 were deliberately used as a compatible pinned test pair. The unversioned WooCommerce installer rejected that WordPress version because its current package required WordPress 7.0; that was a test-environment version mismatch, not a rental-plugin compatibility finding. The actual dedicated site's version combination still requires acceptance testing.

## Coverage

- Both actual prefixed tables exist and are InnoDB; exactly two owned tables are created.
- Schema option, repeat initialization, versioned repair of a missing index, preserved data/settings/product metadata, and capacity seed uniqueness.
- Fleet quantity updates persist across upgrades and separate PHP processes; block create/edit/disable, indefinite end, creator, quantity/date/reason/active validation, and protection of row 1.
- Real reservation creates/reads/edits, unique references and non-null request-key database constraints, nullable future fields, active-package/price checks, bad IDs, quantity and interval rejection.
- Regular-price/duration/local-time snapshots survive real WooCommerce changes and schedule edits. Preparation/turnaround buffers expand UTC occupied intervals correctly.
- All six statuses, legal/illegal transitions, revision increments, same-status no-op, stale requests, cancellation/completion retention, and terminal record protection.
- UTC/local round trips, winter/summer offsets, invalid dates, DST gaps/repeated hours, quarter-hour and fixed offsets, invalid configured timezone, and timezone changes while a form was open.
- Actual WordPress nonce generation/verification, operation/record binding, unauthorized internal/UI writes, administrator and real shop-manager access, malformed IDs/pagination, escaped detail values, and native PHP page rendering.
- Commit failure rollback, schema lock/verification failure without false success, retry recovery, and two real database connections proving capacity/block writers respect the shared row lock.
- Deactivation of WooCommerce leaves existing fleet/reservations/snapshots readable; new creation returns an actionable error. Reactivation/uninstall entry points preserve all business data.
- Source checks exclude public booking/checkout/remote integration, product stock mutations, and customer branding. Existing package queries remain WooCommerce API based. The old Milestone 2 global prohibition on `$wpdb` was narrowed because Milestone 3 explicitly authorizes custom tables; package-specific direct SQL remains prohibited and checked.

Intentional limits: no available-quantity/overlap algorithm, package-duration scheduling, automatic hold expiry, customer workflow, payment/deposit/refund/callback handling, waivers, email, or fulfillment readiness. No financial or legal integration acceptance is claimed.

## Reproducing checks

Run existing dependency-free checks with PHP 8.3+:

```powershell
$phpPath = 'C:\path\to\php.exe'
& $phpPath -n .\tests\packages.php
if ($LASTEXITCODE -ne 0) { throw 'Foundation/package checks failed' }
```

The integration scripts write fixtures and deliberately test index repair. **Never run them against a business/test-site database containing real data.** They refuse any database except `brp_m3_disposable` at `127.0.0.1:33316` with prefix `m3_` and require an explicit environment opt-in. Provision a disposable WordPress install using those identifiers, with administrator ID 1 and WooCommerce active. Use the WordPress site's own config for local database access; no credentials belong in this repository. PHP needs mysqli and the normal WordPress/WooCommerce extensions, including mbstring.

```powershell
$phpPath = 'C:\path\to\php.exe'
$testIni = 'C:\path\to\integration-php.ini'
$env:BRP_TEST_WP_ROOT = 'C:\path\to\disposable-wordpress'
$env:BRP_ALLOW_DISPOSABLE_TESTS = '1'
& $phpPath -c $testIni .\tests\reservations.php
if ($LASTEXITCODE -ne 0) { throw 'Reservation integration checks failed' }
# Deactivate WooCommerce on this disposable installation, using its admin or local WP-CLI.
& $phpPath -c $testIni .\tests\reservations-without-woocommerce.php
if ($LASTEXITCODE -ne 0) { throw 'Missing-WooCommerce checks failed' }
# Reactivate WooCommerce afterward if continuing tests.
```

The first integration script resets test settings, creates sample products/reservations/blocks, and stores verification pointers for the second script. Existing fixture rows are retained; repeated runs add fixtures. Some migration/constraint/locking tests intentionally produce database errors in the **disposable** WordPress debug log. Assertions verify the expected error handling; do not upload these logs. Stop the disposable database process when finished.

## Dedicated test-site acceptance — pending

Record the site WordPress, PHP, database, WooCommerce, Divi, and plugin versions along with results. Use the existing dedicated test site and a database backup.

1. Record existing 0.2.0 settings, rental package metadata, and WooCommerce regular prices. Upload only the inner `bike-rental-plugin/` folder through SFTP during a maintenance window.
2. Visit administration without reactivating. Confirm plugin **0.3.0**, schema option `brp_db_version = 1`, and no database error notice. Confirm old settings and packages remain intact.
3. Confirm **Bike Rentals > Fleet** and **Bike Rentals > Reservations** appear for administrator/shop manager and are denied to a customer/subscriber.
4. Set total fleet to **10**, save/reload, and confirm **10** persists.
5. Add a block with quantity **2**, valid future local start/end, reason **Test Maintenance**, active. Save/reload and verify times in the configured WordPress timezone.
6. Edit its quantity/time/reason; verify persistence. Disable it; confirm the row remains marked Disabled. Also test an indefinite end and invalid quantity/date/reason rejection.
7. Create a manual test reservation using an active package, quantity **2**, future local start/end, status **hold**. Confirm it appears in the list; open detail.
8. Confirm original package ID/name, regular price/currency, duration, promotional label, local dates/timezone, and buffer snapshot. Confirm current/occupied times and revision **1**.
9. Change the WooCommerce package regular price and duration. Reload the old reservation; confirm its original snapshot is unchanged. Confirm a new test reservation captures new values.
10. Change the first reservation to **confirmed**. Confirm revision increases to **2**. In another tab try submitting the older form and confirm rejection.
11. Change confirmed to **cancelled**. Confirm revision **3**, retained record, and terminal status. Separately test confirmed → active → completed retention.
12. Edit the quantity/schedule of a hold/confirmed record and confirm revision increments, captured buffers are reused, and original snapshot remains unchanged.
13. Inspect the configured-prefix tables in the host database tool: exactly the two plugin tables, InnoDB, documented columns/indexes, UTC intervals, unique capacity row 1. See [schema](reservation-storage.md).
14. Deactivate/reactivate the rental plugin. Confirm fleet, blocks, reservations, settings, and packages remain. Re-upload the same complete directory and verify idempotence without row loss or capacity reset.
15. Temporarily deactivate WooCommerce on this dedicated test site. Confirm fleet/list/detail still work and new reservation creation displays guidance; reactivate WooCommerce.
16. Submit missing/invalid nonces and unauthorized requests through test tooling; confirm no mutation. Confirm browser POST/redirect behavior and displayed errors are usable.
17. Confirm no public rental booking form/calendar appeared, no Square/product stock synchronization occurs, and there are no checkout/payment/deposit/waiver/email integrations.
18. Review the host PHP/database logs for unexpected errors. Record the commit/version and acceptance result before considering the milestone deployed/tested.

Upload source: `C:\Users\RandyNewby\Documents\GitHub\bike-rental-plugin\bike-rental-plugin\`

Destination: `wp-content/plugins/bike-rental-plugin/`

Main file after upload: `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`.

Do not upload the repository root, `.git`, `tests`, `docs`, temporary verification environment, or credentials. No server build tools or database CLI are required for ordinary deployment. Keep the previous directory and a database backup for rollback; tables are preserved by design. **Milestone 4 has not started.**

## Implementation references

- [WordPress table creation and dbDelta upgrades](https://developer.wordpress.org/plugins/creating-tables-with-plugins/)
- [WordPress prepared queries and identifier placeholders](https://developer.wordpress.org/reference/classes/wpdb/prepare/)
- [MySQL named lock behavior](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html)
- [Official MariaDB verification archive](https://archive.mariadb.org/mariadb-11.4.8/winx64-packages/)
