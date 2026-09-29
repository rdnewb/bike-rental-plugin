# Bike Rental Plugin 1.0.0 production cleanup

> Historical developer record. Version 1.0.1 removes direct admin reservation creation and replaces block inputs. For current staff workflows and verification, see [1.0.1 UX release](production-ux-1.0.1.md).

Release work on `main`, 2026-09-29. Runtime 1.0.0; schema 2 unchanged. No push or deployment. The cleanup and local package are complete; deployment acceptance and the unavailable integration suites remain outstanding.

## Changes and preservation

- Removed the reservation snapshot heading, raw JSON, obsolete history explanation, request/session association display, visible revision number, and order-item implementation ID. Snapshot storage, agreed terms, hidden edit revision, and validation are preserved.
- The former “Create manual test reservation” form already used the real `reservation_create` operation. It is now **Create Reservation**. There was no separate fake generator endpoint, AJAX route, shortcode, query flag, or bundled CLI generator to delete. Package, quantity, dates, status, permissions, nonce, license, capacity, and inventory-lock checks are unchanged. Dates remain required and blank; quantity 1 and Hold are operational defaults, not fabricated customer data.
- Kept the real confirm-hold action, availability diagnostic, and expired-hold cleanup. Renamed Availability test to Availability; retained its internal `availability_test` operation for compatibility because it performs a read-only capacity check, not a booking generator.
- Removed the raw WPForms diagnostic JSON panel. Kept provider availability/configuration, mappings, legal version, signing-page configuration, invitation resends, waiver progress, and boolean-only opt-in support records. Updated the provider guide to explain developer access to the transient.
- Replaced stale package “booking not implemented” claims, future list wording, integration-history copy, compatibility-mode wording, and the public photo promise. A missing image now says **No rental photo available**.
- Reservation list/edit, calendar, public receipt, and waiver status labels use readable words. Stored status codes and API protocol values remain unchanged.
- Rewrote README/readme around current capabilities and deployment. Changelog release headings use feature names. Detailed historical verification records remain repository-only.
- Removed three empty `.gitkeep` files; strengthened packaging to reject unexpected files and symlinks and to include only runtime files. No other settings, hooks, classes, or assets became dead after removing the display panels. No speculative refactoring or data deletion.

PaymentMode, Payments, Checkout, CheckoutReservation, CartHolds, Database, License, Waivers, WaiverSettings, WaiverEmail, GuestSession, and inventory/scheduling algorithms have no behavioral changes. Reservations changes are display formatting and error copy only. WPForms changes remove output only; signing, proof validation, token rotation, and completion are unchanged. WaiverUI changes are status labels only. LicenseAdmin changes are explanatory copy only. Square credentials, licensing protocol/enforcement, and protected evidence storage are unchanged.

## String and sensitive-output audit

Repository-wide searches covered case-insensitive milestone, test reservation, development placeholder, placeholder, demo, sample, debug, TODO, FIXME, future milestone/phase, testing, development, developer, mock, sandbox, temporary, phase, and stub. A second pass covered future/deferred/not implemented/not yet/coming soon, raw JSON, hashes, tokens, request/session values, callbacks, and visible status rendering.

| Occurrences | Disposition and reason |
| --- | --- |
| Runtime DataAdmin milestone banner; reservation test labels and settings error | Rewritten as operational administration and booking copy. No milestone wording remains in the runtime directory or distributed readme. |
| Reservation snapshot block, request/session association paragraph, visible revision/order-item ID | Removed from normal UI; no storage or snapshot write code removed. |
| Raw waiver diagnostics panel | Removed; provider status and developer-only boolean transient retained for troubleshooting. |
| Settings foundation/future-checkout and untested-integration copy | Rewritten to current dependency requirements and “Check provider settings.” Presence detection still does not claim configuration or integration success. |
| Package future-list/booking-unavailable claims; image “coming soon” | Rewritten to current booking behavior and a neutral missing-image label. |
| License compatibility-mode wording | Rewritten to accurately describe the existing site-configuration override without weakening enforcement. |
| README/readme milestone/phase, unfinished-roadmap, test-tools, manual-test labels | Replaced by current feature/deployment documentation. Earlier detailed history remains in the repository changelog and developer records. |
| Changelog milestone/phase release headings and roadmap stop instructions | Rewritten. The historical `docs/milestone-6a-verification.md` path is retained as a valid developer reference. Historical test/sandbox/development descriptions explain prior releases, not current UI. |
| `placeholder` attributes in branding/general fields | Retained: legitimate example input hints. |
| Waiver invitation placeholders and their validation text | Retained: supported template substitutions, not unfinished features. |
| Temporary holds/blocks and temporary unavailability | Retained: accurate operational states and recovery messages. |
| `PublicBooking::diagnose_times`, support comments, `BRP_WAIVER_DIAGNOSTICS` | Retained: protected PHP diagnosis and opt-in boolean support observations; no public diagnostic route or raw UI panel. |
| Internal `availability_test` operation and independently testable comments | Retained: useful capacity diagnostic and maintenance descriptions, not fake reservation creation. |
| Tests: sample products, mock transport, test reservations, historical filenames, negative assertions | Retained: automated infrastructure explicitly required in source control, excluded from ZIP. |
| Developer docs: milestone/phase records, testing instructions, sandbox instructions, debug observations | Retained: historical or technical verification material outside runtime/distribution. README identifies their historical scope. |
| `latest reservation` substring matches | Retained: ordinary comments, not test-reservation functionality. |
| TODO/FIXME/stub or demo generator in runtime | None found. |

No new raw hashes, license secrets, payment credentials, signature URLs, provider callbacks, or existing request identifiers are rendered. Operational order links, provider submission references and exemption attribution remain useful for authorized staff. Required hidden nonces/revisions, fresh manual-booking idempotency keys, REST CSRF values, invitation URLs, and protected signing tokens remain part of the security protocol; they are not printed as diagnostics. The License tab retains masked keys and non-secret server response codes for support.

## Verification actually completed

**255 passing automated checks**, without counting duplicate runs:

| Command | Result |
| --- | --- |
| `php -n tests/packages.php` | 209: 114 foundation + 95 package checks, using explicit API doubles |
| `php -n tests/production-cleanup.php` | 34 rendering, privacy, menu, required-input, rejected-operation, permission, source-copy, and schema checks, using explicit API doubles |
| `php -n tests/license-enforcement.php` | 12 isolated default/true/false policy cases across local/development/staging/production environments |
| PHP lint | 66 runtime/test PHP files passed on local PHP 8.5.1 |
| Node syntax checks | Three runtime scripts and six browser suites passed |
| `git diff --check` | Passed |
| `python tools/package.py` | 46 runtime files; CRC, root directory, allowed paths, and every file's source bytes verified |

The WordPress/database fixture is not installed in this workspace or the searched local fixture locations. `BRP_TEST_WP_ROOT` is unset; no local MariaDB/MySQL/Docker command or separate controller checkout was available. Real database, concurrency, CPT/HPOS, browser-fixture, and cross-repository licensing suites were **not run**. Guards were not weakened. PHP 8.3 compatibility and real host/provider behavior are not established by the local PHP 8.5 lint run. Prior release counts are not counted as current passing tests.

## Required coverage map and pending runs

The automated suite remains intact. Updated assertions remove the old expectation that snapshot JSON/prices render and add persistent snapshot preservation plus rejected generator/no-insert checks. Existing suites cover the business workflows below but require their guarded fixture before current-release acceptance.

| Requirement | Coverage / current result |
| --- | --- |
| 1. No raw snapshot in admin | New cleanup rendering checks pass; real rendering assertion updated in reservation-editing/reservations suites, pending |
| 2. Snapshot remains stored | Rendering leaves fixture row unchanged; real database read-back assertions added, pending |
| 3. No runtime Milestone UI | Runtime/readme source guard passes |
| 4. Test button removed | Real DataAdmin renders Create Reservation in API-double check; runtime audit passes |
| 5. Manual booking works | Existing validated operation preserved; reservations/availability suites pending |
| 6. No fake endpoint creation | Unsupported generator dispatches reject in local checks; new real no-insert assertions pending; no separate prior generator endpoint existed |
| 7. Revision-safe editing | Rendered controls preserved locally; reservation-editing suite pending |
| 8. Calendar | Existing milestone7a and calendar suites retained, pending |
| 9. Booking shortcode | Public-booking/product-grid suites retained, pending |
| 10. Product deep links | milestone7a PHP/browser suites retained, pending |
| 11. Branding | Foundation settings checks pass; booking-branding PHP/browser suites pending |
| 12. Full Payment | checkout/checkout-store-api suites retained, pending; Square acceptance remains required |
| 13. Cart cleanup | cart-holds plus product-grid browser suite retained, pending |
| 14. Rider collection | waivers/waiver-refinements and rider-booking browser suites retained, pending |
| 15–16. Waivers and admin tracking | Waiver suites retained, pending; real provider/mail acceptance required |
| 17. License enforcement | 12 policy checks pass; licensing-client integration pending |
| 18. Existing records accessible | Existing detail fixture renders; real reservations-without-woocommerce assertion updated, pending |
| 19. Cancelled deletion | waiver-refinements safeguards retained, pending |
| 20. ZIP excludes controller | Verified runtime-only ZIP; no server/controller files |
| 21. No sensitive output | Rendered marker/hash checks and source review pass; real reservation privacy checks updated, pending |
| 22. Schema unchanged | Source assertion passes; Database.php byte-identical to prior commit, schema 2 |

The product-grid browser suite now checks all four restored paid/confirmed status labels, including **Pending Waivers**. It passes syntax validation only; its fixtures are unavailable for browser execution.

## Manual deployment QA still required

- Public booking: verify clean copy, no generator/debug controls, normal package/date/quantity/rider selection, deep links, Change Rental, branding, hold expiry, and checkout.
- Reservation detail: open existing records, confirm no raw JSON or request/session metadata, save edits, exercise stale-revision rejection, inspect order links and rider/waiver tracking.
- Reservation list: inspect friendly statuses, package details, references, and navigation.
- Calendar: verify existing reservations, blocks, status/waiver progress, capacity, buffers, timezone, and navigation on desktop/mobile.
- Settings: save each tab, confirm other tabs are preserved, verify dependency/provider diagnostics and no development panels.
- Waivers: use the real configured provider for adult/minor signing, invitations/resends, legal version, admin progress, protected evidence, and signing-page access.
- License: verify activate/deactivate/check/status/grace, denial of new bookings without entitlement, and continued access to existing records.
- Manual reservation: create a real intended booking with explicit dates, test capacity/status/date rejection on a disposable copy, confirm inventory locks and no automatic fake details. Verify eligible cancelled deletion and blocked deletion of protected records.
- Payment/cart: verify Square sandbox capture, decline/retry, duplicate callbacks, late-payment conflict handling, cart removal, and CPT/HPOS behavior as applicable. No live payment is claimed.

Known limits retained: unsupported deposit providers remain blocked, snapshots do not provide separate prior-revision history, and site-specific WPForms/Square/mail/theme acceptance remains necessary. No SFTP deployment was performed.

## Release package and source-control record

- ZIP: `.release/production/bike-rental-plugin-1.0.0.zip` (ignored local artifact).
- Upload the inner directory to **`wp-content/plugins/bike-rental-plugin/`** relative to the rental site's WordPress root. The host-specific absolute SFTP document root is not provided; do not guess it.
- NT License Controller remains separate and is not included or modified.
- Commit message: `chore: prepare Bike Rental Plugin 1.0.0 for production`. Obtain the exact commit using `git log -1 --format=%H`; the completion response records it. No push authorized or performed.

Changed-file manifest is recorded in this commit: runtime version/readme; administration, reservation, package, waiver and public display copy; booking status JavaScript; three removed `.gitkeep` files; README/CHANGELOG and this report/provider guide; version/privacy/manual-form/browser regression assertions; the new cleanup suite; and the package builder. Automated test infrastructure is retained and not distributed.

## Files changed

- `CHANGELOG.md`
- `README.md`
- `bike-rental-plugin/assets/css/.gitkeep`
- `bike-rental-plugin/assets/js/.gitkeep`
- `bike-rental-plugin/assets/js/booking.js`
- `bike-rental-plugin/bike-rental-plugin.php`
- `bike-rental-plugin/languages/.gitkeep`
- `bike-rental-plugin/readme.txt`
- `bike-rental-plugin/src/DataAdmin.php`
- `bike-rental-plugin/src/LicenseAdmin.php`
- `bike-rental-plugin/src/Plugin.php`
- `bike-rental-plugin/src/PublicBooking.php`
- `bike-rental-plugin/src/Reservations.php`
- `bike-rental-plugin/src/WPFormsWaiverProvider.php`
- `bike-rental-plugin/src/WaiverUI.php`
- `bike-rental-plugin/src/booking-form.php`
- `bike-rental-plugin/src/calendar-page.php`
- `bike-rental-plugin/src/package-fields.php`
- `bike-rental-plugin/src/packages-page.php`
- `bike-rental-plugin/src/reservations-page.php`
- `bike-rental-plugin/src/settings-general.php`
- `docs/production-release-1.0.0.md`
- `docs/wpforms-waiver-provider.md`
- `tests/foundation.php`
- `tests/licensing-client.php`
- `tests/product-grid-browser.cjs`
- `tests/production-cleanup.php`
- `tests/reservation-editing.php`
- `tests/reservations-without-woocommerce.php`
- `tests/reservations.php`
- `tools/package.py`
