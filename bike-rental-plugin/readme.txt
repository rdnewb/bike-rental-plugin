=== Bike Rental Plugin ===
Requires at least: 6.6
Requires PHP: 8.3
Stable tag: 0.2.0
License: GPL-2.0-or-later
Text Domain: bike-rental-plugin

Reusable bicycle-rental settings and package management for WordPress and WooCommerce.

== Description ==

Milestone 2 adds Rental Settings to the standard WooCommerce Simple product editor
and a read-only Bike Rentals > Packages overview. Configure duration, promotional
text, and rental activation; WooCommerce owns the title, regular price, tax and ordering.
All foundation scheduling settings, timezone visibility, and dependency detection remain.

There is no public rental booking, inventory, reservation, payment, or waiver processing.
Available dependencies have NOT been integration tested by this plugin.
WooCommerce is required for package management, not foundation activation.

== Installation ==

1. Upload this complete bike-rental-plugin directory into wp-content/plugins/ via SFTP.
2. Activate Bike Rental Plugin in WordPress Plugins.
3. Open Bike Rentals > Settings and review/save the configuration.
4. All days initially remain closed. No public rental functionality is enabled.
5. With WooCommerce active, create a Simple product and open Product data > Rental Settings.
6. Set the regular price using WooCommerce and the duration using Rental Settings.
7. Use Advanced > Menu order for display order. No packages are created automatically.

No server-side build tools, Composer, Node.js, WP-CLI, or SSH are required.
Settings are retained on updates, deactivation, and uninstall.

== Changelog ==

= 0.2.0 =
* Add validated rental metadata on WooCommerce Simple products and a package reader.
* Add a read-only Packages overview and product-editor field visibility script.
* Preserve foundation settings and all standard WooCommerce commerce fields.

= 0.1.0 =
* Add plugin foundation, validated settings, capability checks, and status display.
