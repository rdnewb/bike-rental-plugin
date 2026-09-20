# Settings tabs — 0.7.2

Plugin version **0.7.2**, schema **1**. This release reorganizes the administrator settings page only. No database migration or saved-value migration runs. Public branding output, booking, reservations, scheduling, availability, calendar, Full Payment and deposit behavior are unchanged. Waiver integration has not started.

## Tab architecture and usage

Open **Bike Rentals > Settings**. The base URL opens **General**. Normal keyboard-accessible links use WordPress `nav-tab-wrapper`, `nav-tab` and `nav-tab-active` classes, with `aria-current="page"` on the selected tab. The single page heading is **Bike Rentals Settings**.

- General: `admin.php?page=brp-settings&tab=general`
- Booking Form Branding: `admin.php?page=brp-settings&tab=branding`

Unknown or malformed query tabs fall back to General. Navigation does not depend on JavaScript. The two-tab registry and separate panel templates keep future additions localized; no future tabs have been added.

**General** contains business name, booking horizon, minimum notice, booking increment, calendar-day pickup time, preparation and turnaround buffers, weekly operating hours, Payment Mode, dependency/integration information, configuration readiness, WordPress timezone and plugin version. Their keys, defaults, validation and meaning are unchanged.

**Booking Form Branding** contains the existing heading, intro, Select/Selected/Change button text, six colors, radius preset, Media Library image, administrator-only Advanced Custom CSS, and Restore Branding Defaults. None of these controls appear on General. Native color/media helpers load on Branding only. See [existing branding behavior and restrictions](booking-branding-verification.md).

## Save and reset behavior

Both panels use the existing `brp_settings` option, `brp_settings_group`, `options.php` endpoint, Settings API nonce and capability checks (`manage_options` or `manage_woocommerce`). A hidden `brp_settings_tab` field identifies the submitted panel; it is not stored in the option. Invalid submitted tab identifiers are rejected.

General validates its complete operational form using the existing validator, merges into the saved option, and preserves branding and unknown existing keys. It ignores forged branding fields. Branding validates its own fields using the existing branding validator and preserves the General values exactly; forged operational fields are ignored. An invalid submission retains the entire previous option. Legacy complete-form submissions without the new marker retain their previous behavior.

Restore Branding Defaults uses the existing branding-only reset. General remains unchanged. Shop managers retain their existing restriction against overwriting or clearing administrator CSS.

Core `settings_fields()` includes the current URL in `_wp_http_referer`. WordPress `options.php` uses that referer for its `settings-updated` return redirect. Success and validation notices render above the active form, including after reset. A base-URL General save returns to the base URL, which still selects General.

Existing saved values survive the upgrade without being rewritten. A corrupted scalar settings option must still be repaired on General before Branding can save. Switching tabs is ordinary navigation, so save pending edits before switching. Settings retain ordinary WordPress concurrent-save behavior; this release does not introduce settings revision locking.

## Automated verification

Local disposable WordPress 7.0, WooCommerce 11.1.0, PHP 8.3.33 and MariaDB 11.4.8; local Chrome through Playwright for browser checks. **2,292 passing check executions / 2,034 distinct checks**, including 258 checks repeated in both legacy order storage (CPT) and HPOS. All **52 PHP files** in runtime/tests pass syntax validation. The diff passes whitespace validation.

| Suite | Passing checks |
| --- | ---: |
| Foundation and rental packages | 208 |
| Storage, reservation editing, availability and active policy | 407 |
| Calendar scheduling and generic duration matrix | 667 |
| Public booking integration | 74 |
| Multiprocess inventory concurrency | 70 |
| Checkout, CPT + HPOS | 192 |
| Store API rental checkout, CPT + HPOS | 42 |
| Store API ordinary checkout, CPT + HPOS | 8 |
| Cart hold cleanup, CPT + HPOS | 94 |
| Product grid integration + browser | 23 + 45 |
| Missing WooCommerce / persistence | 13 |
| Deep links and calendar, CPT + HPOS | 180 |
| Deep links and calendar browser | 41 |
| Existing branding integration + browser | 80 + 42 |
| New settings-tab integration | 85 |
| New settings-tab browser | 21 |

The 85 new integration checks cover default/direct/invalid tab selection, links, heading, panel isolation, all existing settings controls, subset preservation, forged cross-tab fields, branding reset, validation notices, existing keys/data, Full Payment selection, capabilities, nonce validation and the core referer/return contract. Core `check_admin_referer()` is exercised with valid and invalid nonces; the invalid nonce aborts before mutation. Both unauthorized page renders and saves are rejected. Registered Settings API callbacks are also tested through `update_option()` for General saves and Branding resets. These are real WordPress function/integration checks, not complete HTTP `options.php` submissions.

The 21 new browser checks use the rendered PHP panels with native WordPress admin styles at widths 1280, 782 and 375, with JavaScript disabled. They verify tab links fit, keyboard navigation, selected styling, panel isolation and the native form endpoint. Mobile Branding was visually inspected. Native Media Library/color-picker interactions and full admin chrome on the dedicated test site remain manual acceptance items.

Run the disposable suites with `BRP_ALLOW_DISPOSABLE_TESTS=1` and `BRP_TEST_WP_ROOT` pointing to the guarded local fixture. `tests/settings-tabs.php` can export panels using `BRP_TABS_FIXTURE_DIR`; `tests/settings-tabs-browser.cjs` consumes them with that variable and `BRP_TEST_WP_ROOT`. Browser suites can use `BRP_BROWSER_CHANNEL=chrome`. Existing scripts and environment guards are unchanged.

Payment regression tests use gateway/payment evidence doubles. No real Square payments were made. Dedicated-site Square sandbox acceptance remains pending from earlier releases. The archived, unsupported deposit-provider compatibility preflight is not part of the passing Full Payment matrix.

## Manual test-site acceptance still required

1. Back up the installed plugin folder and saved General/Branding values. Upload the complete 0.7.2 folder and clear relevant caches. Verify plugin version 0.7.2 and schema 1.
2. Open Bike Rentals > Settings without a tab parameter. Confirm General is active, both links are visible, and only General controls/dependencies appear.
3. Open Branding directly and through the link. Confirm all existing saved branding values appear and General controls are absent. Test color pickers and image selection/removal. Check keyboard navigation and typical desktop/mobile admin widths.
4. Change one branding label and save. Confirm the return to Branding, success notice, persisted label and unchanged General values (especially Full Payment, hours and buffers).
5. Change a harmless General value and save. Confirm return to General, success notice and unchanged Branding values. Restore the test value afterward.
6. Submit an invalid branding color and an invalid General numeric value. Confirm useful validation messages on the respective tab and no saved-value loss.
7. Restore Branding Defaults. Confirm return to Branding with success notice, branding-only reset and untouched General settings. Restore the site's preferred branding afterward.
8. Verify direct General/Branding URLs and an invalid tab. Test administrator/shop manager access and an unauthorized account; CSS permissions remain unchanged.
9. Check the public booking page and a package deep link, a normal Full Payment checkout, and the weekly calendar. This release changes none of those flows.

No remote deployment or test-site changes were performed during local verification.

## Changed files

- Runtime: `bike-rental-plugin/bike-rental-plugin.php`, `bike-rental-plugin/readme.txt`, `bike-rental-plugin/src/Plugin.php`, `bike-rental-plugin/src/Settings.php`, `bike-rental-plugin/src/settings-page.php`, new `bike-rental-plugin/src/settings-general.php`, and the admin-only assets condition in `bike-rental-plugin/src/Branding.php`.
- Tests: `tests/foundation.php` (version expectations), `tests/booking-branding.php` (render the Branding tab), new `tests/settings-tabs.php`, new `tests/settings-tabs-browser.cjs`.
- Documentation: `README.md`, `CHANGELOG.md`, and this file.

## Release and SFTP

Package: `.release/milestone7a/bike-rental-plugin-0.7.2.zip`, containing only the complete `bike-rental-plugin/` runtime folder. No tests, repository files or local fixtures are included.

All **40 runtime files** were checked against the source by SHA-256. ZIP SHA-256: `1AAFAFC859B2613AB7C33DF92F01B130125DBD8F8F555B3E391DBC79D4C69878`.

Upload the contents of local folder:

`C:\Users\RandyNewby\Documents\GitHub\bike-rental-plugin\bike-rental-plugin`

to the site's exact plugin folder:

`wp-content/plugins/bike-rental-plugin/`

The main file must end up at `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`; include the new `src/settings-general.php` file. No schema upgrade or reactivation is required.

Commit on `main`: `feat: organize rental settings into admin tabs in 0.7.2`. Do not push or deploy as part of this task.
