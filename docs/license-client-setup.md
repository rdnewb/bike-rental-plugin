# Licensing deployment and client setup

The controller is maintained in [rdnewb/nt-license-controller](https://github.com/rdnewb/nt-license-controller). This repository and its ZIP contain only the rental client. Version 0.9.2 enables new-booking license enforcement by default and removes controller details from the License page. The protocol and rental schema remain unchanged.

1. Back up both sites. Verify PHP 8.3+, WordPress 6.6+, InnoDB, database schema permissions, HTTPS and working WP-Cron. The rental site's PHP must provide sodium authenticated encryption. Confirm correct site timezone and synchronized clocks.
2. Deploy the separately maintained **NT License Controller 0.2.0** using its [migration/deployment guide](https://github.com/rdnewb/nt-license-controller/blob/main/docs/migration-from-bike-rental-repo.md). It remains on `newbytechnologies.com` at `/wp-content/plugins/nt-license-controller/`. Its new Products registry must have `bike-rental-plugin` Active. Server installation, tables and API documentation belong to that repository.
3. Under **NT Licenses > Add License**, create product `bike-rental-plugin`, select Lifetime/Monthly/Annual, status Active, a positive limit or 0 for unlimited, and a local expiration for monthly/annual. Copy the generated key immediately to a secure location; it is displayed once. The controller cannot recover it later.
4. Upload **Bike Rental Plugin 0.9.2** to the customer/test site's **`/wp-content/plugins/bike-rental-plugin/`**. This replaces the existing plugin directory's files. Do not upload the controller there or upload the Git repository as a plugin. Rental schema stays 2 and settings/data remain intact.
5. Open **Bike Rentals > Settings > License** as an administrator. Confirm **Production enforcement is enabled for new reservations**. Enter the key and click **Activate License**. Confirm active/valid, plan, expiration, used/limit, connection, last validation and next validation. Verify the controller lists this site and installation. Use **Check License Now** and confirm the record's last check advances.
6. Complete the deployment acceptance checklist in [verification](licensing-phase1-verification.md) on test installations, including suspension, expiry, outage, deactivation and booking behavior. Enforcement is on automatically; activate the license before accepting new bookings. Existing activated installations retain their cached entitlement.
7. No configuration is required for normal enforcement. For an intentional development/maintenance override only, set this boolean in the rental site's `wp-config.php`, above the stop-editing line:

```php
define( 'BRP_LICENSE_ENFORCE', false );
```

Removing the constant or setting it to boolean true restores enforcement. Use boolean values, not quoted strings. This constant is the only override; there is no UI option or filter bypass. Existing license state is retained. Before upgrading, review any existing false override because it continues to disable enforcement.

For a different HTTPS test controller, set the full namespace base before loading WordPress:

```php
define( 'BRP_LICENSE_CONTROLLER_URL', 'https://license-test.example.com/wp-json/nt-license/v1' );
```

Deactivation on the old controller should precede changing that endpoint/key. The former `BRP_LICENSE_DEV_MODE` and `brp_license_enforcement_enabled` controls are ignored in every environment. The explicit enforcement constant does not bypass HTTPS requirements for licensing calls.

## Operating instructions

When activated, the tab shows **License Active** and a disabled masked **Saved License Key** field. No re-entry or further activation is needed. Check License Now and Deactivate License use the stored key. If a check asks for the original key after a site security/salt change, expand **Re-enter the stored license key** and use Restore License Key. Grace has a warning indicator; inactive or invalid states never display the normal Active confirmation.

- **Deactivate License** clears local entitlement and releases the remote slot. The encrypted key stays stored; leave the input empty to reuse it. If offline, the tab shows a pending deactivation and cron retries. Manual Check License Now will not reverse that pending request.
- For a different key, complete deactivation first. A lost encrypted key after WordPress salt rotation can be recovered by re-entering the original key and activating. If the original key is lost, use controller administration to deactivate/revoke it and explicitly reset the local license option before entering its replacement; no automatic recovery displays secrets.
- For a URL move, deactivate the old site before moving, or deactivate its row on the controller. Update WordPress home URL and activate again. `www`, subdirectories and distinct ports are separate sites. A copied database at a new URL cannot reuse cached entitlement. For a clone, remove its `brp_license` and `brp_license_installation` options through trusted maintenance tooling before licensing it separately; never reset the source site's identity unintentionally.
- Daily WP-Cron is traffic-driven. Configure a server scheduler for reliable execution on low-traffic sites. A recent manual check does not suppress the next daily event. Next Validation is a target; delays do not extend the fixed grace deadline.
- An unavailable controller gives a previously valid license up to seven days **from its last successful check**, capped by known expiration. The admin warning and deadline show the condition. Successful contact resets this deadline; repeated failures do not. Definite suspension/revocation/expiry disables new booking immediately on receipt when enforcement is on.
- Existing rentals, checkout for existing holds, payments, orders and waiver evidence remain operational under their usual permissions. No data is deleted by licensing. Full Payment, Square and existing deposit guards are unchanged.
- Controller **View/Edit** changes status, expiration, activation limit and purchaser metadata. To extend a term, change the expiration and set Active. Suspended/revoked records still consume their active slots until deactivated. Reducing the limit does not choose sites to evict automatically.
- Normal deactivation/uninstall preserves all records and options. To free a slot, explicitly deactivate the license before uninstalling or deactivate its activation from controller admin afterward.

Neither deployment folder is a server account's absolute filesystem path: prepend each site's actual document root supplied by the host. No SFTP credentials or server-specific document roots are stored in the repository.

## 0.9.2 verification

442 local checks passed: 12 isolated enforcement configuration cases, 56 client/controller integration checks, 209 package/foundation checks, 90 settings-tab checks and 75 public-booking checks. Configuration cases cover default/true/false across local, development, staging and production with legacy bypass controls present. Licensing integration uses the normal default and verifies grace, definitive denial, existing-rental completion and the simplified License page. Other disposable rental suites explicitly disable licensing in their test bootstrap; this override is not packaged.

All 65 runtime/test PHP files passed syntax checks. The rendered active License page was checked in local Chrome; the masked field, recovery disclosure and enforcement message remain usable, with both requested details absent. The release ZIP contains 49 byte-verified client files. No live deployment or live outage simulation was performed.

After upload, confirm the License page says enforcement is enabled, the existing key remains active, both removed details are absent, and a valid-license booking reaches checkout. On a test installation, check that suspension blocks new bookings with generic public wording while existing reservation management remains accessible. Restore the test license and verify booking resumes.
