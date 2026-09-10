=== Bike Rental Plugin ===
Requires at least: 6.6
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPL-2.0-or-later
Text Domain: bike-rental-plugin

Reusable bicycle-rental configuration foundation for WordPress and WooCommerce.

== Description ==

Milestone 1 provides Bike Rentals > Settings, scheduling configuration,
weekly operating hours, timezone visibility, and dependency detection.

There is no public booking, inventory, package, payment, or waiver processing.
Available dependencies have NOT been integration tested by this plugin.
WooCommerce is needed for later rental-commerce features, not foundation activation.

== Installation ==

1. Upload this complete bike-rental-plugin directory into wp-content/plugins/ via SFTP.
2. Activate Bike Rental Plugin in WordPress Plugins.
3. Open Bike Rentals > Settings and review/save the configuration.
4. All days initially remain closed. No public rental functionality is enabled.

No server-side build tools, Composer, Node.js, WP-CLI, or SSH are required.
Settings are retained on updates, deactivation, and uninstall.

== Changelog ==

= 0.1.0 =
* Add plugin foundation, validated settings, capability checks, and status display.
