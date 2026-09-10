# Milestone 1 verification

Version: **0.1.0**. Branch: `feature/plugin-foundation`.

## Completed locally

- PHP syntax checks for all runtime PHP files and the standalone test script.
- **113 passing behavioral checks** in `tests/foundation.php`, using explicit WordPress API doubles:
  - Defaults and activation/version/uninstall preservation.
  - Scheduling ranges, valid boundary values, unsupported increments, malformed inputs, invalid local times, weekday completeness, and opening/closing order.
  - All-or-nothing invalid saves, name normalization, discarded unknown keys.
  - Administrator, WooCommerce manager, and denied-user permission paths.
  - Settings registration and capability-filter wiring.
  - Missing/inactive/network-active dependency detection and renamed installation directories.
  - HTML rendering with normal/corrupt settings, timezone warnings, and escaped business-name output.
- Source review of nonce delegation: form calls `settings_fields('brp_settings_group')`, posts to core `options.php`, and registers only `brp_settings` under that group. WordPress performs the nonce check; the test double does **not** verify real CSRF handling.
- Direct-access protection checked for runtime PHP, including the separate uninstall guard.
- Scope/branding searches: no customer-specific identity, selling prices, direct SQL, stock synchronization, public booking handlers, checkout changes, or remote requests in runtime code.
- Deployment review: no build step or runtime development dependency.

The main local verification uses an official portable PHP 8.3.33 Windows CLI outside the repository, downloaded with its published SHA-256 verified. An existing local PHP 8.2.11 CLI also passed the initial runtime-file syntax checks; the declared deployment minimum remains PHP 8.3.

## Not performed: real WordPress installation tests

No local WordPress/database installation or connected test-site credentials were available. The checks above do not establish actual WordPress activation, nonce enforcement, database persistence, browser behavior, or third-party compatibility.

Perform these on the test site before accepting the milestone as installation-verified:

1. Upload the inner plugin folder via SFTP and activate. Confirm version 0.1.0 and no errors with WordPress debug logging enabled on the test site.
2. With WooCommerce inactive, verify administrator access and Missing status without a fatal error.
3. With WooCommerce active, verify both an administrator and a shop manager can view and save settings. Verify an ordinary subscriber/customer cannot access the page or save the option.
4. Submit a valid save, then test a missing nonce and an invalid nonce directly against `options.php`. Confirm rejection and unchanged settings. Do not treat browser field validation as this test.
5. Bypass browser constraints and submit negative/fractional/array inputs, invalid time increments, missing weekdays, and invalid/equal/reversed open-day times. Confirm understandable errors and no partial save.
6. Confirm WordPress's real text sanitization and escaping, including quotes, angle brackets, international names, and an oversized business name.
7. Save all seven weekdays, reload, deactivate/reactivate, and replace plugin files through SFTP. Confirm saved values remain intact and defaults are not reapplied.
8. Verify Not Configured, Partially Configured, and Ready for Package Setup transitions. No status should imply production or booking readiness.
9. Test a named city timezone and a fixed UTC offset. Confirm warning behavior and that the plugin never changes the timezone.
10. Test dependency detection using the actual installed versions, active/inactive states, and commercial add-on headers. Confirm every integration still says Not yet integration tested. Check WPForms Elite licensing separately.
11. Use keyboard navigation and a narrow browser window. Verify native settings layout, labels, controls, and readable error messages.
12. Confirm no public shortcode/page, product, reservation, inventory, payment, or waiver changes occur.
13. On a disposable test copy, delete/uninstall the plugin and reinstall it. Confirm retained settings. Do not run destructive site cleanup during this test.

Use per-site activation. Multisite network-wide option provisioning is outside this milestone. Real Square/deposit/waiver integration tests belong to later authorized phases, not to this foundation release.
