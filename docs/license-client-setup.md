# Licensing deployment and client setup

Phase 1 packages are separate. No remote installation or enforcement change was performed during development.

1. Back up both sites. Verify PHP 8.3+, WordPress 6.6+, InnoDB, database schema permissions, HTTPS and working WP-Cron. The rental site's PHP must provide sodium authenticated encryption. Confirm correct site timezone and synchronized clocks.
2. Upload **NT License Controller 0.1.0** only to the licensing site, intended `newbytechnologies.com`, at **`/wp-content/plugins/nt-license-controller/`**, relative to that site's WordPress installation root. Activate it. Verify the three `ntlc_` tables and NT Licenses admin pages. Configure trusted proxy HTTPS/IP handling if needed; REST must reach WordPress directly without login challenges, redirects or HTML bot pages.
3. Under **NT Licenses > Add License**, create product `bike-rental-plugin`, select Lifetime/Monthly/Annual, status Active, a positive limit or 0 for unlimited, and a local expiration for monthly/annual. Copy the generated key immediately to a secure location; it is displayed once. The controller cannot recover it later.
4. Upload **Bike Rental Plugin 0.9.0** to the customer/test site's **`/wp-content/plugins/bike-rental-plugin/`**. This replaces the existing plugin directory's files. Do not upload the controller there or upload the Git repository as a plugin. Rental schema stays 2 and settings/data remain intact.
5. Open **Bike Rentals > Settings > License** as an administrator. Confirm **Compatibility mode**. Enter the key and click **Activate License**. Confirm active/valid, plan, expiration, used/limit, connection, last validation and next validation. Verify the controller lists this site and installation. Use **Check License Now** and confirm the record's last check advances.
6. Complete the deployment acceptance checklist in [verification](licensing-phase1-verification.md) on test installations, including suspension, expiry, outage, deactivation and booking behavior. No enforcement is enabled automatically during deployment.
7. Only after acceptance, explicitly enable production enforcement in the rental site's `wp-config.php`, above the stop-editing line:

```php
define( 'BRP_LICENSE_ENFORCE', true );
```

The trusted PHP filter `brp_license_enforcement_enabled` offers an equivalent integration seam. There is no UI bypass setting. Roll back enforcement by removing the constant or setting it false; existing license state is retained. This is a deliberate rollout control, not part of the purchaser workflow.

For a different HTTPS test controller, set the full namespace base before loading WordPress:

```php
define( 'BRP_LICENSE_CONTROLLER_URL', 'https://license-test.example.com/wp-json/nt-license/v1' );
```

Deactivation on the old controller should precede changing that endpoint/key. For an explicit local/development exception only, `BRP_LICENSE_DEV_MODE=true` disables enforcement when `WP_ENVIRONMENT_TYPE` is `local` or `development`. It has no effect in `staging` or `production`. Development bypass does not bypass HTTPS for actual licensing calls.

## Operating instructions

- **Deactivate License** clears local entitlement and releases the remote slot. The encrypted key stays stored; leave the input empty to reuse it. If offline, the tab shows a pending deactivation and cron retries. Manual Check License Now will not reverse that pending request.
- For a different key, complete deactivation first. A lost encrypted key after WordPress salt rotation can be recovered by re-entering the original key and activating. If the original key is lost, use controller administration to deactivate/revoke it and explicitly reset the local license option before entering its replacement; no automatic recovery displays secrets.
- For a URL move, deactivate the old site before moving, or deactivate its row on the controller. Update WordPress home URL and activate again. `www`, subdirectories and distinct ports are separate sites. A copied database at a new URL cannot reuse cached entitlement. For a clone, remove its `brp_license` and `brp_license_installation` options through trusted maintenance tooling before licensing it separately; never reset the source site's identity unintentionally.
- Daily WP-Cron is traffic-driven. Configure a server scheduler for reliable execution on low-traffic sites. A recent manual check does not suppress the next daily event. Next Validation is a target; delays do not extend the fixed grace deadline.
- An unavailable controller gives a previously valid license up to seven days **from its last successful check**, capped by known expiration. The admin warning and deadline show the condition. Successful contact resets this deadline; repeated failures do not. Definite suspension/revocation/expiry disables new booking immediately on receipt when enforcement is on.
- Existing rentals, checkout for existing holds, payments, orders and waiver evidence remain operational under their usual permissions. No data is deleted by licensing. Full Payment, Square and existing deposit guards are unchanged.
- Controller **View/Edit** changes status, expiration, activation limit and purchaser metadata. To extend a term, change the expiration and set Active. Suspended/revoked records still consume their active slots until deactivated. Reducing the limit does not choose sites to evict automatically.
- Normal deactivation/uninstall preserves all records and options. To free a slot, explicitly deactivate the license before uninstalling or deactivate its activation from controller admin afterward.

Neither deployment folder is a server account's absolute filesystem path: prepend each site's actual document root supplied by the host. No SFTP credentials or server-specific document roots are stored in the repository.
