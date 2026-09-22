# Licensing client extraction verification

The original server implementation and historical Phase 1 verification moved to [rdnewb/nt-license-controller](https://github.com/rdnewb/nt-license-controller). Current controller version is **0.2.0**, schema **2**. Bike Rental Plugin remains **0.9.0**, schema **2**, because all distributed runtime/readme files remain byte-identical to the preceding release.

Removed from this repository:

- Entire `nt-license-controller/` source folder: bootstrap, readme, uninstall, Store/Licenses/Api/Admin (seven files).
- `tests/licensing-controller.php`, `tests/license-controller-bootstrap.php`, `tests/license-controller-worker.php` (now owned by the separate controller repository).
- `docs/license-controller-api.md`; replaced combined server/client architecture and verification with client-only guidance. Historical verification is archived on the controller side.
- Old ignored controller ZIP moved to the standalone repository's `.release/legacy/`. Controller releases are now built there.

Retained unchanged: `bike-rental-plugin/src/License.php`, `LicenseAdmin.php`, bootstrap/hooks/guard integration, settings tab, `BRP_LICENSE_CONTROLLER_URL`, product `bike-rental-plugin`, encrypted option, installation ID, cache, grace, daily validation and compatibility-mode controls. Payment, reservation and waiver business behavior is unchanged.

Client regression: **354 passing assertions**: 55 cross-repository licensing checks, 209 package/foundation checks and 90 settings-tab checks. These include original activation/deactivation, request format/response parsing, offline grace and UI checks, plus product deactivation/reactivation against the standalone server. The server test worker is external; there is no controller source folder in the rental repository. All 64 rental runtime/test PHP files passed syntax checks.

Test prerequisites use the existing guarded disposable rental WordPress/database fixture. Set `BRP_ALLOW_DISPOSABLE_TESTS=1`, `BRP_TEST_WP_ROOT` to it, `NTLC_ALLOW_DISPOSABLE_TESTS=1`, `NTLC_TEST_WP_ROOT` to the separate controller WordPress root, and `NTLC_CONTROLLER_REPO` to the standalone source checkout. Run `php -c <test-ini> tests/licensing-client.php`. The subprocess bridge invokes actual controller REST/database code; it does not prove live HTTPS/TLS. See the controller repository for its standalone setup and migration tests.

Build with `python tools/package.py`. Output: `.release/extraction/bike-rental-plugin-0.9.0.zip`. The builder checks that the old server source folder is absent and packages only `bike-rental-plugin/`, comparing every ZIP entry to its source bytes. Compare its manifest to the prior 0.9.0 release to verify no client version bump is necessary. Deployment remains `/wp-content/plugins/bike-rental-plugin/` on rental sites; redeployment solely for extraction is unnecessary because client bytes are unchanged.

Manual acceptance after authorized server upgrade: Activate/Check/Deactivate from the existing client over real HTTPS; pause and resume its product, check slot counts, cron and bounded grace, and confirm booking/admin behavior under the existing enforcement setting. Live sites were not changed. Stripe/private updates remain unimplemented. This work stops at extraction/refactor; neither repository is pushed.
