# Bike Rental Plugin

A reusable WordPress/WooCommerce bicycle-rental extension, developed locally on Windows and deployed as a self-contained directory through SFTP. Business identity belongs in configuration. This project is focused on bicycle rentals.

**Current version: 0.6.1 — responsive rental product grid. Dedicated-site Square sandbox acceptance is pending.**

Milestone 6A supersedes the deposit-dependent Milestone 6 plan. Full Payment requires no deposit extension. Deposit mode is configurable but blocks new rental checkout until a compatible provider is selected. See [Milestone 6A architecture and verification](docs/milestone-6a-verification.md); the earlier [Acowebs compatibility findings](docs/milestone-6-compatibility.md) remain an archived preflight record.

Develop and validate test releases on the separate WordPress test site. Production installation follows approval of the completed plugin; no site URLs or credentials belong in source control.

## What works

- Native **Bike Rentals > Settings** page.
- Business name, booking horizon, notice, time increment, pickup time, preparation/turnaround buffers, and weekly operating hours.
- Server-side validation. An invalid submission is rejected as a whole, with the previous configuration retained and explanatory errors shown.
- Access for users with `manage_options` or `manage_woocommerce`; no role names or role mutations.
- WordPress Settings API saves through `options.php`, including its nonce and capability checks. The plugin filters only its own settings group's save capability and also checks permission in its sanitizer and page renderer.
- WordPress timezone display and an informational warning for fixed offsets/unrecognized timezone settings; no automatic timezone changes.
- Installed/active dependency visibility for WooCommerce, WooCommerce Square, WPForms, Signature Addon, and recognized WooCommerce deposit solutions.
- Settings preservation across updates, deactivation/reactivation, and uninstall.
- **Rental Settings** in the standard WooCommerce Simple product editor: package identification, duration, promotional label, and rental-active flag.
- **Bike Rentals > Packages**: paginated read-only overview with product-editor links, regular prices, status, and WooCommerce display order.
- A reusable PHP package reader plus a public catalog of active published rental packages.
- **Bike Rentals > Fleet**: persistent shared fleet quantity, dated or indefinite quantity blocks, editing, and disabling.
- **Bike Rentals > Reservations**: paginated list, manual test creation, and a full revision-protected edit form for package, quantity, dates, status, and issue code.
- Two InnoDB tables, verified versioned schema installation on normal initialization, and current reservation snapshots protected from direct editing and automatic catalog changes.
- One sweep-line availability service, shared capacity-row locking for all allocation changes, expiring idempotent holds, and conflict protection for reservation edits, blocks, and fleet reductions.
- **Bike Rentals > Availability test**: local occupied-interval test with fleet, peak usage, available quantity, and Fits / Does not fit results; manual hold cleanup.

- **[bike_rental_booking]** shortcode: package/date/time/quantity selection, calculated pickup, availability, and protected guest hold with expiry display and refresh recovery.

**The public form transfers its existing hold to WooCommerce Checkout Block. Full Payment uses WooCommerce Square; verified captured payment confirms that reservation. Deposit processing and waivers are not implemented. WooCommerce handles order emails and financial records.**

Configure **Bike Rentals > Settings > Payment Mode > Full Payment**. Deactivate unsupported deposit extensions before rental checkout (including the previously tested Acowebs installation). Configure Square, guest checkout, and WooCommerce delivery zones/rates. Rental products require WooCommerce shipping/delivery fields; no custom shipping-rate calculation is added. Rental carts contain one reservation, with a locked quantity and no coupons or other products. Normal non-rental carts retain standard WooCommerce behavior.

## Public booking form

Insert `[bike_rental_booking]` into a normal WordPress page or a Divi shortcode-capable area. Configure open hours first; all-closed defaults offer no start times. JavaScript and first-party cookies are required. No custom Divi module or calendar library is used. Assets are scoped to `.brp-booking` and load only when the shortcode renders, including late-rendered styles.

Start-time dropdown labels use 12-hour AM/PM display (for example, `1:30 PM`); option values remain `HH:MM` for existing booking validation.

Rental packages render as semantic product cards before JavaScript loads. Cards use WooCommerce featured thumbnail images (responsive `srcset`/`sizes` and attachment alt text), short descriptions with restricted basic formatting, and WooCommerce formatted prices; duration and optional promotion come from rental metadata. Missing images have an aligned fallback. Long descriptions are contained in keyboard-scrollable regions.

The grid fits up to three columns in its 72rem container, two in medium spaces, and one on narrow screens. Native Select Rental buttons support Enter/Space, visible focus, and `aria-pressed`; selected text/checkmark and borders make selection clear without relying on color. Selecting a card synchronizes a hidden package ID and reveals the existing date/time, quantity, and review controls. The existing catalog request refreshes eligibility and date limits before buttons enable; the server still validates all booking requests. Clear cached booking pages after updating products or deploying. See [0.6.1 verification and deployment](docs/product-grid-verification.md).

Public packages and holds use the **current WooCommerce selling price**, formatted by `wc_price()` and labeled per bike. WooCommerce calculates checkout totals and taxes. A catalog price change during a hold requires a fresh selection. The existing internal package reader and manual creation retain their regular-price contract. The hold preserves price, package metadata, quantity, local endpoints, timezone, and occupied buffers.

The start-date horizon is inclusive in the WordPress timezone. Start times follow increments measured from opening, before closing, and respect elapsed minimum notice. Hourly packages use elapsed UTC duration and require both endpoints in open periods; hourly pickup at closing is allowed. Calendar-day packages use `start date + (N - 1)` for any valid configured duration and apply the business-controlled pickup time to that date. Any weekday can be a start when its operating hours permit. Calendar pickup is independent of delivery/start hours; intermediate and final days may be closed for new starts. End must still be after start, including for one-day rentals. Ambiguous/nonexistent DST endpoints are rejected without extension. Buffers affect capacity, not customer-displayed times. The horizon constrains the start date.

REST namespace `bike-rental/v1` exposes GET `packages`, `times`, `availability`, and POST `session`, `holds`, `hold-status`. Responses are non-cacheable. Read endpoints expose catalog/schedules/aggregate capacity only. Hold operations require a signed HttpOnly, SameSite=Lax cookie, same-origin/custom-header checks, and a session-bound CSRF token. HTTPS enables Secure cookies. Raw guest identifiers/hashes never appear in responses or markup. Ordinary admin services retain capability/nonce checks; only the protected controller enters the trusted PHP public-booking scope.

The server derives endpoints/buffers and rechecks notice against the locked database clock before using the existing capacity-row allocation service. One live public hold is allowed per guest session. Duplicate request keys reuse existing results without renewing expiry. Browser session storage remembers the request key for refresh recovery; server ownership always depends on the signed cookie. Start over is offered after expiry. Advisory per-IP minute limits reduce repetition but are not the inventory lock or a full anti-bot system.

See [M5 API, verification, and test-site acceptance](docs/public-booking-verification.md). The isolated `PublicBooking::next_step_message()` is development-only copy to replace when Milestone 6 is authorized.

## Installation for testing

1. Use a WordPress test site with PHP 8.3+ and WordPress 6.6+ (declared code baseline, not a completed compatibility matrix).
2. Back up the site/database before changing an existing installation.
3. Upload the repository's **inner** `bike-rental-plugin/` directory to `wp-content/plugins/` using SFTP.
4. The main file must end up at `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`.
5. Activate **Bike Rental Plugin** through WordPress Plugins.
6. Open **Bike Rentals > Settings**, configure the business and hours, and save.
7. Activate WooCommerce for package management. Create a Simple product and configure **Product data > Rental Settings**. No products are created by activation.
8. On the next normal request, schema version **1** installs the two rental tables and a single fleet capacity row (initial quantity 10). Open **Fleet** to configure it, then **Reservations** for storage testing.

Do not upload the repository root, `.git`, `tests`, editor configuration, logs, or credentials. No compilation, Composer, Node.js, SSH, WP-CLI, or production build commands are required. Product-editor JavaScript and shortcode-scoped public JavaScript/CSS ship ready to upload.

WooCommerce is required for package management, reservation package validation, and checkout. It is not a hard activation dependency: settings, fleet, and stored reservations remain accessible without it. Square is required for real rental payment. WPForms and deposit extensions are not required for Milestone 6A.

## Rental packages

Use the **standard WooCommerce product editor**, not a second catalog editor. Select Simple product, enter the title and Regular price, and open Rental Settings:

- **Use as Rental Package:** marks this selected product only. Unchecking it and saving removes the five owned metadata fields, leaving all other product data intact.
- **Duration Type:** `hours` or `calendar_days`.
- **Duration Amount:** integer 1–8760 hours or 1–365 calendar days. Public selection derives elapsed-hour or inclusive calendar-day endpoints.
- **Promotional Label:** optional plain text, maximum 120 Unicode characters. Display text only; no discount, coupon, or daily-rate dependency.
- **Rental Active:** defaults off, separate from product publication. Inactive packages are retained in administration but excluded from the active-package reader.

Use standard WooCommerce fields for name, description, image, tax status/class, and **Advanced > Menu order**. The overview follows menu order, then title and ID for ties. The plugin never writes price, stock, or tax fields.

Invalid submissions retain all previous rental metadata and show WooCommerce admin errors. Standard WooCommerce fields (including price/title) may still save; the error does not roll back unrelated product edits. Quick/bulk edits without the rental panel payload leave rental metadata untouched. Changing a rental to a non-Simple type excludes it from the reader but retains metadata for repair; switching back to Simple restores that configuration.

**Price contract:** the package reader uses `get_regular_price('edit')` and returns a non-negative decimal string, or `null` when unset/invalid. An explicitly entered zero is distinct from an unset price. Sale prices are not used by the rental package reader. The plugin does not change normal WooCommerce sale pricing or purchasing behavior in this milestone. Prices are returned as stored; WooCommerce tax display/calculation is not performed by this reader.

Direct rental add-to-cart is blocked, including product buttons, URLs, and the Store API. The protected booking transfer is required. Stale holds, mismatched quantities/products, changed snapshots, and wrong guest sessions cannot proceed to payment.

### Metadata and package API

| Metadata key | Value |
|---|---|
| `_brp_rental_package` | `yes` when identified; absent when removed |
| `_brp_duration_type` | `hours` or `calendar_days` |
| `_brp_duration_amount` | Positive integer in the type's allowed range |
| `_brp_promotional_label` | Sanitized plain text |
| `_brp_rental_active` | `yes` or `no` |

WooCommerce stores these through product CRUD. Package configuration stays on products; the separate reservation table captures historical package details and nullable future order references. Deactivation, uninstall, and SFTP replacement preserve package metadata.

After `woocommerce_init`, later PHP code can use `BikeRentalPlugin\Packages`:

| Method | Contract |
|---|---|
| `is_rental_package($id_or_product)` | True for an existing Simple product with the rental marker; not a validity/availability guarantee. |
| `get_package($id_or_product)` | Valid configuration array or `null` for non-rental/unsupported/invalid metadata. Includes inactive and unpublished packages for administrative use. |
| `get_current_price($id_or_product)` | Current regular price decimal string or `null`. |
| `get_active_packages()` | Published, rental-active, valid Simple packages with an entered regular price, ordered by WooCommerce menu order/title/ID. No inventory check. |

The array includes `product_id`, `name`, `price`, `currency`, `duration_type`, `duration_amount`, `promotional_label`, boolean `active`, `product_status`, `display_order`, `tax_status`, and `tax_class`. Invalid IDs are rejected instead of being coerced into another product. Prefer passing the ID when a fresh read is needed; a supplied product object represents its current in-memory state.

The active catalog is small and reads all matching package products through `wc_get_products()`. The administration overview paginates 25 products at a time. A narrow, documented WooCommerce product-query extension adds the rental marker constraint; no direct SQL or direct product-table access is used. The query adapter targets WooCommerce's current standard product data store, independently of HPOS order storage.

These APIs return current product data. `Reservations::create()` captures agreed package details for each manual test reservation; later catalog price, duration, label, or product changes alone do not rewrite it. Authorized reservation edits maintain a current snapshot, as described below.

## Fleet and reservation storage

See [schema and service contracts](docs/reservation-storage.md) for every field/index, the final plugin folder structure, and method signatures.

- Schema option `brp_db_version` remains **1**, separate from plugin version **0.6.1**. No columns, tables, or indexes changed. Tables use the actual WordPress prefix: `{prefix}brp_reservations` and `{prefix}brp_availability`.
- Availability row **1** is the sole capacity row. Its saved quantity is authoritative and is never reset during upgrades. Fleet quantities must be positive integers. Capacity is independent of WooCommerce and Square stock.
- Blocks reserve a quantity against a local start and optional end; a reason is required. Active blocks and reservations share the same availability calculation. Block replacements exclude their existing allocation; fleet reductions must support peak combined usage across all current/future commitments.
- Administrator input/display uses the current WordPress timezone. Storage uses UTC. Invalid dates, daylight-saving gaps, repeated clock times, and changed form timezones are rejected.
- New manual test reservations retain explicit start/end correction behavior and must fit availability. Public selection additionally enforces package duration, hours, notice, increments, and horizon.
- Occupied intervals include preparation/turnaround buffers captured at creation. Administrative edits reuse those buffers. Changes to schedule, quantity, or status refresh those values in the current snapshot while keeping the agreed package price/duration. A package replacement captures the new package name, current WooCommerce selling price (`get_price('edit')`, including an active sale), duration, promotional label, and currency. Initial manual creation retains the existing regular-price contract.
- The **View / edit** detail page edits package, quantity, local start/end, any of the six valid statuses, and an optional issue code/short note (64 UTF-8 bytes). The current selection must still be a valid rental package; an existing inactive/unpublished package can retain its agreed terms, while a replacement must be published, active, and priced.
- Reference, created time, order relationships, IDs, hashes, and raw snapshot JSON cannot be edited. One successful multi-field save increments revision once and records the current UTC modification time. Stale revisions fail with a reload instruction; no-op saves leave the row and snapshot unchanged. Timestamps have one-second precision; revision distinguishes saves within one second.
- Administrative corrections are available for all six statuses, including completed/cancelled/expired records. Existing lifecycle helpers retain their ordinary transitions: `hold → confirmed / cancelled / expired`, `confirmed → active / cancelled`, `active → completed`. The explicit hold-confirmation helper also allows an expired result after a fresh availability check. Cancellation is persistent, with no delete action.
- Edits never rewrite WooCommerce orders or other reservations. Payment mode is retained when changing packages. Changed rental details after checkout trigger a reconciliation exception. Completing an active rental creates a dated quantity block when its captured turnaround buffer is positive. Prior revisions are not retained yet. Manual status changes do not verify payment or waiver readiness.

## Availability, holds, and transactions

`Availability::check($occupied_start_utc, $occupied_end_utc, $quantity = 1, $reservation_id = null, $block_id = null)` returns `total_capacity`, `peak_existing_usage`, `available_quantity`, `requested_quantity`, and `fits`, or `WP_Error`. Inputs use UTC SQL datetime strings; `null` end represents an indefinite internal interval. Exclusion IDs are for replacement checks by trusted PHP callers. This admin/internal method returns aggregate quantities, not customer details, and is not a public endpoint. A read is a point-in-time answer; allocation services always recheck before writing.

The single sweep clips relevant occupied intervals to the request, sorts quantity events chronologically with ends before starts at ties, and subtracts **peak simultaneous usage** from fleet capacity. Intervals are half-open `[start, end)`. Confirmed reservations, holds with future expiry, active rentals, and active blocks consume inventory; cancelled/completed/expired records do not. Existing overbooked development data is preserved and can produce a negative available quantity; resolve it before further allocation.

**Active-return policy (0.5.1):** an Active rental blocks only its scheduled occupied interval while the locked database UTC time is at or before its occupied end (including turnaround). Once that end has passed, it blocks from occupied start indefinitely until staff records completion. Both availability reads and allocation writes use this rule. Becoming overdue can conflict with previously accepted future bookings; those records remain intact and staff must resolve the conflict. Only the rental's quantity is claimed.

Active is allowed only at or after the scheduled rental start, including on direct creation, status helpers, and administrative schedule edits. Preparation does not allow early activation. Completed may record an ended rental, or an actual return from Active before scheduled end. Staff selecting Completed for an Active rental attests that the bikes were returned. Premature Completed creation/corrections are rejected; early-return completion retries preserve the saved result. At actual completion, captured turnaround minutes become a dated block starting at database UTC now; zero buffer releases immediately. Completed rows themselves are ignored. These controls do not establish payment/waiver readiness.

Pre-upgrade future Active records are retained and treated as bounded scheduled claims; correct their status to Confirmed in administration. No automatic migration or historical/order rewrite occurs. See the [0.5.1 verification and manual checklist](docs/active-reservations-verification.md).

Calendar-day packages use one generic duration calculation with no package-specific or weekday-specific branches. The existing administrator package range is 1–365 days. Weekly hours restrict new starts, not calendar-day collections; pickup uses the configured time even outside final-day delivery hours. The obsolete pickup-hours warning has been removed. Empty time lists still explain applicable schedule failures, and protected PHP diagnostics retain per-candidate and buffer-conflict reasons. See [generic duration rules, test matrix, and browser checklist](docs/generic-calendar-verification.md). Existing settings and saved reservation schedules are not rewritten.

`Reservations::create_hold($input, $request_key, $session_hash)` uses the same local package/quantity/start/end input as manual creation. Same key, intent, and session reuse the existing hold without renewing expiry. Public requests use `create_booking_hold()` after guest-session validation. Checkout transfer snapshots payment mode; payment submission claims one primary Woo order and sets the deadline to original creation plus 30 minutes. Loads/refreshes do not renew it. Failed/pending attempts stay bounded. Late payment rechecks locked capacity; a conflict needs staff resolution.

Holds last **15 minutes** from the database time after acquiring the lock (`Reservations::HOLD_MINUTES`). Expired timestamps cease consuming immediately. WP-Cron runs `brp_expire_holds` every **five minutes**, processing at most **100** expired rows per invocation and incrementing their revision/current snapshot status. Repeated scheduling is guarded by a database registration lock and a fresh schedule check. Deactivation removes the scheduled hook; reactivation schedules it on initialization. Delayed cron affects housekeeping only. Legacy holds with NULL expiry remain unallocated and are not silently renewed; explicit confirmation rechecks capacity. Moving a non-hold back to hold through administration starts a new 15-minute hold under the allocation lock.

Every inventory mutation uses `START TRANSACTION`, locks availability row 1 with `SELECT ... FOR UPDATE`, performs fresh reads and replacement validation, writes, then checks `COMMIT`. Errors roll back. This includes holds, confirmation/cancellation/status changes, full edits, cleanup, blocks, and capacity changes. Product/snapshot preparation is outside the transaction; no gateway work occurs inside it. Mutation SQL also verifies a per-connection session token so WordPress's automatic database reconnect cannot retry a write without its lost lock. There is **one transaction attempt**, with a clear retryable error on lock/SQL failure; callers must reload/retry, retaining the same creation key. See [engine verification and acceptance checklist](docs/availability-verification.md).

## Settings contract

Options: `brp_settings` (configuration), `brp_plugin_version` (code version), `brp_db_version` (verified schema version), and `brp_db_error` (actionable installation error, present only when needed). Fleet capacity is stored in the availability table, not duplicated in settings.

| Setting | Unit / allowed values | Initial value |
|---|---|---|
| Business name | Plain text, maximum 240 UTF-8 bytes; blank allowed during setup | Blank |
| Booking horizon | Integer days, 1–365 | 90 |
| Minimum notice | Integer minutes, 0–10080 (7 days) | 0 |
| Time increment | 5, 10, 15, 20, 30, or 60 minutes | 30 |
| Calendar-day pickup | Local 24-hour `HH:MM` | 17:00 |
| Preparation buffer | Integer minutes, 0–1440 | 0 |
| Turnaround buffer | Integer minutes, 0–1440 | 0 |
| Weekly hours | Each day Open/Closed, valid opening/closing times | All closed; retained time fields 09:00–17:00 |

Open days must close after opening on the same local date. Overnight business-hour windows and special-date/holiday management are deferred. Closed days retain their time values for convenient editing but remain closed.

Defaults are created only when the option does not exist. Reads can display defaults for an absent option; a malformed stored option displays a repair notice without overwriting it. Code-version updates never write rental settings. Ordinary deactivation and uninstall delete nothing.

Configuration status is deliberately limited:

- **Not Configured:** unchanged initial settings.
- **Partially Configured:** configuration differs from defaults but lacks a business name/open day, or saved data fails validation.
- **Ready for Package Setup:** valid foundation settings, a nonblank business name, and at least one open day. This does not imply operational suitability, payment readiness, or permission to accept rentals.

The plugin uses WordPress's configured timezone and rejects ambiguous/nonexistent local times. Preparation and turnaround settings feed occupied intervals. Public selection also uses hours, notice, increment, horizon, and calendar-day pickup settings.

## Dependency detection limits

Detection uses loaded runtime identifiers and installed plugin headers, not presumed folder names. Inactive installations are listed but marked Missing for active availability. Network-active dependencies can be detected; network-wide configuration/provisioning is not implemented, so activate this plugin per site for testing.

Available only means active presence was detected. Every component still displays **Not yet integration tested**. Header detection does not prove successful initialization, credentials, licenses, or compatible versions. WPForms Lite is identified as installed WPForms but does not satisfy the later paid signature workflow. A deposit-like WooCommerce plugin is only a candidate until verified.

WooCommerce Square processes Full Payment through WooCommerce. Compatible deposit-provider selection and WPForms waiver integration remain future work. This plugin does not read credentials or configure external payment plugins. Detection is not proof of successful sandbox validation.

## Architecture

- `src/PaymentMode.php`: generic payment-mode policy, guarded deposit mode, Woo financial summary, and captured-payment evidence reader.
- `src/CheckoutReservation.php`: locked ownership/allocation validation, historical mode snapshot, primary-order claim, bounded extension, and late-payment recovery.
- `src/Checkout.php`: Checkout Block transfer/validation, locked cart quantity, safe order metadata, and Square gateway selection.
- `src/Payments.php`: payment observation, two-way reconciliation, staff exceptions, and order/reservation crosslinks.

- `bike-rental-plugin.php`: headers, direct-access guard, explicit class loading, activation and deferred bootstrap.
- `src/Plugin.php`: lifecycle, text domain, version marker, dependency visibility, conditional package loading after WooCommerce initializes.
- `src/Settings.php`: capabilities, native settings registration, strict validation, and configuration status.
- `src/settings-page.php`: native WordPress administration HTML, separate from validation logic.
- `src/Packages.php`: rental metadata validation, product-editor save hooks, package reads, and overview query.
- `src/package-fields.php` and `src/packages-page.php`: product tab and read-only overview.
- `src/Database.php`: two-table definitions, verified idempotent schema install, prepared reads, and short capacity-row transactions.
- `src/RentalTime.php`: strict WordPress-local input, UTC conversion, occupied-time shifts, and local display.
- `src/Fleet.php` and `src/Reservations.php`: validated persistent records and reservation revisions/snapshots.
- `src/Availability.php`: one shared sweep-line calculation and allocation conflict result.
- `src/HoldCleanup.php`: guarded five-minute WP-Cron registration and bounded expired-hold housekeeping.
- `src/BookingSchedule.php`: public selling-price lookup and local/UTC scheduling rules.
- `src/GuestSession.php`: signed guest cookie, origin/token protection, and advisory rate limits.
- `src/PublicBooking.php`, `src/booking-form.php`, and `assets/{css,js}/booking.*`: REST boundary and public shortcode interface.
- `src/DataAdmin.php`, `src/fleet-page.php`, and `src/reservations-page.php`: capability/nonce-protected administration test tools.
- `assets/js/package-admin.js`: field visibility on product-edit screens only; PHP validates all submissions.
- `uninstall.php`: explicit data-preserving uninstall behavior.

There is no autoload framework or runtime dependency manager. Runtime PHP has direct-access protection. Foundation, package, and administration saves retain their capability and nonce checks. Public holds use the separate protected guest boundary; housekeeping remains restricted to its cron action or managers. Required inputs are allowlisted and SQL uses prepared identifiers/values. Public database errors are suppressed and mapped to safe messages. No remote gateway calls or order writes are introduced.

## Development and Git

Use GitHub Desktop to clone/update, open the local repository in Visual Studio Code, and edit locally. Git/GitHub is the source of truth. Do not edit production files directly unless expressly authorized.

Make all commits directly on `main`; do not create feature or development branches unless explicitly requested. Keep commits focused, push reviewed changes, and tag tested deployments using semantic versions. A commit on `main` does not by itself authorize deployment. Keep the plugin header, `Plugin::VERSION`, readme stable tag, and changelog synchronized. Do not commit credentials, exports, signed evidence, or local dependencies.

No build command is needed. To syntax-check with a local PHP executable in PowerShell:

```powershell
$phpPath = 'C:\path\to\php.exe'
Get-ChildItem .\bike-rental-plugin -Recurse -Filter *.php | ForEach-Object {
    & $phpPath -n -l $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax failure: $($_.FullName)" }
}
& $phpPath -n .\tests\packages.php
if ($LASTEXITCODE -ne 0) { throw 'Package/foundation checks failed' }
git diff --check
```

Version 0.5.3 passed **1,411 automated checks** and syntax validation for all **34 PHP files**. Verification and test-site steps are recorded in [the 0.5.3 report](docs/generic-calendar-verification.md). The prior calendar tests have been updated for the newly approved independent-pickup policy; unrelated regressions remain in place. No live deployment or browser/Divi acceptance is claimed. Historical reports retain their original counts and limitations: [0.5.2 investigation](docs/calendar-booking-verification.md), [0.5.1 Active policy](docs/active-reservations-verification.md), [0.5.0 public booking](docs/public-booking-verification.md), [0.4.0](docs/availability-verification.md), [0.3.1](docs/reservation-editing-verification.md), [0.3.0](docs/reservations-verification.md), [0.2.0](docs/packages-verification.md), [foundation](docs/verification.md).

## SFTP update and rollback checklist

- Record the commit and plugin version; retain the prior plugin directory and a verified database backup.
- Use a test environment first. Upload only the inner production-ready plugin directory.
- Use a hosting-supported maintenance window so requests cannot execute a mixture of old and new PHP files. Stage a complete directory and replace it when supported; do not assume SFTP performs an atomic live update.
- After replacement, visit administration and confirm both plugin/schema versions and saved settings. Reactivation is not required: normal `init` installs/upgrades the schema, verifies columns/indexes/InnoDB, and initializes only a missing capacity row. Resolve any database notice before entering test data. Database CREATE/ALTER/INDEX permissions and MySQL named locks are required for installation.
- Check page rendering, permissions, validation, timezone and dependency visibility. No cache clearing is normally needed; if the host serves stale PHP, use its supported cache reset.
- Do not restore an old database over later business changes. Older releases share schema 1 or ignore the tables, but **0.3.x bypasses availability enforcement**. Do not run older allocation code against current commitments; keep allocation disabled during any rollback and restore a compatible release. Future newer schema versions are rejected rather than downgraded automatically.
- Keep backups, settings, evidence, and secrets outside the replaceable plugin directory.

## References used during implementation

- [WordPress Settings API](https://developer.wordpress.org/plugins/settings/settings-api/)
- [Settings group capability filter](https://developer.wordpress.org/reference/hooks/option_page_capability_option_page/)
- [WooCommerce Square source](https://github.com/woocommerce/woocommerce-square/blob/trunk/woocommerce-square.php)
- [WPForms Signature Addon](https://wpforms.com/docs/how-to-install-and-use-the-signature-addon-in-wpforms/)
- [WooCommerce custom product queries](https://developer.woocommerce.com/docs/features/products/wc-get-products/)
- [WooCommerce product data save hook](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/admin/meta-boxes/class-wc-meta-box-product-data.php)
- [InnoDB locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html)
- [WordPress recurring cron scheduling](https://developer.wordpress.org/plugins/cron/scheduling-wp-cron-events/)
- [WordPress custom REST endpoints](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/)
- [WooCommerce price APIs](https://woocommerce.github.io/code-reference/)

Milestone 6 has not started and requires separate authorization.
