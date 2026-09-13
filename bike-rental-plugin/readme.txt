=== Bike Rental Plugin ===
Requires at least: 6.6
Requires PHP: 8.3
Stable tag: 0.4.0
License: GPL-2.0-or-later
Text Domain: bike-rental-plugin

Reusable bicycle-rental settings, packages, reservations, and shared fleet availability.

== Description ==

Milestone 4 adds authoritative shared fleet availability and double-booking protection.
Configure shared fleet quantity, add/edit/disable unavailability blocks, and create
manual test reservations with current package snapshots and revision checks.
Full reservation editing supports package, quantity, start/end, status,
and issue code. System identifiers/order relationships remain read-only. Package
changes capture current WooCommerce selling price; other edits retain agreed pricing.
Snapshots reflect the edited current state; prior revision history is not retained yet.
Schema version 1 creates exactly two prefixed InnoDB tables during normal initialization.
Existing settings, rental packages, timezone visibility, and dependency detection remain.
The shared sweep uses peak simultaneous usage, half-open occupied intervals, and buffers.
All inventory changes lock the same permanent InnoDB fleet capacity row before validation.
Holds expire after 15 minutes; duplicate request keys reuse their existing result.
Five-minute WP-Cron cleanup marks at most 100 expired holds per run. Availability ignores
expired timestamps even before cleanup. Deactivation removes the recurring schedule.
Active rentals remain allocated until completion; actual-return turnaround uses dated blocks.
Bike Rentals > Availability test displays capacity, peak usage, available quantity, and fit.

There is no public rental booking, checkout, payment, or waiver processing.
Manual test records enforce availability but do not establish fulfillment readiness.
Available dependencies have NOT been integration tested by this plugin.
WooCommerce is required for package management, new reservations, and full reservation editing,
not foundation activation.
Existing fleet and reservation data remain available without WooCommerce.

== Installation ==

1. Upload this complete bike-rental-plugin directory into wp-content/plugins/ via SFTP.
2. Activate Bike Rental Plugin in WordPress Plugins.
3. Open Bike Rentals > Settings and review/save the configuration.
4. All days initially remain closed. No public rental functionality is enabled.
5. With WooCommerce active, create a Simple product and open Product data > Rental Settings.
6. Set the regular price using WooCommerce and the duration using Rental Settings.
7. Use Advanced > Menu order for display order. No packages are created automatically.
8. Open Fleet to set total quantity and manage blocks; the initial setup quantity is 10.
9. Open Reservations to test persistent records. Enter times in the WordPress timezone.
10. Resolve any database installation notice before using the test tools. SFTP updates
    trigger schema checks without requiring reactivation. InnoDB and named locks are required.
11. Use Availability test with occupied local intervals (including buffers) to verify fit.

No server-side build tools, Composer, Node.js, WP-CLI, or SSH are required.
Settings, package metadata, fleet, blocks, and reservations are retained on updates,
deactivation, and uninstall. No automatic table deletion is performed.

== Changelog ==

= 0.4.0 =
* Enforce shared fleet peak availability on reservations, edits, blocks, and capacity changes.
* Serialize allocation with the InnoDB capacity-row lock; roll back failures and reject stale edits.
* Add 15-minute idempotent holds, safe confirmation, and bounded five-minute expiry cleanup.
* Count active rentals until completion and preserve actual-return turnaround through dated blocks.
* Add admin availability testing and real concurrent-process verification. Keep schema version 1.
* Public booking, checkout, Square, deposits, waivers, calendar, and emails remain deferred.

= 0.3.1 =
* Fix reservation detail editing for package, quantity, dates, any valid status, and issue code.
* Update current snapshots on actual edits while preserving protected identifiers and orders.
* Retain nonce/capability/revision checks, UTC storage, and schema version 1.
* Show development-only notice: availability conflict checking remains deferred.

= 0.3.0 =
* Add two-table reservation/fleet storage with verified schema version 1 installation.
* Add Fleet and Reservations administration, immutable snapshots, UTC times, and revisions.
* Preserve all previous settings/packages. Public availability and booking remain deferred.

= 0.2.0 =
* Add validated rental metadata on WooCommerce Simple products and a package reader.
* Add a read-only Packages overview and product-editor field visibility script.
* Preserve foundation settings and all standard WooCommerce commerce fields.

= 0.1.0 =
* Add plugin foundation, validated settings, capability checks, and status display.
