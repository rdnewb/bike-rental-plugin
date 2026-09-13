# Bike Rental Plugin

A reusable WordPress/WooCommerce bicycle-rental extension, developed locally on Windows and deployed as a self-contained directory through SFTP. Business identity belongs in configuration. This project is focused on bicycle rentals.

**Current release: 0.4.0 — Milestone 4, shared fleet availability and double-booking protection.**

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
- A reusable PHP package reader for later milestones; no public endpoint.
- **Bike Rentals > Fleet**: persistent shared fleet quantity, dated or indefinite quantity blocks, editing, and disabling.
- **Bike Rentals > Reservations**: paginated list, manual test creation, and a full revision-protected edit form for package, quantity, dates, status, and issue code.
- Two InnoDB tables, verified versioned schema installation on normal initialization, and current reservation snapshots protected from direct editing and automatic catalog changes.
- One sweep-line availability service, shared capacity-row locking for all allocation changes, expiring idempotent holds, and conflict protection for reservation edits, blocks, and fleet reductions.
- **Bike Rentals > Availability test**: local occupied-interval test with fleet, peak usage, available quantity, and Fits / Does not fit results; manual hold cleanup.

**No public rental booking, checkout changes, payment processing, waiver forms, customer emails, calendar, or inventory synchronization exist yet. Manual reservations are administration test records and do not establish fulfillment readiness.**

## Installation for testing

1. Use a WordPress test site with PHP 8.3+ and WordPress 6.6+ (declared code baseline, not a completed compatibility matrix).
2. Back up the site/database before changing an existing installation.
3. Upload the repository's **inner** `bike-rental-plugin/` directory to `wp-content/plugins/` using SFTP.
4. The main file must end up at `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`.
5. Activate **Bike Rental Plugin** through WordPress Plugins.
6. Open **Bike Rentals > Settings**, configure the business and hours, and save.
7. Activate WooCommerce for package management. Create a Simple product and configure **Product data > Rental Settings**. No products are created by activation.
8. On the next normal request, schema version **1** installs the two rental tables and a single fleet capacity row (initial quantity 10). Open **Fleet** to configure it, then **Reservations** for storage testing.

Do not upload the repository root, `.git`, `tests`, editor configuration, logs, or credentials. No compilation, Composer, Node.js, SSH, WP-CLI, or production build commands are required. One small JavaScript file controls rental-field visibility in the product editor; no custom CSS or public script is loaded.

WooCommerce is required for package management, new manual reservations, and the full reservation edit form, which validates the selected package. It is not a hard activation dependency. If absent, administrators can still configure settings/fleet and read existing reservation records and snapshots; internal lifecycle helpers remain available. Square, WPForms, and a deposit extension are not required for this milestone.

## Rental packages

Use the **standard WooCommerce product editor**, not a second catalog editor. Select Simple product, enter the title and Regular price, and open Rental Settings:

- **Use as Rental Package:** marks this selected product only. Unchecking it and saving removes the five owned metadata fields, leaving all other product data intact.
- **Duration Type:** `hours` or `calendar_days`.
- **Duration Amount:** integer 1–8760 hours or 1–365 calendar days. No rental time arithmetic is implemented yet.
- **Promotional Label:** optional plain text, maximum 120 Unicode characters. Display text only; no discount, coupon, or daily-rate dependency.
- **Rental Active:** defaults off, separate from product publication. Inactive packages are retained in administration but excluded from the active-package reader.

Use standard WooCommerce fields for name, description, image, tax status/class, and **Advanced > Menu order**. The overview follows menu order, then title and ID for ties. The plugin never writes price, stock, or tax fields.

Invalid submissions retain all previous rental metadata and show WooCommerce admin errors. Standard WooCommerce fields (including price/title) may still save; the error does not roll back unrelated product edits. Quick/bulk edits without the rental panel payload leave rental metadata untouched. Changing a rental to a non-Simple type excludes it from the reader but retains metadata for repair; switching back to Simple restores that configuration.

**Price contract:** the package reader uses `get_regular_price('edit')` and returns a non-negative decimal string, or `null` when unset/invalid. An explicitly entered zero is distinct from an unset price. Sale prices are not used by the rental package reader. The plugin does not change normal WooCommerce sale pricing or purchasing behavior in this milestone. Prices are returned as stored; WooCommerce tax display/calculation is not performed by this reader.

**Test-site behavior:** published WooCommerce products can still have ordinary product pages/purchase buttons. Rental Active is not a storefront-purchase restriction. Keep test products/site access appropriate for testing; rental checkout enforcement belongs to a later milestone.

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

- Schema option `brp_db_version` remains **1**, separate from plugin version **0.4.0**. No columns, tables, or indexes changed. Tables use the actual WordPress prefix: `{prefix}brp_reservations` and `{prefix}brp_availability`.
- Availability row **1** is the sole capacity row. Its saved quantity is authoritative and is never reset during upgrades. Fleet quantities must be positive integers. Capacity is independent of WooCommerce and Square stock.
- Blocks reserve a quantity against a local start and optional end; a reason is required. Active blocks and reservations share the same availability calculation. Block replacements exclude their existing allocation; fleet reductions must support peak combined usage across all current/future commitments.
- Administrator input/display uses the current WordPress timezone. Storage uses UTC. Invalid dates, daylight-saving gaps, repeated clock times, and changed form timezones are rejected.
- New test reservations use active, published, valid packages and an explicit start/end. Quantity must fit total fleet and inventory-consuming records must fit remaining availability. Package duration, business hours, notice, and horizon are not yet enforced.
- Occupied intervals include preparation/turnaround buffers captured at creation. Administrative edits reuse those buffers. Changes to schedule, quantity, or status refresh those values in the current snapshot while keeping the agreed package price/duration. A package replacement captures the new package name, current WooCommerce selling price (`get_price('edit')`, including an active sale), duration, promotional label, and currency. Initial manual creation retains the existing regular-price contract.
- The **View / edit** detail page edits package, quantity, local start/end, any of the six valid statuses, and an optional issue code/short note (64 UTF-8 bytes). The current selection must still be a valid rental package; an existing inactive/unpublished package can retain its agreed terms, while a replacement must be published, active, and priced.
- Reference, created time, order relationships, IDs, hashes, and raw snapshot JSON cannot be edited. One successful multi-field save increments revision once and records the current UTC modification time. Stale revisions fail with a reload instruction; no-op saves leave the row and snapshot unchanged. Timestamps have one-second precision; revision distinguishes saves within one second.
- Administrative corrections are available for all six statuses, including completed/cancelled/expired records. Existing lifecycle helpers retain their ordinary transitions: `hold → confirmed / cancelled / expired`, `confirmed → active / cancelled`, `active → completed`. The explicit hold-confirmation helper also allows an expired result after a fresh availability check. Cancellation is persistent, with no delete action.
- Edits never rewrite WooCommerce orders or other reservations. Completing an active rental creates a dated quantity block when its captured turnaround buffer is positive. There is no audit-history table or automatic retention of previous snapshot revisions; future audit/history functionality may retain them. The edit page confirms availability enforcement and explains that payment/fulfillment checks remain deferred.

## Availability, holds, and transactions

`Availability::check($occupied_start_utc, $occupied_end_utc, $quantity = 1, $reservation_id = null, $block_id = null)` returns `total_capacity`, `peak_existing_usage`, `available_quantity`, `requested_quantity`, and `fits`, or `WP_Error`. Inputs use UTC SQL datetime strings; `null` end represents an indefinite internal interval. Exclusion IDs are for replacement checks by trusted PHP callers. This admin/internal method returns aggregate quantities, not customer details, and is not a public endpoint. A read is a point-in-time answer; allocation services always recheck before writing.

The single sweep clips relevant occupied intervals to the request, sorts quantity events chronologically with ends before starts at ties, and subtracts **peak simultaneous usage** from fleet capacity. Intervals are half-open `[start, end)`. Confirmed reservations, holds with future expiry, active rentals, and active blocks consume inventory; cancelled/completed/expired records do not. Existing overbooked development data is preserved and can produce a negative available quantity; resolve it before further allocation.

**Active-return policy:** an active rental consumes from its occupied start indefinitely until staff records completion. This conservative rule also affects future availability and can reject activation when future commitments already fill the fleet. At actual completion, its captured turnaround minutes become a dated block starting at the database UTC time; zero buffer releases capacity immediately. The completed reservation itself is ignored. Staff can inspect these blocks in Fleet. Administrative corrections remain possible and require care; this is not a fulfillment workflow.

`Reservations::create_hold($input, $request_key, $session_hash)` uses the same local package/quantity/start/end input as manual creation. The server computes the intent hash. Same key, intent, and session return the existing result, including terminal results, without renewing expiry; changed intent/session is rejected. Manual creation forms carry a stable request key and use a hash of the administrator's ID as their current admin-test identity. Future customer session/payment integration must establish its own trusted boundary.

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

The plugin uses WordPress's configured timezone and rejects ambiguous/nonexistent local times. Preparation and turnaround settings feed occupied intervals; other scheduling settings remain configuration for later milestones.

## Dependency detection limits

Detection uses loaded runtime identifiers and installed plugin headers, not presumed folder names. Inactive installations are listed but marked Missing for active availability. Network-active dependencies can be detected; network-wide configuration/provisioning is not implemented, so activate this plugin per site for testing.

Available only means active presence was detected. Every component still displays **Not yet integration tested**. Header detection does not prove successful initialization, credentials, licenses, or compatible versions. WPForms Lite is identified as installed WPForms but does not satisfy the later paid signature workflow. A deposit-like WooCommerce plugin is only a candidate until verified.

The intended later integrations are WooCommerce Square, a compatible deposit extension, and WPForms Elite with Signature Addon. This milestone neither reads their credentials nor changes their settings. Unknown products must be inspected before adding detection identifiers.

## Architecture

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
- `src/DataAdmin.php`, `src/fleet-page.php`, and `src/reservations-page.php`: capability/nonce-protected administration test tools.
- `assets/js/package-admin.js`: field visibility on product-edit screens only; PHP validates all submissions.
- `uninstall.php`: explicit data-preserving uninstall behavior.

There is no autoload framework or runtime dependency manager. All runtime PHP has direct-access protection. WordPress core handles foundation settings CSRF verification. Package saves additionally verify a product-bound nonce and both `edit_products` and object-level `edit_post` capabilities. Rental metadata is staged before WooCommerce's normal CRUD save, without recursive saves. Fleet/reservation mutations require management capabilities and operation/record-bound nonces. Only the registered cleanup action may invoke housekeeping without a logged-in manager. Inputs are allowlisted; dynamic SQL uses prepared identifiers/values. WooCommerce orders are accessed only through WooCommerce APIs. No secrets, remote calls, or public endpoints are registered.

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

Version 0.4.0 verification covers **611 checks**: 208 foundation/package checks with API doubles, 159 real storage checks, 96 editing checks, 98 availability checks, 40 multiprocess concurrency checks, and 10 missing-WooCommerce checks. Four older editing concurrency assertions were replaced by the stronger real simultaneous-process suite, not counted twice. See [current verification, reproducible commands, and test-site checklist](docs/availability-verification.md). The user confirmed Milestones 1–3 on the dedicated test site; 0.4.0 SFTP/browser acceptance remains pending. Historical records: [0.3.1](docs/reservation-editing-verification.md), [0.3.0](docs/reservations-verification.md), [0.2.0](docs/packages-verification.md), [foundation](docs/verification.md).

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

Milestone 5 has not started and requires separate authorization.
