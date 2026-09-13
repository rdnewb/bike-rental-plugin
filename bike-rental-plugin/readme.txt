=== Bike Rental Plugin ===
Requires at least: 6.6
Requires PHP: 8.3
Stable tag: 0.3.0
License: GPL-2.0-or-later
Text Domain: bike-rental-plugin

Reusable bicycle-rental settings, packages, fleet records, and reservation storage.

== Description ==

Milestone 3 adds Bike Rentals > Fleet and Bike Rentals > Reservations admin test tools.
Configure shared fleet quantity, add/edit/disable unavailability blocks, and create
manual test reservations with historical package snapshots and revision checks.
Schema version 1 creates exactly two prefixed InnoDB tables during normal initialization.
Existing settings, rental packages, timezone visibility, and dependency detection remain.

There is no public rental booking, overlap calculation, checkout, payment, or waiver processing.
Manual test records do not establish availability or fulfillment readiness.
Available dependencies have NOT been integration tested by this plugin.
WooCommerce is required for package management and new reservations, not foundation activation.
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

No server-side build tools, Composer, Node.js, WP-CLI, or SSH are required.
Settings, package metadata, fleet, blocks, and reservations are retained on updates,
deactivation, and uninstall. No automatic table deletion is performed.

== Changelog ==

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
