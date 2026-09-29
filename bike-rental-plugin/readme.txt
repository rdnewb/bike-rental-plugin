=== Bike Rental Plugin ===
Requires at least: 6.6
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPL-2.0-or-later
Text Domain: bike-rental-plugin

Bicycle rental booking, shared fleet availability, reservation management, and rider waivers.

== Description ==

Offer active WooCommerce rental packages through [bike_rental_booking]. Customers
choose dates and quantity, provide rider information, and continue from a temporary
inventory hold to Full Payment checkout through WooCommerce Square.

Manage real manual reservations, revision-safe edits, fleet capacity, blocks, a weekly
calendar, order links, rider rosters, waiver progress, and eligible record deletion.
Manual reservations require staff-entered dates and inventory validation; they do not
collect payment. Package and pricing snapshots remain stored for booking integrity.

Settings include General, Booking Form Branding, Waivers, and License. Branding supports
text, colors, logos, and scoped CSS. Direct package links use ?rental=product-slug on
the booking page, with Change Rental returning to the package grid.

Optional rider waivers use a configured WPForms Elite + Signature form. Publish
[bike_rental_waiver] on the selected signing page. Paid reservations retain inventory
while required adult/guardian waivers are pending. Staff can resend invitations and
review completion. Protected waiver evidence is retained.

A valid license is required for new bookings. Activation, deactivation, manual/daily
validation, encrypted key storage, and bounded outage grace are supported. Existing
records remain accessible. NT License Controller is separate and is not included.
Deposit checkout requires a compatible provider and is otherwise blocked.

== Installation ==

1. Back up the WordPress site and database.
2. Upload this complete directory to wp-content/plugins/bike-rental-plugin/.
3. Activate Bike Rental Plugin and WooCommerce.
4. Configure a named-city timezone, business hours, and fleet capacity. Days start closed.
5. Configure active rental products with valid prices and durations.
6. Publish [bike_rental_booking], configure Full Payment and Square, and activate the license.
7. Configure the signing page, provider, field mappings, and invitation templates if required.
8. Verify booking, payment, email delivery, signing, and staff workflows before accepting bookings.

Normal initialization verifies schema 2. Version 1.0.0 requires no schema change.
Deactivation, uninstall, and complete-folder updates preserve stored data.

== Frequently Asked Questions ==

= Where do I upload updates? =
Replace wp-content/plugins/bike-rental-plugin/ relative to your WordPress root during
a maintenance window. Do not upload repository tests, tooling, or NT License Controller.

= Can staff create reservations? =
Yes. Use Reservations > Create Reservation with an active package and agreed dates.
Availability, date/status validation, and inventory locking apply. Review payment and
waiver readiness before releasing bikes.

= Are raw reservation snapshots displayed? =
No. They remain stored internally; staff see operational reservation details.

== Changelog ==

= 1.0.0 =
* Prepare the production interface with clear booking and administration wording.
* Remove raw snapshot/session metadata and the raw waiver diagnostics panel.
* Retain validated manual reservations, useful availability checks, and recovery tools.
* Preserve schema 2, payment processing, waivers, licensing, and existing records.

= 0.9.2 =
* Require a valid license for new bookings while retaining access to existing records.

= 0.9.1 =
* Clarify activation state, masked key display, and original-key recovery.

= 0.9.0 =
* Add license activation, validation, deactivation, and bounded outage grace.

= 0.8.2 =
* Add Product Card Text Color to Booking Form Branding.

= 0.8.1 =
* Collect riders before checkout and add signing-page configuration and protected cleanup.

Earlier release details are maintained in the source repository changelog.
