# Bike Rental Plugin 1.0.2 release verification

Runtime: **1.0.2**. Database schema: **2**, unchanged. Work completed on `main`; no push or deployment.

## Admin changes and dead code

Fleet retains total capacity display, its nonce-protected save action, and a **Manage Availability Blocks** link. It has no block form or block-edit controls. Availability exclusively renders block creation, editing and listing, including timed, all-day, multi-day and indefinite records. Existing records and disable actions remain available. Expired-hold cleanup remains. Admin navigation names are unchanged.

Removed the checker heading/form/button, `availability_test` dispatch operation, checker result notices, checker-only input options and duplicate-page return routing. Renamed `fleet-page.php` to `availability-page.php`; calendar block links now open Availability. No checker-exclusive JavaScript or CSS existed; shared block controls and assets remain unchanged.

## Automated verification

- Foundation/package: 209 passed (`php -n tests/packages.php`).
- Reservation presentation cleanup: 35 passed (`php -n tests/production-cleanup.php`).
- Block input, real Fleet/Availability services with in-memory database doubles, rendering and calendar: 86 passed (`php -n tests/availability-ui.php`). Includes capacity increase/decrease, create/edit, disabled-record visibility, removed checker rejection, authorization, overlap, DST and half-open boundaries.
- Chrome/Playwright over PHP-rendered fixtures: 18 passed (`node tests/availability-ui-browser.cjs`). Includes Fleet controls/navigation, absent checker, all-day/indefinite toggles, keyboard editing and narrow layout. Desktop screenshot inspected.
- License enforcement configuration: 12 passed (`php -n tests/license-enforcement.php`).
- **Total: 360 passed.** PHP lint: 69 files. JavaScript syntax: 11 files. `git diff --check` passed.

These are local isolated checks, not full WordPress integration or database concurrency acceptance. Updated guarded integration expectations in availability/reservation/licensing tests; those suites were not executed because no disposable WordPress/database fixture is configured (`BRP_TEST_WP_ROOT` unset).

## Preservation evidence

Compared runtime files against 1.0.1: all 41 files outside the seven changed/new runtime files match after line-ending normalization. `Availability.php`, `Fleet.php`, `Reservations.php`, `Database.php`, `RentalTime.php`, `AdminCalendar.php`, public booking/API and assets, product deep links, rider collection, checkout, Square/payment, cart cleanup, waiver and licensing implementations are unchanged. No schema/storage migration or business behavior change was introduced. Calendar presentation changes only its block edit destination; allocation and capacity calculations are unchanged. Runtime version reporting now identifies 1.0.2.

## Required deployment-site QA

1. On disposable staging, run the guarded database/integration/concurrency suites sequentially, including reservation validation, public API and calendar capacity, checkout, waiver and license-client tests.
2. Verify real WordPress Fleet capacity save and rejected invalid capacity; follow Manage Availability Blocks. Confirm no duplicate form even when opening an old Fleet block bookmark.
3. Create/edit/disable timed, all-day, multi-day and indefinite blocks; confirm existing records, pagination, redirects, notices, calendar links and occupied totals. Confirm the checker is absent and its old action is rejected.
4. Exercise public booking, valid/invalid product deep links, rider collection, quantity limits, overlapping holds and expiry.
5. Complete WooCommerce/Square test checkout; verify payment callbacks, retries, cancellation and cart cleanup without double allocation.
6. Verify adult/minor waiver signing, pending-waiver progression, invitations/mail, exemptions and stored evidence with the configured WPForms provider.
7. Verify active/expired/offline-grace license enforcement and continued access to existing records; inspect real theme/mobile/admin layout.

No live payment, email, signing, controller or production-data operation was performed.

## Package and SFTP

ZIP: `.release/production/bike-rental-plugin-1.0.2.zip`.

SHA256: `1d0455a9b6160b19cedf55eb9124127e14801c83e1f48f6965745397de280faa`.

`python tools/package.py` verified 48 runtime-only entries, archive CRC, one `bike-rental-plugin/` root and every entry against source. No tests, dependencies, development artifacts or NT License Controller are included.

Exact SFTP destination relative to the site's WordPress root: **`wp-content/plugins/bike-rental-plugin/`**. Upload the complete inner plugin folder in a maintenance window. Remove the obsolete `src/fleet-page.php` from an existing installation when replacing files; its replacement is `src/availability-page.php`. The host-specific absolute document root is not supplied. Schema stays 2; no database replacement is required.

Commit message: `fix: simplify Fleet and Availability admin pages in 1.0.2`. The final response records the actual hash (a commit cannot embed its own hash). Stop at 1.0.2; do not push.

## Files changed

- `CHANGELOG.md`
- `README.md`
- `bike-rental-plugin/bike-rental-plugin.php`
- `bike-rental-plugin/readme.txt`
- `bike-rental-plugin/src/BlockInput.php`
- `bike-rental-plugin/src/DataAdmin.php`
- `bike-rental-plugin/src/Plugin.php`
- `bike-rental-plugin/src/availability-page.php`
- `bike-rental-plugin/src/calendar-page.php`
- `bike-rental-plugin/src/fleet-page.php`
- `docs/admin-cleanup-1.0.2.md`
- `tests/availability-ui-browser.cjs`
- `tests/availability-ui.php`
- `tests/availability.php`
- `tests/foundation.php`
- `tests/licensing-client.php`
- `tests/production-cleanup.php`
- `tests/reservations.php`
