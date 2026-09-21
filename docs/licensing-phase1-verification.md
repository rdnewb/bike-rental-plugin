# Licensing Phase 1 verification and release report

## Delivered scope

1. **Structure:** independent top-level `bike-rental-plugin/` and `nt-license-controller/` plugins, each with bootstrap, namespace, readme, version and uninstall behavior. Controller code is excluded from the customer ZIP.
2. **Client version:** 0.9.0, following the inspected 0.8.2 baseline. Rental schema remains 2.
3. **Controller version:** 0.1.0.
4. **Controller schema:** 1; `{prefix}ntlc_licenses`, `{prefix}ntlc_activations`, `{prefix}ntlc_events`, all InnoDB. Per-site prefixes are respected.
5. **Terms:** lifetime without expiration; monthly and annual with manually maintained, authoritative UTC expiration.
6. **Statuses:** active, expired, suspended and revoked. Inactive describes a deactivated installation, not a stored license status.
7. **Limits:** positive integer or 0 for unlimited; concurrent activation count/insert is serialized. Duplicate activation is idempotent. Deactivation frees a slot without deleting history; reducing a limit does not automatically evict existing sites.
8. **REST:** HTTPS POST `/wp-json/nt-license/v1/activate`, `/validate`, `/deactivate`, stable response/error codes, schema validation and rate limits.
9. **Security:** 256-bit random keys; controller hash/suffix only and one-time display; client sodium-encrypted non-autoload option, masked redisplay, nonce and administrator checks. No global embedded secret or sensitive request logging.
10. **Schedule:** activation, Check License Now, and daily WP-Cron. Booking/page requests read cached entitlement without remote validation.
11. **Grace:** seven days from last valid check, never extended by failure, capped by known expiry. Definite invalidity bypasses grace.
12. **Enforcement:** compatibility mode defaults OFF; internal constant/filter opts in after rollout tests. Invalid enforcement blocks new booking only. Existing reservations/returns/checkout/orders/waivers keep operating under their original rules.
13. **Controller admin:** create, View/Edit, status changes, expiration extension, limits/customer metadata, active-site deactivation, paginated activations/events and rate settings.
14. **Client License tab:** encrypted key entry, Activate, Deactivate, Check License Now, status/plan/expiration/counts, validation dates, connection/code and grace information. Administrator-only.
15. **Future Stripe:** nullable billing provider/customer/subscription fields and trusted license service boundary; no Stripe operations or renewals.
16. **Future updater:** product/plan/status and update entitlement in responses; no update hooks or artifact delivery.
17. **Automated checks:** **2,622 passing assertions** across the selected suites below, including **141 new licensing/controller integration assertions**. Counts represent executed suite assertions, not 2,622 distinct scenarios; CPT/HPOS repetitions are listed explicitly and the separately repeated foundation run is excluded from the total.
18. **Integration:** two separate WordPress roots/options/users/salts and table prefixes (`m3_` client, `lc_` controller) on the guarded disposable MariaDB database. The controller has no WooCommerce or rental plugin dependency. Client WordPress HTTP requests are bridged through independent PHP processes into the controller's real WordPress REST dispatcher and InnoDB tables. This verifies production request/response and business logic, **not real HTTPS networking/TLS**.
19. **Deployment acceptance:** real controller/client HTTPS, reverse proxy/CDN, production PHP sodium, clock/cron, admin browser issuing, activation and customer checkout smoke tests remain required. No live site was changed.
20. **Limitations:** see below. Compatibility mode remains enabled; Phase 1 has no billing or updater delivery.
21. **Commit:** committed directly to `main` with `feat: add license controller and client validation framework`; the actual hash is reported in the completion response. No push. Retrieve it with `git log -1 --format=%H` at the release checkout.
22. **Client ZIP:** `.release/licensing-phase1/bike-rental-plugin-0.9.0.zip` under the repository root.
23. **Controller ZIP:** `.release/licensing-phase1/nt-license-controller-0.1.0.zip` under the repository root.
24. **Deployment folders:** customer site's WordPress root + `/wp-content/plugins/bike-rental-plugin/`; controller site's WordPress root + `/wp-content/plugins/nt-license-controller/`. The intended controller host is `newbytechnologies.com`; host-account document roots are not assumed.

## Verified results

Local runtime: PHP 8.3.33, WordPress 7.0 on both fixtures, WooCommerce 11.1.0 on the client only, MariaDB 11.4.8. No actual Square charges or WPForms signing were performed by these suites.

| Suite | Passing assertions |
| --- | ---: |
| `licensing-controller.php` | 88 |
| `licensing-client.php` | 53 |
| `packages.php`, includes 114 foundation checks | 209 |
| `settings-tabs.php` | 90 |
| `active-reservations.php`, includes reservation/editing/availability checks | 408 |
| `calendar-duration-matrix.php`, includes calendar schedule checks | 667 |
| `public-booking.php` | 75 |
| `checkout.php`, CPT | 96 |
| `checkout-store-api.php`, CPT | 21 |
| `waivers.php`, CPT, includes refinements | 345 |
| `booking-branding.php` | 81 |
| `settings-tabs-browser.cjs`, Chrome desktop/tablet/mobile | 27 |
| `checkout.php`, HPOS | 96 |
| `checkout-store-api.php`, HPOS | 21 |
| `waivers.php`, HPOS | 345 |
| **Total** | **2,622** |

All 73 PHP files across runtime plugins and tests passed syntax checks. Final diff whitespace validation passed. ZIP verification compares every packaged file to its source bytes and confirms only the respective plugin root is present; tests, fixtures, documentation, credentials and the other plugin are excluded.

Controller tests cover all three terms, expiration requirements, hashing, product isolation, activation/idempotency/limits, deactivation/history, unlimited slots, all statuses, invalid payloads, URL normalization, HTTPS requirement, throttling, nonce/capability enforcement, audit events and daily deduplication. Three simultaneous independent activation workers contest one slot: exactly one succeeds and two receive `activation_limit_reached`. Injected storage read failure returns a generic 503, and a lost transaction token cannot persist a mutation. All five controller admin pages render without raw saved keys.

Client tests cover the protected tab/form, encrypted non-autoload storage and raw-key absence from HTML, stable identity, exact seven-field payload, version/site binding, manual/daily validation, no per-page remote requests, invalid activation, cached validity, outage, fixed grace expiry, immediate suspension/revocation, monthly/annual expiry, site clone rejection, salt-rotation recovery, deactivation/retry and slot reuse. Enforced public catalog/time/availability routes return a generic 503. A pre-existing active rental completes successfully while enforcement is enabled and the license suspended. Reservation administration remains accessible after revocation. The two-installation sequence covers requested scenarios A–M.

Earlier source-boundary tests were updated to permit the isolated licensing transport; rental checkout code still cannot directly call external payment APIs, create custom orders or synchronize inventory. Pricing, Square evidence handling, Full Payment settings, deposits, schedule calculations and waiver business logic were not modified.

## Reproducing the licensing checks

These tests mutate disposable fixtures. They refuse any database except `brp_m3_disposable` at `127.0.0.1:33316`, with client prefix `m3_` and controller prefix `lc_`. Never change the guard to point at live data.

Provision two independent WordPress roots on that disposable database. Install WooCommerce only in the client root, with administrator ID 1 and the existing rental test schema. The controller root needs its own `wp-config.php`, `lc_` prefix and installed administrator ID 1; no licensed plugin/Woo dependency. Bootstrap files load runtime plugin code from this repository and install the controller schema. PHP needs mysqli, sodium and subprocess support. Database user needs schema/transaction/advisory-lock permissions.

```powershell
$env:BRP_ALLOW_DISPOSABLE_TESTS = '1'
$env:BRP_TEST_WP_ROOT = '<disposable client WordPress root>'
$env:BRP_TEST_CONTROLLER_ROOT = '<separate disposable controller WordPress root>'
php -c '<test php.ini>' tests/licensing-controller.php
php -c '<test php.ini>' tests/licensing-client.php
```

Controller tests truncate only its guarded disposable tables. Client tests create an existing-rental fixture and restore saved home/license/settings/identity/capacity options. Fixture product/reservation rows may remain for inspection. The HTTP bridge never contacts the real controller. Runtime response/log fixtures are ignored by Git.

## Required deployment acceptance

1. Install controller on an authorized test HTTPS host. Open each NT Licenses page; create Lifetime, Monthly and Annual licenses through the browser. Copy a key once, reload and confirm only masking remains. Verify nonce/capability errors and local-time expiration display. Extend a term and restore Active.
2. Deploy client in compatibility mode. Confirm General/Branding/Waivers still save independently and Full Payment remains selected. Enter a key, Activate, inspect matching site/installation/version/count on controller, then Check License Now. Confirm no PHP or database error output.
3. With a one-slot key, repeat activation on the same client (one row), then try a second site (limit denied). Deactivate the first, verify its historical row remains, then activate the second. Test controller-admin deactivation and subsequent client validation.
4. On a **test site**, enable `BRP_LICENSE_ENFORCE`. Suspend, revoke and expire test licenses, validating after each change. Confirm generic new-booking unavailability and prominent admin notice. Confirm existing reservation detail/return, Woo order view, already-created hold checkout and waiver evidence remain accessible. Restore Active with a future expiration and confirm booking resumes.
5. Simulate unreachable controller on the test site after a successful check. Confirm no frontend validation request, bounded grace, unchanged deadline on repeat failures and eventual blocking outside grace. Use disposable fixture clock/cache controls for accelerated expiry, never alter production data or weaken TLS verification.
6. Run the actual scheduled event via WP-Cron/server scheduler and verify the client and controller timestamps advance. Test SSL certificate validation, correct HTTPS detection behind the proxy, noncached REST POSTs, clock synchronization and rate limits without real credentials in logs.
7. Deactivate while the controller is offline, restore connectivity and verify retry releases the slot. Test a site move/clone using the documented reset/deactivation workflow.
8. Smoke-test rental selection through checkout on the existing approved payment setup; do not alter providers/deposit logic. Uninstall only on disposable sites and verify record preservation. Enable production enforcement only after acceptance.

## Known limits and phase boundary

- Manual license delivery and term maintenance; no purchaser portal, email issuing, Stripe billing, subscriptions, webhooks, automatic renewals or private plugin updates.
- Single-site WordPress is tested; network provisioning/multisite policy, domain aliases, IPv6 literal site URLs and richer activation history revisions are deferred. A reused activation row stores its current identity; bounded events preserve transitions rather than every old URL snapshot.
- Daily checks are traffic-dependent and authoritative changes can take until the next successful validation to reach a client. Grace is fixed from last success, not seven days from first observed outage. PHP/config owners can change enforcement.
- Local encryption requires sodium and WordPress salts. Keep salts and backups secure; re-enter the original key after salt rotation. Unknown/lost keys require controller replacement and explicit local cleanup.
- Basic fixed-hour throttling and bounded 180-day event retention require normal database/cron operation; infrastructure abuse protection and request-body redaction remain deployment responsibilities.
- Controller uses a global mutation lock for simple Phase 1 consistency; very high activation volume may need later partitioning. Reduced limits do not automatically evict active sites.
- Real public HTTPS/TLS and host-specific operational acceptance are pending. Existing live Square/WPForms acceptance tasks are unchanged.

Implementation stops at Licensing Phase 1.
