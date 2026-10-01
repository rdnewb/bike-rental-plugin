=== Bike Rental Plugin ===
Requires at least: 6.6
Requires PHP: 8.3
Stable tag: 1.1.3
License: GPL-2.0-or-later
Text Domain: bike-rental-plugin

Bicycle rental booking, shared fleet availability, reservation management, and rider waivers.

== Description ==

Version 1.1.3 extends friendly dates to checkout receipts, Woo cart/customer
order displays and customer emails while preserving staff timing and stored data.

The booking form presents a customer-focused Review Your Reservation section with
rental name, friendly dates, quantity, rider count and rental location. Internal
times/timezone are omitted from that review; scheduling and order data are retained.

The location form removes customer country selection for rental locations and derives
the country from the WooCommerce store base. State/postal validation follows that
country; existing stored addresses and Woo billing/shipping remain unchanged.

The booking flow uses each start weekday's configured opening time automatically.
General settings offer a Drop Off / Pick Up Time Disclaimer shown after date selection.
The published WooCommerce Refund & Returns Policy appears above Reserve Bikes.
Rental location is separate from Woo billing/shipping and appears for staff and
relevant customers on orders, confirmations and order emails. Schema remains 2.

Offer active WooCommerce rental packages through [bike_rental_booking]. Customers
choose a start date and quantity, provide a separate Drop Off / Pick Up Location
and rider information, and continue from a temporary
inventory hold to Full Payment checkout through WooCommerce Square.

Manage existing reservations, revision-safe edits, fleet capacity, blocks, a weekly
calendar, order links, rider rosters, waiver progress, and eligible record deletion.
New reservations are created through the public booking flow, including staff-assisted
bookings. Package and pricing snapshots remain stored for booking integrity.

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

Normal initialization verifies schema 2. Version 1.0.3 requires no schema change.
Deactivation, uninstall, and complete-folder updates preserve stored data.

== Frequently Asked Questions ==

= Where do I upload updates? =
Replace wp-content/plugins/bike-rental-plugin/ relative to your WordPress root during
a maintenance window. Do not upload repository tests, tooling, or NT License Controller.

= Can staff create reservations? =
Yes. Use the public booking page to collect riders, checkout payment, and waivers
consistently. Reservations administration manages existing bookings only.

= Are raw reservation snapshots displayed? =
No. They remain stored internally; staff see operational reservation details.

= How do I block unavailable bikes? =
Use Availability > Add Availability Block. Fleet manages total bike capacity. Enter
start/end dates, AM/PM times, quantity, and reason. Time choices follow Booking Time
Increment in the WordPress timezone. The same controls edit existing blocks.

All Day includes every selected date: December 24 through 26 ends at midnight on
December 27. Equal dates block one day. Times are disabled for All Day. DST follows
local calendar boundaries. Timed blocks can span multiple dates; end must follow start.
No end date preserves indefinite blocks until disabled. Quantity cannot exceed fleet
capacity or available inventory. Lists and Calendar show All Day or AM/PM ranges.

== Changelog ==

= 1.1.3 =
Replace customer-facing operational times with friendly rental dates in receipts,
Woo cart/order displays and emails. Retain precise timing for staff and storage.
Reuse the existing receipt date formatter and keep one disclaimer visible.
Waiver email date placeholders use dates only. Schema remains 2.

= 1.1.2 =
Simplify the public review with friendly reservation details and live location
preview. Remove internal review times/timezone and Temporary Reservation wording
from public booking labels/messages. Schema 2 and booking logic unchanged.

= 1.1.1 =
Remove the rental-location country control. Derive the store base country on the
server, retaining country-aware state/postal validation and structured storage.
Schema remains 2; billing, shipping and existing reservation addresses unchanged.

= 1.1.0 =
Separate rental-service location, date-only booking at configured opening time,
prominent configurable delivery/pickup disclaimer, and safely rendered Woo refund
policy above Reserve Bikes. Preserve billing, payments, waivers, inventory and
licensing. Schema 2 unchanged.

= 1.0.3 =
Move Bike Rentals to explicit admin menu position 56, near WooCommerce.
Submenus, permissions, icon and schema 2 are unchanged.

= 1.0.2 =
Fleet now links to dedicated Availability block management. Removed the separate
admin availability checker. Allocation and business services unchanged; schema 2.

= 1.0.1 =
* Remove direct admin reservation creation; staff use public booking.
* Add shared date-only fields, AM/PM time choices, and inclusive All Day blocks.
* Preserve block editing, indefinite blocks, locking, capacity, and schema 2.


= 1.0.0 =
* Prepare the production interface with clear booking and administration wording.
* Remove raw snapshot/session metadata and the raw waiver diagnostics panel.
* Retain useful availability checks and recovery tools.
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
