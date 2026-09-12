# Bike Rental Plugin

A reusable WordPress/WooCommerce bicycle-rental extension, developed locally on Windows and deployed as a self-contained directory through SFTP. Business identity belongs in configuration. This project is focused on bicycle rentals.

**Current release: 0.2.0 — Milestone 2, rental package management.**

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

**No public rental booking, inventory quantities, custom tables, reservations, checkout changes, payment processing, waiver forms, calendar, or inventory synchronization exist yet.**

## Installation for testing

1. Use a WordPress test site with PHP 8.3+ and WordPress 6.6+ (declared code baseline, not a completed compatibility matrix).
2. Back up the site/database before changing an existing installation.
3. Upload the repository's **inner** `bike-rental-plugin/` directory to `wp-content/plugins/` using SFTP.
4. The main file must end up at `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`.
5. Activate **Bike Rental Plugin** through WordPress Plugins.
6. Open **Bike Rentals > Settings**, configure the business and hours, and save.
7. Activate WooCommerce for package management. Create a Simple product and configure **Product data > Rental Settings**. No products are created by activation.

Do not upload the repository root, `.git`, `tests`, editor configuration, logs, or credentials. No compilation, Composer, Node.js, SSH, WP-CLI, or production build commands are required. One small JavaScript file controls rental-field visibility in the product editor; no custom CSS or public script is loaded.

WooCommerce is required for package management but is not a hard activation dependency. If absent, administrators can still configure the foundation and see an actionable notice. Square, WPForms, and a deposit extension are not required for this milestone.

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

WooCommerce stores these through product CRUD. No custom tables, duplicate price fields, or duplicate order fields are introduced. Deactivation, uninstall, and SFTP replacement preserve package metadata.

After `woocommerce_init`, later PHP code can use `BikeRentalPlugin\Packages`:

| Method | Contract |
|---|---|
| `is_rental_package($id_or_product)` | True for an existing Simple product with the rental marker; not a validity/availability guarantee. |
| `get_package($id_or_product)` | Valid configuration array or `null` for non-rental/unsupported/invalid metadata. Includes inactive and unpublished packages for administrative use. |
| `get_current_price($id_or_product)` | Current regular price decimal string or `null`. |
| `get_active_packages()` | Published, rental-active, valid Simple packages with an entered regular price, ordered by WooCommerce menu order/title/ID. No inventory check. |

The array includes `product_id`, `name`, `price`, `currency`, `duration_type`, `duration_amount`, `promotional_label`, boolean `active`, `product_status`, `display_order`, `tax_status`, and `tax_class`. Invalid IDs are rejected instead of being coerced into another product. Prefer passing the ID when a fresh read is needed; a supplied product object represents its current in-memory state.

The active catalog is small and reads all matching package products through `wc_get_products()`. The administration overview paginates 25 products at a time. A narrow, documented WooCommerce product-query extension adds the rental marker constraint; no direct SQL or direct product-table access is used. The query adapter targets WooCommerce's current standard product data store, independently of HPOS order storage.

These APIs return current product data, not historical reservation values. A later reservation milestone must snapshot the returned data when booking; snapshots are not implemented here.

## Settings contract

Options: `brp_settings` (configuration) and `brp_plugin_version` (installed code version marker). No schema version or custom tables are needed yet.

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

The plugin uses WordPress's configured timezone. A named city timezone is recommended for future daylight-saving handling. Settings are not yet used to calculate availability.

## Dependency detection limits

Detection uses loaded runtime identifiers and installed plugin headers, not presumed folder names. Inactive installations are listed but marked Missing for active availability. Network-active dependencies can be detected; network-wide configuration/provisioning is not implemented, so activate this plugin per site for testing.

Available only means active presence was detected. Every component still displays **Not yet integration tested**. Header detection does not prove successful initialization, credentials, licenses, or compatible versions. WPForms Lite is identified as installed WPForms but does not satisfy the later paid signature workflow. A deposit-like WooCommerce plugin is only a candidate until verified.

The intended later integrations are WooCommerce Square, a compatible deposit extension, and WPForms Elite with Signature Addon. This milestone neither reads their credentials nor changes their settings. Unknown products must be inspected before adding detection identifiers.

## Architecture

- `bike-rental-plugin.php`: headers, direct-access guard, explicit loading of two classes, activation and deferred bootstrap.
- `src/Plugin.php`: lifecycle, text domain, version marker, dependency visibility, conditional package loading after WooCommerce initializes.
- `src/Settings.php`: capabilities, native settings registration, strict validation, and configuration status.
- `src/settings-page.php`: native WordPress administration HTML, separate from validation logic.
- `src/Packages.php`: rental metadata validation, product-editor save hooks, package reads, and overview query.
- `src/package-fields.php` and `src/packages-page.php`: product tab and read-only overview.
- `assets/js/package-admin.js`: field visibility on product-edit screens only; PHP validates all submissions.
- `uninstall.php`: explicit data-preserving uninstall behavior.

There is no autoload framework or runtime dependency manager. All runtime PHP has direct-access protection. WordPress core handles foundation settings CSRF verification. Package saves additionally verify a product-bound nonce and both `edit_products` and object-level `edit_post` capabilities. Rental metadata is staged before WooCommerce's normal CRUD save, without recursive saves. Inputs are allowlisted. No SQL, secrets, remote calls, cron jobs, or public endpoints are registered.

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

The dependency-free package test script also runs all foundation checks. It uses small WordPress/WooCommerce API doubles, including a fake nonce verifier to exercise the package save gate. It does **not** run real WordPress nonce cryptography, WooCommerce persistence, a database, or a browser. The user confirmed Milestone 1's listed foundation functions in WordPress; Milestone 2 still requires dedicated test-site verification. See [Milestone 2 verification](docs/packages-verification.md) and the [foundation record](docs/verification.md).

## SFTP update and rollback checklist

- Record the commit and plugin version; retain the prior plugin directory and a verified database backup.
- Use a test environment first. Upload only the inner production-ready plugin directory.
- Use a hosting-supported maintenance window so requests cannot execute a mixture of old and new PHP files. Stage a complete directory and replace it when supported; do not assume SFTP performs an atomic live update.
- After replacement, visit administration and confirm the version and saved settings. Reactivation is not required to detect a code version change.
- Check page rendering, permissions, validation, timezone and dependency visibility. No cache clearing is normally needed; if the host serves stale PHP, use its supported cache reset.
- If necessary, restore the previous compatible plugin directory. Do not restore an old database over later business changes. No database migration exists in this milestone.
- Keep backups, settings, evidence, and secrets outside the replaceable plugin directory.

## References used during implementation

- [WordPress Settings API](https://developer.wordpress.org/plugins/settings/settings-api/)
- [Settings group capability filter](https://developer.wordpress.org/reference/hooks/option_page_capability_option_page/)
- [WooCommerce Square source](https://github.com/woocommerce/woocommerce-square/blob/trunk/woocommerce-square.php)
- [WPForms Signature Addon](https://wpforms.com/docs/how-to-install-and-use-the-signature-addon-in-wpforms/)
- [WooCommerce custom product queries](https://developer.woocommerce.com/docs/features/products/wc-get-products/)
- [WooCommerce product data save hook](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/admin/meta-boxes/class-wc-meta-box-product-data.php)

Milestone 3 requires separate authorization.
