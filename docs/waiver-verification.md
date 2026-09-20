# Milestone 8 verification and release - 0.8.0

Runtime **0.8.0**, schema **2**, work directly on **main**. Local implementation, regression testing and packaging are complete. **Dedicated-site acceptance is still pending.** The available browser reached the WordPress login screen; no authenticated admin, approved waiver form/text, or authorized test inboxes were supplied. No remote deployment, real email, signature, Square charge or GitHub push was performed.

## Implementation summary

- Schema 2 adds `{prefix}brp_riders` and `{prefix}brp_waivers` as verified InnoDB tables. Reservation/availability columns are unchanged. Existing snapshots without a waiver policy remain waiver-disabled; upgrades do not downgrade old bookings.
- New bookings have one roster position per bike. Adults age 18+ provide name/age/email and self-sign. Minors provide name/age and guardian name/email/relationship; each minor has a separate waiver, even with the same guardian. Minor email is optional.
- Generic provider services own policy, requests, invitations and readiness. WPForms-specific configuration, hooks and stored-entry verification live in one adapter. Additional providers can register through the interface without changing reservation logic.
- Invitations use individual random 256-bit tokens, SHA-256 lookup hashes, seven-day expiry, and 15-minute resend limits. Rotation invalidates old links. Cancellation, expiry, completion and exemption block use. No raw token or signature image is stored in custom tables.
- WordPress mail goes to each adult or guardian. Failed mail remains pending; admin resend requires capability and nonce. Exemptions preserve reason, administrator ID and timestamp.
- Completion verifies the saved provider entry, mapped context, signature reference, acceptance, signer and legal-text hash. Accepted legal text/version and provider reference persist. Duplicate callbacks do not repeat state changes or invitations.
- Verified payment enters **Pending Waivers** when needed, retaining the normal occupied inventory interval. Complete roster + all waivers + verified payment permits **Confirmed**. Normal activation is blocked while incomplete and retains the existing scheduled-start restriction.
- Purchaser collection follows payment, with a Rider 1 purchaser shortcut. Order/thank-you, public receipt and purchaser page show notices and progress. Admin detail/list/calendar and Woo order administration show compact progress; signer views contain only their intended rider.
- **Full Payment amounts, taxes, Square processing and guarded deposit architecture were not changed.** No later milestone started.

## Automated results

**2,745 passing check executions / 2,305 distinct checks.** The 440 repeated checks cover both CPT and HPOS order storage. Counts exclude failed development attempts and repeated seeding runs. PHP syntax passed for **58 files**; booking JavaScript syntax and Git whitespace checks passed.

Environment: disposable local WordPress 7.0, WooCommerce 11.1, PHP 8.3.33 and MariaDB 11.4.8. Browser checks use headless Chrome, PHP-rendered local fixtures and isolated transport. WPForms and Square completion evidence are explicit doubles; these results do not certify actual addon signing or sandbox gateway processing.

| Suite | Checks per run | Runs counted |
|---|---:|---:|
| Foundation + packages | 208 | 1 |
| Storage + editing + availability + active policy | 408 | 1 |
| Calendar-day scheduling matrix | 667 | 1 |
| Public booking | 74 | 1 |
| Multiprocess inventory concurrency | 70 | 1 |
| Full-payment checkout | 96 | 2: CPT/HPOS |
| Store API rental checkout | 21 | 2 |
| Store API ordinary checkout | 4 | 2 |
| Cart-hold cleanup | 47 | 2 |
| Product grid | 37 | 1 |
| Deep links/admin calendar | 93 | 2 |
| Branding | 80 | 1 |
| Settings tabs | 87 | 1 |
| WooCommerce inactive/persistence | 13 | 1 |
| New waiver integration | 179 | 2 |
| Product-grid browser | 76 | 1 |
| Deep-link/calendar browser | 41 | 1 |
| Branding browser | 44 | 1 |
| Settings-tab browser | 24 | 1 |
| New waiver browser | 36 | 1 |

`tests/waivers.php` covers provider absence/signature capability/configuration, migration, field validation, age boundaries, individual requests, shared guardian, private/hash-only/expired/rotated links, email routing/failure/cooldown, permissions/nonces, stored proof tampering, duplicate callbacks, frozen version/text, lost-payment recovery, terminal records, exemptions, purchaser prefill, guest roster authorization, pending inventory, admin/customer/calendar/receipt displays and provider extensibility. Existing suites retain hourly/calendar scheduling, totals, guarded deposits, branding, deep links, concurrency and cart cleanup checks.

`tests/waivers-browser.cjs` checks two-rider collection, purchaser prefill, required age entry, keyboard navigation, native POST target, input widths, absence of signature images, Waivers settings/mapping and panel isolation at 1280, 782 and 375 pixels. Screenshots were visually reviewed. These are local rendering checks, not WPForms Signature canvas tests.

To reproduce: use the disposable-only environment required by `tests/inventory-test-bootstrap.php`; set `BRP_ALLOW_DISPOSABLE_TESTS=1` and `BRP_TEST_WP_ROOT`. Run `php tests/waivers.php` in each Woo order-storage mode. For browser fixtures, also set `BRP_WAIVER_FIXTURE_DIR` to an existing scratch directory, then run `node tests/waivers-browser.cjs` with Playwright and `BRP_BROWSER_CHANNEL=chrome`. The tests intercept outbound mail and never call Square. Database-mutating suites must run sequentially.

## Required dedicated-site acceptance (all pending)

Use the approved test site, authorized test inboxes, explicit test waiver wording and Square sandbox. Record actual plugin/addon versions. Keep **Full Payment** selected throughout. Follow [provider setup](wpforms-waiver-provider.md), then record these outcomes:

| Scenario | Acceptance |
|---|---|
| A: Configure | WPForms + Signature enabled, valid form/mappings and preserved legal text/version; diagnostics available/configured; required Yes. Missing provider/signature blocks new checkout with admin warning. |
| B: One adult | Pay one-bike rental, collect adult, receive invitation, sign in guest browser; Pending Waivers becomes Confirmed only after verified completion. |
| C: Two adults | Separate inboxes/links; first signature shows 1/2 and remains pending; second shows 2/2 and confirms. |
| D: One minor | Guardian identity/relationship required, minor email optional; guardian receives/signs for named minor and confirms. |
| E: Adult + minor | Adult and guardian receive independent links; no cross-rider information in either signing page. |
| F: Shared guardian | Same guardian receives two unique links; signing one minor does not complete the other. |
| G: Resend | After cooldown, resend retains rider/request, rotates link; old link fails; immediate repeat denied; no duplicate rider. |
| H: Customer status | Checkout Block's Additional Information/thank-you section, account order, guest purchaser page and restored booking receipt show payment/progress/action notice. Completion replaces action notice. |
| I: Reservation admin | List/detail show roster, status, invitation and completion timestamps, provider reference; unauthorized/no-nonce actions denied; exemption requires recorded reason. |
| J: Calendar | Pending rentals retain capacity, compact progress visible, filters preserve capacity totals; later nonoverlapping bookings still available. |
| K: Activation | Incomplete activation fails with the required message. After all waivers, activation succeeds only at/after scheduled start. |

Also test actual Signature storage/entry references, AJAX and non-AJAX submissions, missing signature, wrong form/context, duplicate submission, expired/cancelled links, failed mail, invalid mapping, nonce failure, refunds, disabled waivers, existing pre-upgrade reservations, and unchanged order totals/taxes. Confirm no signature images in purchaser/admin summaries and private entry/asset access on the host. Preserve the Checkout Block's Additional Information block so the standard thank-you hook remains visible.

## Known limits

Actual WPForms Elite/Signature versions and genuine mail/signature/Square behavior remain unverified. Approved legal text and form mappings must be supplied/configured; waivers default off. Signer identity is locked after a waiver request exists, including failed invitations; correcting a person/email requires staff-assisted cancellation/rebooking in this release. No legacy per-reservation opt-in UI, customer resend, automatic evidence erasure/export, full revision history or additional confirmation email ships. Cancellation retains evidence. Provider entry/assets require their own access control and backups. Mail accepted by WordPress is not guaranteed delivered. Age 18 is an operational threshold, not a legal determination.

## Release and SFTP

Release ZIP: `.release/milestone8/bike-rental-plugin-0.8.0.zip`. Contains only the complete inner runtime folder; tests, docs, local fixtures and credentials are excluded.

Upload the contents of:

`C:\Users\RandyNewby\Documents\GitHub\bike-rental-plugin\bike-rental-plugin`

to:

`wp-content/plugins/bike-rental-plugin/`

The main file must end at `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`. Back up the database and provider assets first. Normal initialization installs/verifies schema 2; no reactivation is needed. Verify runtime 0.8.0/schema 2, preserved existing reservations and Full Payment. Older schema-1 plugin releases will reject schema 2; do not downgrade allocation code against current bookings.

Commit on main: `feat: add generic rider waiver workflow and WPForms provider in 0.8.0`. No push or remote deployment.

Package verification: **45 runtime files**, every extracted byte matched source. ZIP SHA-256: `E490B1D0CE3C5BD201E75B13768444DF5EC36865EEBD1585159AB64CD3A4CBE6`.

## Files changed

- `CHANGELOG.md`
- `README.md`
- `bike-rental-plugin/assets/css/calendar.css`
- `bike-rental-plugin/assets/js/booking.js`
- `bike-rental-plugin/bike-rental-plugin.php`
- `bike-rental-plugin/readme.txt`
- `bike-rental-plugin/src/AdminCalendar.php`
- `bike-rental-plugin/src/Availability.php`
- `bike-rental-plugin/src/CheckoutReservation.php`
- `bike-rental-plugin/src/Database.php`
- `bike-rental-plugin/src/Payments.php`
- `bike-rental-plugin/src/Plugin.php`
- `bike-rental-plugin/src/PublicBooking.php`
- `bike-rental-plugin/src/Reservations.php`
- `bike-rental-plugin/src/Settings.php`
- `bike-rental-plugin/src/WPFormsWaiverProvider.php`
- `bike-rental-plugin/src/WaiverProvider.php`
- `bike-rental-plugin/src/WaiverSettings.php`
- `bike-rental-plugin/src/WaiverUI.php`
- `bike-rental-plugin/src/Waivers.php`
- `bike-rental-plugin/src/calendar-page.php`
- `bike-rental-plugin/src/reservations-page.php`
- `bike-rental-plugin/src/settings-page.php`
- `docs/waiver-architecture.md`
- `docs/waiver-verification.md`
- `docs/wpforms-waiver-provider.md`
- `tests/availability.php`
- `tests/booking-branding.php`
- `tests/cart-holds.php`
- `tests/checkout.php`
- `tests/foundation.php`
- `tests/milestone7a-browser.cjs`
- `tests/milestone7a.php`
- `tests/packages.php`
- `tests/product-grid.php`
- `tests/reservation-editing.php`
- `tests/reservations.php`
- `tests/settings-tabs.php`
- `tests/waivers-browser.cjs`
- `tests/waivers.php`
