# Milestone 8 corrective release verification - 0.8.1

Runtime **0.8.1**, schema **2 unchanged**, directly on **main**. Local implementation and automated verification are complete. **Deployment and dedicated-site acceptance A-H remain pending.** No remote settings, mail, signature, payment or GitHub push was performed.

## Implementation

- Flow: choose rental, date/time, quantity, numbered riders, review/temporary hold, explicit Continue to checkout, verified payment, invitations, Pending Waivers, then Confirmed after every required waiver completes. Adults 18+ require name/age/email. Minors require name/age and guardian name/email/relationship; own email is optional. Server validation determines classification. Reservation and riders are saved atomically; checkout revalidates the roster. Requests and mail wait for verified payment.
- Generic Adult and Guardian Invitation Email groups contain current plain-text subjects/bodies. Eleven placeholders are documented in [architecture](waiver-architecture.md). Resends preserve token rotation/cooldown, riders and frozen legal text.
- Publish a public page containing `[bike_rental_waiver]` and select Waiver Signing Page. The generic shortcode validates the bearer, displays frozen legal/context information and delegates rendering to the provider. Invalid page configuration blocks readiness. Exclude the full page and private query links from all cache layers.
- WPForms fills all 13 context/signer values server-side. Adults use their identity with blank guardian fields. Minors retain their rider identity while signer fields identify the guardian. Actual generated HTML is checked before rendering, submitted values are compared, and completion rereads the saved entry/signature/consent. Bearers stay out of mapped fields, raw entry data and source URL metadata. Opt-in diagnostics contain timestamps and booleans only.
- Delete Permanently requires cancelled/expired status, capability, nonce, explicit confirmation, current revision and no protected evidence. The transaction rechecks relationships and removes incomplete requests, riders and reservation/session linkage together. Any linked Woo order, reverse order metadata link, completed/exempt/provider evidence, inconsistent association or return-turnaround audit evidence blocks deletion. Orders, provider entries, signature assets and fleet rows are never deleted.
- Existing cron removes abandoned unlinked rider PII/incomplete requests after 30 terminal days in bounded batches, retaining reservation metadata and protected evidence. No bulk deletion UI or full evidence-history platform was added.
- **Full Payment amounts/taxes, Square processing, deposit architecture and fleet-capacity algorithms are unchanged.** Checkout adds only the validated-roster prerequisite. No later milestone started.

## Automated results

**3,114 passing check executions / 2,508 distinct checks.** The 606 repeated checks cover CPT and HPOS order storage. Counts exclude failed development attempts and duplicate fixture-seeding runs. PHP syntax passed for **61 files**, JavaScript syntax for **9 files**, and Git whitespace validation passed.

Environment: disposable local WordPress 7.0, WooCommerce 11.1, PHP 8.3.33, MariaDB 11.4.8 and headless Chrome. Integration tests use actual WordPress/WooCommerce/InnoDB and explicit WPForms/Square doubles. Browser checks use plugin markup/JavaScript with isolated API transport. These checks do not certify real Signature storage, inbox delivery or Square sandbox processing.

| Suite | Checks per run | Runs counted |
|---|---:|---:|
| Foundation + packages | 208 | 1 |
| Storage + editing + availability + active policy | 408 | 1 |
| Calendar-day scheduling matrix | 667 | 1 |
| Public booking | 75 | 1 |
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
| Waiver integration including refinements | 345 | 2 |
| Product-grid browser | 76 | 1 |
| Deep-link/calendar browser | 41 | 1 |
| Branding browser | 44 | 1 |
| Settings-tab browser | 24 | 1 |
| Waiver browser | 36 | 1 |
| Pre-checkout rider browser | 36 | 1 |

`tests/waiver-refinements.php`, included by `tests/waivers.php`, covers roster validation, atomic hold rollback, idempotent roster intent, checkout guard, unpaid invitation suppression, templates/all placeholders, page validation, each rendered field, context/signer/signature/consent rejection, bearer scrubbing, stored-entry reread, correct-rider/duplicate completion, deletion eligibility/permissions/nonce/revision, atomic rollback, reverse order links, protected evidence and retention. Existing tests retain token expiry/rotation, frozen policy, shared guardians, failed mail, exemptions and customer/admin progress coverage.

Regression fixtures now supply valid pre-checkout riders to continue testing their original behavior. The status fixture explicitly rejects Pending Waivers when waivers are disabled. The concurrency fixture supplies a roster before its inventory-claim race. No production guard is bypassed.

The new rider browser suite checks 1/3 riders, adult/minor fields and age boundary, validation, numbered POST data, explicit review before checkout, no rider PII in sessionStorage and no JavaScript errors at 1280/375 pixels. Mobile layout was visually reviewed. Signature canvas interaction is not simulated.

To reproduce, use only the disposable environment required by `tests/inventory-test-bootstrap.php`; set `BRP_ALLOW_DISPOSABLE_TESTS=1` and `BRP_TEST_WP_ROOT`. Run `php tests/waivers.php` in each order-storage mode. For browser fixtures set `BRP_WAIVER_FIXTURE_DIR` and `BRP_GRID_FIXTURE_DIR` to a scratch folder, generate the corresponding PHP fixtures, then run `node tests/waivers-browser.cjs` and `node tests/rider-booking-browser.cjs` with Playwright and `BRP_BROWSER_CHANNEL=chrome`. Tests intercept mail and never charge Square. Database-mutating suites run sequentially.

## Read-only dedicated-site inspection

On September 21, 2026 signed-in admin at `https://www.brp.newbytechfl.com` showed:

- Bike Rental Plugin **0.8.0**, **Full Payment**, required waivers **Yes**.
- WordPress **7.1.1**, WooCommerce **11.1.1**, Square **5.5.0**, WPForms **2.0.2**, WPForms Signatures **1.14.0**.
- **Bike Rental Rider Waiver**, form **58**; context fields **1-11**, signer text **12**, signer email **13**, Signature **14**, acceptance **15**.
- Required signer/signature/acceptance, email confirmation off, AJAX on, entry storage enabled, automatic entry purging off. Form overview showed **0 entries**.

This verifies configuration, not 0.8.1 runtime population. No form settings were saved. Actual addon rendering/entry verification remains a deployment acceptance gate. Configured legal text is test wording; replace with business-approved wording/version before real use.

## Required acceptance after deployment (A-H pending)

Upload 0.8.1, verify runtime/schema, publish/select the generic page, retain Full Payment and verify mappings using [provider setup](wpforms-waiver-provider.md). Exclude private pages from caching. Use authorized test inboxes and Square sandbox. Temporarily enable `BRP_WAIVER_DIAGNOSTICS` during controlled verification.

| Scenario | Exact acceptance |
|---|---|
| A: Rider collection | Book 2 bikes: adult and minor. Missing adult email or guardian name/email/relationship blocks submission; minor email may stay blank. Two valid riders exist on hold with no requests/mail yet. |
| B: Checkout | Explicit Continue opens WooCommerce; roster already persisted, correct association and unchanged quantity/price/tax/total. Removing unpaid rental cancels hold; fresh booking never inherits riders. |
| C: Payment | Authorized sandbox payment enters Pending Waivers, retains interval, creates two requests/invitations once. Verify current templates/routing. Failure/unpaid booking sends none. |
| D: Adult signing | Guest adult link shows all 13 correct values, blank guardian fields and separate bearer. Sign/accept; entry reread completes intended rider and shows 1/2. |
| E: Minor signing | Guardian link retains minor identity plus guardian signer/name/email/relationship. Sign/accept; only intended rider completes, 2/2 becomes Confirmed. Duplicate callback changes nothing. |
| F: WPForms evidence | Inspect saved mappings, Signature reference and consent. Custom tables contain provider reference/frozen text, no blobs/tokens. Verify diagnostics, source URL scrubbing, AJAX/non-AJAX. Disable diagnostics afterward. |
| G: Eligible deletion | Disposable cancelled/expired unpaid unlinked booking shows warning/confirmation. Deletion removes associated rows atomically with no orphans/capacity change. Repeat fails safely. |
| H: Protected deletion | Order, completed/exempt waiver or provider/return evidence blocks deletion. Orders/evidence remain. Unauthorized, invalid-nonce and stale-revision requests fail. |

Also verify invalid page warnings, tampered/expired/cancelled links, missing signature/consent, resend cooldown/current template/old-link invalidation, theme appearance, status wording and mail transport. Spot-check hourly/calendar-day/deep-link booking and calendar capacity. Preserve Checkout Block Additional Information for the standard thank-you hook.

## Known limits and upgrade notes

Real addon population/completion, mail delivery and sandbox payment remain unverified until deployed acceptance. Licensed addons are absent from the local fixture. Runtime HTML verification fails closed if provider markup differs; guarded diagnostics identify mismatches without secrets. Source inspection/configured status are not end-to-end proof.

Old unpaid holds with blank riders cannot check out: staff may fill their roster, or let them expire and restart. Existing paid records retain frozen policy. Identity becomes immutable once a request exists; corrections require staff-assisted cancellation/rebooking. No pre-checkout purchaser shortcut is shown because trustworthy billing details are unavailable. WP-Cron must run for 30-day cleanup; order-linked/evidence-protected data is intentionally retained. Provider assets need separate access controls/backups. No completed-evidence erasure/export, full audit history, customer resend or additional confirmation email is included.

## Release and SFTP

ZIP: `.release/milestone81/bike-rental-plugin-0.8.1.zip`, containing the complete inner runtime folder only. Tests/docs/fixtures/credentials are excluded. Schema definitions remain unchanged; no migration/reactivation is needed from 0.8.0.

Upload contents of `C:/Users/RandyNewby/Documents/GitHub/bike-rental-plugin/bike-rental-plugin/` to **`wp-content/plugins/bike-rental-plugin/`**. Main file must be `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`. Back up database/provider assets; verify 0.8.1/schema 2, unchanged Full Payment and records. Configure the new signing page before waiver-required checkout testing.

Commit message: `feat: refine rider waiver flow and reservation cleanup in 0.8.1`. Directly on main; no push or remote deployment.

Package verified: **47 runtime files**, every archived byte matched source. ZIP SHA-256: `3DB378B5A6EEAB8010035FABDAA4C2B540295E7804301B2463F2C868D0983B06`.

## Files changed

- `CHANGELOG.md`
- `README.md`
- `bike-rental-plugin/assets/js/booking.js`
- `bike-rental-plugin/bike-rental-plugin.php`
- `bike-rental-plugin/readme.txt`
- `bike-rental-plugin/src/CheckoutReservation.php`
- `bike-rental-plugin/src/DataAdmin.php`
- `bike-rental-plugin/src/Database.php`
- `bike-rental-plugin/src/HoldCleanup.php`
- `bike-rental-plugin/src/Plugin.php`
- `bike-rental-plugin/src/PublicBooking.php`
- `bike-rental-plugin/src/ReservationCleanup.php`
- `bike-rental-plugin/src/Reservations.php`
- `bike-rental-plugin/src/WPFormsWaiverProvider.php`
- `bike-rental-plugin/src/WaiverEmail.php`
- `bike-rental-plugin/src/WaiverSettings.php`
- `bike-rental-plugin/src/WaiverUI.php`
- `bike-rental-plugin/src/Waivers.php`
- `bike-rental-plugin/src/booking-form.php`
- `docs/waiver-architecture.md`
- `docs/waiver-verification.md`
- `docs/wpforms-waiver-provider.md`
- `tests/availability-concurrency.php`
- `tests/booking-branding-browser.cjs`
- `tests/calendar-booking.php`
- `tests/calendar-duration-matrix.php`
- `tests/cart-holds.php`
- `tests/checkout-store-api.php`
- `tests/checkout.php`
- `tests/foundation.php`
- `tests/inventory-test-bootstrap.php`
- `tests/inventory-worker.php`
- `tests/milestone7a-browser.cjs`
- `tests/product-grid-browser.cjs`
- `tests/public-booking.php`
- `tests/reservation-editing.php`
- `tests/rider-booking-browser.cjs`
- `tests/waiver-refinements.php`
- `tests/waivers-browser.cjs`
- `tests/waivers.php`
