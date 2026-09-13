# Verification — version 0.2.0

Historical Milestone 2 record. The user subsequently confirmed this milestone on the dedicated WordPress test site. Current work and pending deployment checks are in [Milestone 3 verification](reservations-verification.md).

Milestone 2: rental package management. All commits remain on `main`.

## Foundation status

The user reports that version 0.1.0 was installed and tested in WordPress: activation, generic branding, settings menu, timezone, dependency detection (including the listed commercial add-ons), settings persistence, weekly hours, horizon, increments, pickup time, and buffers work.

The implementation preserves those components. All **113 local foundation checks** continue to run as regression checks, with the expected release version updated. Their use of API doubles is unchanged; user-reported installation results are distinct from tests executed locally by the agent.

## Local Milestone 2 verification

Run `php -n tests/packages.php`; this includes `tests/foundation.php` and then exercises package behavior with WordPress/WooCommerce doubles. **208 checks passed: 113 foundation checks plus 95 package checks.** PHP 8.3.33 CLI is available outside the repository. No server build tools are required.

Coverage:

- Missing WooCommerce does not load the package class or crash; actionable notice rendering.
- Deferred/idempotent package hooks and the supported WooCommerce query extension.
- Normal product exclusion; marking/unmarking only the selected product.
- Hours/calendar-day metadata and active/inactive persistence through a CRUD double.
- Zero/negative/fractional/oversized/malformed duration rejection and valid limits.
- Unknown duration types and invalid flags; array inputs and promotional-label length.
- Plain-text promotional normalization and output escaping.
- Current regular price reads, price changes, sale-price independence, zero versus unset price.
- Published/active/valid/priced filtering and WooCommerce menu ordering.
- Invalid IDs, non-Simple types, and malformed metadata handled safely.
- No partial rental metadata mutation on validation failure.
- Product-bound nonce gate: missing, invalid, array, and another product's token rejected.
- Both general product-edit and object-specific edit permission required.
- Quick/bulk/other saves without the panel payload remain unchanged.
- No recursive CRUD save; no edits to unrelated metadata, product prices, or stock.
- Activation/version/uninstall preservation of metadata.
- Product panel, overview, repair rows, script scope, permission checks, and escaped HTML output.
- Static scope/branding checks for generic runtime code, no hard-coded selling prices, no stock mutation, raw SQL, public booking, checkout, or remote integration.

Also run PHP syntax checks for every runtime/test PHP file, direct-access execution checks for runtime PHP, and `git diff --check`. The small editor JavaScript requires no build; actual visibility behavior must be checked in the browser on the test site.

**Limitations:** the harness does not load WordPress/WooCommerce, use a real database, validate real nonce cryptography, execute WooCommerce's product data store, or run the browser. No Milestone 2 SFTP upload or live test-site test has been performed by the agent. The installed WooCommerce version and editor mode still need confirmation on the dedicated test site.

## Dedicated test-site acceptance checklist

Record actual WordPress, WooCommerce, PHP, and browser versions with the results. Use the standard WooCommerce product editor; alternative/new product editors, importers, and REST-based metadata editing are not implemented.

1. Back up the test site and record existing Bike Rentals settings. Upload the inner plugin directory through SFTP and verify version 0.2.0 without reactivation or settings loss.
2. Recheck the existing settings page, timezone, and dependency display.
3. With WooCommerce active, open a Simple product and confirm the Rental Settings tab. Confirm Square/WPForms/deposit plugins are not prerequisites for the tab.
4. Create `Test 4 Hour Rental`, enter a temporary regular price, mark Use as Rental Package, set Hours / 4 and Rental Active, save, and reload. Confirm all fields persist.
5. Change Regular price and save. Confirm the Packages overview reports the new price. Verify `Packages::get_package($product_id)` after `woocommerce_init` returns that same decimal string when inspected on the test site.
6. Create `Test 3 Day Rental`, set Calendar Days / 3, promotional label `Third Day FREE`, and any temporary regular price. Save/reload. Confirm no additional discount/coupon is generated.
7. Disable Rental Active and save. Confirm it remains visible as inactive in administration and is excluded by `Packages::get_active_packages()`. This flag does not remove ordinary WooCommerce purchase buttons.
8. Change product publication to draft/private. Confirm the active reader excludes it even if Rental Active remains checked.
9. Uncheck Use as Rental Package and save. Confirm it is no longer listed as a rental; unrelated product fields/metadata remain unchanged.
10. Confirm ordinary non-rental products continue saving normally. Check quick/bulk edits do not erase rental metadata.
11. Set Advanced > Menu order on multiple packages. Confirm overview order; check pagination if more than 25 configured packages exist.
12. Bypass browser constraints and submit zero, negative, fractional and excessive duration; invalid type/flags; malformed values; and an oversized promotional label. Confirm an explanatory WooCommerce error and unchanged prior rental metadata. Standard product fields may still save, as the notice explains.
13. Test missing/expired/wrong-product nonce and denied product-edit capabilities. Confirm no rental metadata changes. Verify a shop manager with normal product-edit permission can save.
14. Test a non-Simple product. Rental controls are not offered; existing marked metadata must not make a variable/grouped/external product an eligible rental. Switching back to Simple may restore the retained configuration.
15. Test a blank regular price, an explicit zero price, and a sale price. Blank is not an eligible active package; zero is an explicitly configured value. The package API/overview uses Regular price, while normal WooCommerce storefront pricing remains untouched.
16. Disable WooCommerce on the test site. Confirm foundation access and an actionable notice without a fatal error. Reactivate it and confirm metadata still exists.
17. Deactivate/reactivate this plugin and replace its files via SFTP. Confirm both foundation settings and package metadata survive. No products should be created automatically.
18. Verify keyboard labels, checkbox visibility, and error rendering in a narrow browser window. If JavaScript is unavailable, server validation and safe enable/disable saves must still work.
19. Confirm no public rental shortcode, date selector, availability/calendar, reservation, checkout hold, payment, waiver, or Square stock synchronization has been introduced.

At the time of this historical record, Milestone 3 had not started; see the current verification record linked above. Test products must not be treated as production rentals while reservation/checkout enforcement is absent.
