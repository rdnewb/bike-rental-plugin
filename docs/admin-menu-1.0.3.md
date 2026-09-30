# Bike Rental Plugin 1.0.3 verification

Runtime **1.0.3**; schema **2**, unchanged. Work on `main`; no branch, push or deployment.

## Change

Previously `Settings::add_menu()` omitted the position argument, so WordPress appended Bike Rentals near the bottom. It now passes numeric **56** to `add_menu_page()`. WooCommerce registers at **55.5**. This is the nearest safe numeric placement after WooCommerce, not a guarantee of immediate visual adjacency: WooCommerce groups its menus and other plugins may reorder or occupy nearby positions. WordPress handles position collisions through its registration API. No JavaScript, DOM reordering, global menu mutation or menu-order filter was introduced.

References checked: [WordPress registration and collision behavior](https://developer.wordpress.org/reference/functions/add_menu_page/) and [WooCommerce menu registration/order](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/admin/class-wc-admin-menus.php).

The icon, capability selection, hooks, callbacks, roles, nonces and page slugs are unchanged. Settings, Fleet, Reservations, Calendar, Availability and Packages keep their existing submenu registrations; License and Waivers remain existing Settings tabs. Direct URLs retain their original destinations.

## Verification

- `php -n tests/admin-menu.php`: 52 menu checks plus 114 foundation checks. Covers administrator/shop-manager registrations, numeric position, icon, same-position collision and nearby-plugin preservation, no duplicates, unchanged submenu order/slugs/parents/capabilities/callables, direct URLs, denied unauthorized access and schema 2. Collision checks use an explicit API double of core's documented rule, not a live WordPress installation.
- `php -n tests/packages.php`: 209 checks (includes the same 114 foundation checks).
- `php -n tests/production-cleanup.php`: 35 checks.
- `php -n tests/availability-ui.php`: 86 checks.
- `php -n tests/license-enforcement.php`: 12 checks.
- **394 distinct checks passed**, excluding the repeated foundation run. PHP lint passed for 70 files. `git diff --check` passed.

All 44 runtime files outside Settings, the two runtime version markers and readme are unchanged from 1.0.2 after line-ending normalization. Settings changes only add the numeric position and an explanatory comment. Reservations, Calendar, Fleet, Availability, Packages, Settings page behavior, Waivers, Licensing, public booking and WooCommerce checkout implementations are preserved. No schema migration or storage change.

## Manual QA still required

No disposable WordPress/database fixture is configured, so live WordPress menu rendering, provider integration and concurrency suites were not run. On staging with the actual plugin set, check administrator and shop-manager sidebars: one Bike Rentals menu near WooCommerce, below Dashboard; all core menus retained. Open every submenu and direct bookmarked URL, including waiver/license tabs. Verify unauthorized users remain denied and inspect a nearby-position plugin collision. Smoke-test reservation/calendar/fleet/block/package/settings workflows, public booking, WooCommerce/Square checkout, waivers and license enforcement. The fuller [deployment QA checklist](admin-cleanup-1.0.2.md#required-deployment-site-qa) remains applicable.

## Package and deployment

`python tools/package.py` verified **48 runtime-only files**, ZIP CRC and every archived byte against source. No tests, development artifacts, dependencies or NT License Controller.

ZIP: `.release/production/bike-rental-plugin-1.0.3.zip`

SHA256: `a81af1f8655e815db298fe6d159da4ad70943418cefb35e27135d3264ddd17c1`

Exact SFTP folder relative to WordPress root: **`wp-content/plugins/bike-rental-plugin/`**. Upload the inner plugin directory. The host-specific absolute document root is not supplied. No deployment performed.

Commit message: `fix: move Bike Rentals higher in WordPress admin menu in 1.0.3`. Actual commit hash is reported in the completion response. Stop after 1.0.3.

## Files changed

- `CHANGELOG.md`
- `README.md`
- `bike-rental-plugin/bike-rental-plugin.php`
- `bike-rental-plugin/readme.txt`
- `bike-rental-plugin/src/Plugin.php`
- `bike-rental-plugin/src/Settings.php`
- `docs/admin-menu-1.0.3.md`
- `tests/admin-menu.php`
- `tests/availability-ui.php`
- `tests/foundation.php`
- `tests/licensing-client.php`
