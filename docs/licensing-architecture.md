# Bike Rental licensing client

The authoritative controller has moved to [rdnewb/nt-license-controller](https://github.com/rdnewb/nt-license-controller). This repository contains **only the client**, with product ID `bike-rental-plugin`. No server namespace, database tables, admin code or server bootstrap is loaded by the rental runtime.

`src/License.php` handles encrypted non-autoload key storage, random installation identity, activation/deactivation, manual/daily validation, local entitlement cache and new-booking guard. `src/LicenseAdmin.php` provides the administrator-only License tab. All packaged files remain unchanged in this extraction; client version is **0.9.0**, rental schema **2**.

Default base URL remains `https://newbytechnologies.com/wp-json/nt-license/v1`; HTTPS constant `BRP_LICENSE_CONTROLLER_URL` overrides it. Requests send the original seven fields: key, product, installation, site URL, plugin version, WordPress version and PHP version. The standalone controller supports those fields and the same 12-field response. Server Products inactivity returns the already-supported `license_suspended` denial. See its [API documentation](https://github.com/rdnewb/nt-license-controller/blob/main/docs/api.md).

Frontend/reservation operations read cache, without a controller call per request. Activation/manual checks/daily cron contact the server. Grace lasts at most seven days from last confirmed validity, capped by known expiry; repeated outages do not slide the deadline. Definite invalidity immediately clears grace. Offline deactivation clears local entitlement and retries remotely. The original key may be re-entered after WordPress salt rotation.

Compatibility mode remains default; `BRP_LICENSE_ENFORCE` or the existing filter enables new-booking enforcement internally. Existing rentals/returns/orders/waivers stay accessible under their usual permissions. `BRP_LICENSE_DEV_MODE` is honored only in local/development environments. No billing, Stripe calls, renewals or private updates are implemented.

Client tests use the separate checkout's disposable worker through `NTLC_CONTROLLER_REPO`, solely for integration testing. The production client has no filesystem dependency on the controller repository. See [setup](license-client-setup.md) and [extraction verification](licensing-phase1-verification.md).
