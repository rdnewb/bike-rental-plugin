# Bike Rental Plugin

A reusable WordPress/WooCommerce bicycle-rental extension, developed locally on Windows and deployed as a self-contained directory through SFTP. Business identity belongs in configuration. This project is focused on bicycle rentals.

**Current release: 0.1.0 — Milestone 1, plugin foundation.**

## What works

- Native **Bike Rentals > Settings** page.
- Business name, booking horizon, notice, time increment, pickup time, preparation/turnaround buffers, and weekly operating hours.
- Server-side validation. An invalid submission is rejected as a whole, with the previous configuration retained and explanatory errors shown.
- Access for users with `manage_options` or `manage_woocommerce`; no role names or role mutations.
- WordPress Settings API saves through `options.php`, including its nonce and capability checks. The plugin filters only its own settings group's save capability and also checks permission in its sanitizer and page renderer.
- WordPress timezone display and an informational warning for fixed offsets/unrecognized timezone settings; no automatic timezone changes.
- Installed/active dependency visibility for WooCommerce, WooCommerce Square, WPForms, Signature Addon, and recognized WooCommerce deposit solutions.
- Settings preservation across updates, deactivation/reactivation, and uninstall.

**No public booking, packages/prices, inventory quantities, custom tables, reservations, checkout changes, payment processing, waiver forms, calendar, or inventory synchronization exist yet.**

## Installation for testing

1. Use a WordPress test site with PHP 8.3+ and WordPress 6.6+ (declared code baseline, not a completed compatibility matrix).
2. Back up the site/database before changing an existing installation.
3. Upload the repository's **inner** `bike-rental-plugin/` directory to `wp-content/plugins/` using SFTP.
4. The main file must end up at `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`.
5. Activate **Bike Rental Plugin** through WordPress Plugins.
6. Open **Bike Rentals > Settings**, configure the business and hours, and save.

Do not upload the repository root, `.git`, `tests`, editor configuration, logs, or credentials. No compilation, Composer, Node.js, SSH, WP-CLI, or production build commands are required. The asset/language directories contain only placeholders until real assets are needed; no custom JavaScript/CSS is loaded now.

WooCommerce is not a hard activation dependency in this milestone. If absent, administrators can still configure the foundation and see its Missing status. Future commerce features will require WooCommerce.

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
- `src/Plugin.php`: lifecycle, text domain, version marker, read-only dependency inventory.
- `src/Settings.php`: capabilities, native settings registration, strict validation, and configuration status.
- `src/settings-page.php`: native WordPress administration HTML, separate from validation logic.
- `uninstall.php`: explicit data-preserving uninstall behavior.

There is no autoload framework or runtime dependency manager. All runtime PHP has direct-access protection. WordPress core handles the Settings API save route and CSRF verification. Inputs are allowlisted; unknown submitted keys are discarded. No SQL, secrets, remote calls, cron jobs, public endpoints, or product hooks are registered.

## Development and Git

Use GitHub Desktop to clone/update, open the local repository in Visual Studio Code, and edit locally. Git/GitHub is the source of truth. Do not edit production files directly unless expressly authorized.

Use `main` for reviewed release-ready work and short-lived branches such as `feature/plugin-foundation`. Keep commits focused, push reviewed changes, and tag deployments using semantic versions. Keep the plugin header, `Plugin::VERSION`, readme stable tag, and changelog synchronized. Do not commit credentials, exports, signed evidence, or local dependencies.

No build command is needed. To syntax-check with a local PHP executable in PowerShell:

```powershell
$phpPath = 'C:\path\to\php.exe'
Get-ChildItem .\bike-rental-plugin -Recurse -Filter *.php | ForEach-Object {
    & $phpPath -n -l $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax failure: $($_.FullName)" }
}
& $phpPath -n .\tests\foundation.php
if ($LASTEXITCODE -ne 0) { throw 'Foundation checks failed' }
git diff --check
```

The dependency-free test script runs validation, capability selection, default preservation, dependency detection, and HTML rendering against small WordPress API doubles. It does **not** run real WordPress nonce validation, WooCommerce, a database, or a browser. The exact verification results and pending installation checklist are in [docs/verification.md](docs/verification.md).

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

Milestone 2 requires separate authorization.
