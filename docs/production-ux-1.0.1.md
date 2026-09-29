# Bike Rental Plugin 1.0.1 UX release

Runtime **1.0.1**, schema **2**, directly on `main`. No push or deployment. This release removes direct admin reservation creation and simplifies inventory-block inputs. No database migration.

## Reservation creation and management

New reservations are created through the **public booking flow**, including staff-assisted bookings. Reservations administration displays this guidance so riders, payment, and waiver information follow the same workflow. There is no configured booking-page URL in the plugin, so no guessed link or customer-specific URL was added.

Removed the Create Reservation form, help text, `reservation_create` action, form idempotency-key generation, and the creation-only `Reservations::create()` / `create_hold()` entry points. No JavaScript/CSS was exclusive to that form. The supported public `create_booking_hold()` path and its rider/schedule/identity validation remain unchanged. Shared private allocation and validation methods remain because public booking uses them.

Existing reservation list/detail, revision-safe editing, status changes, cancellation, eligible permanent deletion, rider/waiver management, and order links remain. Former direct-creation wrappers now exist only in a guarded repository test fixture to seed historical states and retain allocation/concurrency regressions. That fixture is not shipped or loaded by runtime.

## Availability form architecture

`BlockInput` renders and validates the shared date/time controls. `DataAdmin` checks capability, nonce, ID, and timezone before conversion, then calls the existing `Fleet::save_block()` with its original canonical input structure. `Fleet`, `RentalTime`, and `Availability` are unchanged.

- **Availability** now includes Add Availability Block, existing blocks, and editing. **Fleet** keeps the same shared block interface beside capacity management.
- Start Date and End Date are native date-only fields. Start Time and End Time are selects with 12-hour AM/PM labels for the entire day, following the configured Booking Time Increment: 5, 10, 15, 20, 30, or 60 minutes.
- The site's WordPress timezone is shown and used. The server validates dates and supported time choices, recomposes local endpoints, and invokes the existing strict UTC conversion. Legacy submitted `start`/`end` fields cannot override the date controls.
- An existing off-grid time is included in that endpoint's edit choices, so changing the configured increment does not round old minute-based block times.
- Quantity and required Reason use existing positive-integer/capacity and sanitization rules. Existing inventory lock, overlap checks, active state, disable action, and pagination remain.
- The point-in-time availability diagnostic uses the same date/time controls. It remains separate from block creation and does not create records.

## All-day, multi-day, and edit semantics

**All Day** disables the time selects. Start Date and End Date are inclusive local dates: December 24 through December 26 becomes `[December 24 00:00, December 27 00:00)` in the WordPress timezone, converted to UTC by the existing converter. Equal dates block one day. The end boundary is exclusive, preserving adjacent-block behavior.

Days advance by calendar date, not by adding 24 hours. A DST transition day can span 23 or 25 elapsed hours. Nonexistent/repeated local endpoints, including rare midnight transitions, remain rejected under existing strict conversion rules. No opening hours are invented or used to shorten a full-day block.

Timed blocks can span one or several dates, with end strictly after start. **No end date (until disabled)** preserves existing indefinite-block behavior and cannot be combined with All Day. It disables only the end controls. All-day and indefinite selections are mutually exclusive in both JavaScript and server validation.

Create and edit use the same renderer/converter. Stored exact midnight-to-midnight blocks are recognized as All Day and shown with an inclusive end date; saving the loaded dates does not add another day. No persistent all-day flag is added. Existing minute-resolution input behavior remains; stored records are not migrated or bulk rewritten.

Lists show interval, quantity, reason, state, and actions. Weekly Calendar uses the same All Day/AM-PM interval labels while its UTC bar endpoints, position calculation, block queries, and capacity totals remain unchanged. Calendar edit links continue to work through Fleet.

## Verification

**349 passing automated checks**, excluding duplicate runs:

| Suite | Passing checks | Evidence scope |
| --- | ---: | --- |
| `tests/packages.php` | 209 | Foundation (114) and package (95) API-double regressions |
| `tests/production-cleanup.php` | 35 | Actual reservation template/DataAdmin rendering, existing records, privacy, removed action/helpers, access control, schema marker |
| `tests/availability-ui.php` | 77 | Actual BlockInput, RentalTime, DataAdmin, Fleet, Availability, and calendar template with explicit in-memory database/API doubles |
| `tests/availability-ui-browser.cjs` | 16 | Headless Chrome against PHP-rendered controls and shipped block-admin.js; native controls, AM/PM, toggles, serialized submission, isolated forms, keyboard, edit and narrow viewport |
| `tests/license-enforcement.php` | 12 | Isolated enforcement default/override across environment types |

Also passed: PHP syntax for **69** runtime/test files, Node syntax for **4** runtime scripts and **7** browser suites, Git whitespace validation, and ZIP verification of all **48** runtime files. Local PHP is 8.5.1. Browser markup uses explicit API doubles and a small layout harness, not the full WordPress admin theme. A desktop screenshot was inspected; it shows separate date/select controls and disabled times for All Day.

The disposable WordPress/MariaDB fixture and separate controller checkout remain unavailable; `BRP_TEST_WP_ROOT` / controller configuration are unset. Real WordPress, database concurrency, CPT/HPOS checkout, and controller integration suites were **not run**. Their tests remain in source control with their original safety guards. No prior release counts are represented as current passes. No real Square charge, WPForms signature, or email delivery is claimed.

## Requested coverage and remaining regression work

| Requested coverage | Status |
| --- | --- |
| No admin create form/action; existing list; public replacement workflow | Passing cleanup checks; runtime contains no removed create handler/helpers. No optional link because no URL setting exists. |
| Existing edits, cancellation, permanent deletion | Edit rendering passes; mutation paths unchanged. Real reservation-editing and waiver-refinements safeguards retained, pending fixture. |
| Date fields, dropdowns, AM/PM, configured increment | Passing PHP and Chrome checks. |
| Same-day/multi-day timed saves; single/multi-day all-day saves | Passing actual service pipeline with database doubles, including UTC and inclusive-end assertions. |
| Invalid dates/order/quantity/capacity/reason/time/flags/timezone/nonce | Rejected without writes in local checks; authorization retained. |
| Shared edit controls, existing values, off-grid time preservation | Passing PHP/browser checks, including existing all-day and indefinite records. |
| Timed/all-day calendar display | Actual calendar template checks pass for labels, bar presence, and capacity headings; rendering does not write records. |
| Half-open intervals and capacity calculations | Actual unchanged sweep engine exercised with in-memory records; adjacent blocks fit and overlaps reject. Fleet/Availability source unchanged. |
| Concurrency | Original guarded tests/workers retained using repository-only fixture wrappers; not executed without database fixture. |
| Schema | Database.php unchanged; schema remains 2. |
| Public booking, deep links, branding, riders, waivers, checkout, Square, cart cleanup | Runtime implementations unchanged. Foundation/package checks pass; real integration and existing public browser suites await their fixtures/site acceptance. |
| Licensing | License implementation unchanged; 12 enforcement checks pass. Client/controller integration remains pending. |

The fixture wrapper uses reflection only inside the guarded disposable test suite to exercise shared private allocation services and create past/terminal test states. It is not a production fallback for manual booking and does not bypass the services' permission, license, validation, or locking checks.

## Manual QA before production acceptance

1. Confirm Plugins shows 1.0.1 and `brp_db_version` remains 2. Visit Reservations: no creation form; guidance points staff to public booking. Confirm a valid old `reservation_create` POST cannot insert a row.
2. Open existing reservations; save edits, test stale revision rejection, cancel eligible records, and verify permanent deletion safeguards, rider/waiver controls, and Woo order links.
3. In Availability and Fleet, create a same-day timed block and a multi-day timed block. Confirm the site's AM/PM options, configured increment, reason, quantity, and resulting occupied times.
4. Create a one-day All Day block with equal dates, then a multi-day closure. Confirm time controls disable and the final selected date is fully included without occupying the following day.
5. Edit old timed/off-grid/all-day/indefinite blocks. Confirm values, labels, dates, state, and Disable work, and the page returns to the same administrative section.
6. Reject malformed dates, end-before-start, invalid time choices, excessive quantity, overlapping capacity, missing reason, stale timezone, and unauthorized submissions. Use disposable records for negative/concurrency testing.
7. Verify timed/all-day bars and daily capacity in the Calendar, including adjacent boundaries, operating timezone, and DST. Confirm reservation preparation/turnaround behavior is unchanged.
8. Complete public booking, product deep links, branding, rider collection, waiver signing/resend/progress, Full Payment/Square sandbox checkout, cart cleanup, and license enforcement checks on the configured deployment site.

Known limitations: real database/concurrency/provider acceptance is pending; JavaScript is needed for dynamic disabling of time controls; all-day classification is inferred from exact local midnight boundaries; rare invalid/ambiguous midnight transitions require a valid timed interval under existing policy. No morning/afternoon presets were added. Native date input appearance follows the staff browser locale; time labels remain AM/PM.

## Packaging and deployment

ZIP: `.release/production/bike-rental-plugin-1.0.1.zip`. Contains **48** runtime files only. Tests, test-only reservation fixture wrappers, local Playwright dependencies, logs/screenshots, developer docs, and NT License Controller are excluded. All ZIP file bytes match source; CRC and root-directory checks passed.

Exact SFTP destination: **`wp-content/plugins/bike-rental-plugin/`**, relative to the rental site's WordPress root. Main file: `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`. The host-specific absolute root is not provided. No deployment performed.

Public booking behavior, waiver processing, payment/Square integration, license enforcement, reservation management guards, and inventory allocation are unchanged. NT License Controller remains separate and untouched.

Commit directly on `main` with `fix: simplify availability blocks and remove admin reservation creation in 1.0.1`. The completion response records the actual Git hash. No push authorized or performed.

## Files changed

- `CHANGELOG.md`
- `README.md`
- `bike-rental-plugin/assets/js/block-admin.js`
- `bike-rental-plugin/bike-rental-plugin.php`
- `bike-rental-plugin/readme.txt`
- `bike-rental-plugin/src/BlockInput.php`
- `bike-rental-plugin/src/DataAdmin.php`
- `bike-rental-plugin/src/Plugin.php`
- `bike-rental-plugin/src/Reservations.php`
- `bike-rental-plugin/src/calendar-page.php`
- `bike-rental-plugin/src/fleet-page.php`
- `bike-rental-plugin/src/reservations-page.php`
- `docs/production-release-1.0.0.md`
- `docs/production-ux-1.0.1.md`
- `docs/reservation-storage.md`
- `docs/reservations-verification.md`
- `tests/active-reservations.php`
- `tests/availability-concurrency.php`
- `tests/availability-ui-browser.cjs`
- `tests/availability-ui.php`
- `tests/availability.php`
- `tests/foundation.php`
- `tests/inventory-test-bootstrap.php`
- `tests/inventory-worker.php`
- `tests/licensing-client.php`
- `tests/production-cleanup.php`
- `tests/public-booking.php`
- `tests/reservation-editing.php`
- `tests/reservation-fixture.php`
- `tests/reservations-without-woocommerce.php`
- `tests/reservations.php`
