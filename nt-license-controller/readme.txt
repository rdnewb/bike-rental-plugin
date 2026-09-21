=== NT License Controller ===
Requires at least: 6.6
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPL-2.0-or-later
Text Domain: nt-license-controller

Independent product licensing for NewByte Technologies.

== Description ==

Manage manually issued lifetime, monthly and annual licenses under NT Licenses.
Requires HTTPS and InnoDB. Does not require WooCommerce or Bike Rental Plugin.
Administrators (manage_options) can create keys, change status/expiration/limits,
inspect installations, deactivate them and review bounded audit history.
Keys are shown ONCE on creation; only SHA-256 hashes and masked suffixes are stored.
Keep the generated key securely. Lost keys cannot be recovered; issue a replacement
and revoke the old license. Product, plan and key are immutable after creation.

POST /wp-json/nt-license/v1/activate, /validate and /deactivate authenticate with
the license key and a random installation ID over HTTPS. No global shared secret.
Default limits: 120 requests per direct IP and 60 per key per UTC hour.
An activation limit of 0 means unlimited. A reduced limit does not evict existing
installations; deactivate selected installations explicitly to free slots.
Events older than 180 days are pruned daily. License/activation history is retained.

== Installation ==

Install this folder as wp-content/plugins/nt-license-controller/ on the controller
site (intended: newbytechnologies.com), separately from the licensed rental sites.
Activate, verify database permissions/InnoDB, HTTPS and REST routing, then create
a test license with product slug bike-rental-plugin. Confirm an end-to-end client
activation before enabling enforcement in the licensed plugin. Configure trusted
proxy HTTPS and REMOTE_ADDR handling at server level if applicable.

== Privacy ==

Stores license purchaser details entered by the controller administrator, licensed
site URL, installation ID and software versions. Does not collect rental customers,
orders, reservations or waivers. API responses omit purchaser/admin metadata.
Request credentials must be excluded from infrastructure request-body logging.

== Uninstall ==

Deactivation/uninstall remove scheduled cleanup only. Tables and options remain.
No automatic key revocation or data deletion. Back up before manual removal.

== Limitations ==

Phase 1: manual issuing and expiration maintenance only. No Stripe, renewals,
webhooks or private update delivery. Single-site installation is the tested scope.
See repository docs/licensing-architecture.md and docs/license-controller-api.md.

== Changelog ==

= 0.1.0 =
Initial controller schema 1, administration, HTTPS REST API, activation tracking,
atomic limits, key hashing, rate limiting and bounded audit events.
