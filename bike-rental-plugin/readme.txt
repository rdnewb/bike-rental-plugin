=== Bike Rental Plugin ===
Requires at least: 6.6
Requires PHP: 8.3
Stable tag: 0.6.1
License: GPL-2.0-or-later
Text Domain: bike-rental-plugin

Reusable bicycle-rental settings, packages, reservations, and shared fleet availability.

== Description ==

Milestone 4 adds authoritative shared fleet availability and double-booking protection.
Milestone 5 adds [bike_rental_booking] for public package/date/time/quantity selection,
calculated pickup, current availability, and protected guest holds with expiry display.
Calendar-day rentals end on start date + (days - 1), at the configured local pickup time.
Only the start day must be open for a calendar-day rental. Intermediate and final days
may be closed for new starts; business pickup is independent of delivery/start hours.
Any weekday and valid configured duration use the same calculation; one-day pickup must
be after start. Detailed diagnostics are restricted to authorized PHP callers.
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
Active rentals use their scheduled occupied interval until its end has passed; only then
do they block indefinitely until completion. Activation before the rental start is rejected.
Completed means ended or actually returned; an Active rental may be returned early.
Actual-return turnaround uses dated blocks.
Bike Rentals > Availability test displays capacity, peak usage, available quantity, and fit.

Milestone 6A transfers the existing hold to WooCommerce Checkout Block for Full Payment
through WooCommerce Square. Payment mode defaults to Full Payment. Deposit can be selected
but blocks new rental checkout until a compatible provider is selected. No deposit plugin
is required; deactivate unsupported deposit extensions before Full Payment checkout.
Quantity is locked, direct rental add-to-cart is blocked, and rental coupons/mixed carts
are unsupported. WooCommerce owns price, tax, order, address, email, and payment records.
Payment submission extends the hold only to original creation plus 30 minutes. Refresh
does not extend it. Verified captured payment confirms the same linked reservation.
Late payment rechecks capacity; conflicts need staff resolution. Refunds and Woo Completed
do not automatically cancel, release, or complete a rental. Payment mode is snapshotted.
Reconciliation checks up to 50 reservations and 50 Woo orders per five-minute pass.
Waiver and deposit processing are not implemented. Real Square sandbox acceptance remains
pending; local tests use real WooCommerce/Store API with simulated payment evidence.
WooCommerce is required for package management, new reservations, and full reservation editing,
not foundation activation.
Existing fleet and reservation data remain available without WooCommerce.

== Installation ==

1. Upload this complete bike-rental-plugin directory into wp-content/plugins/ via SFTP.
2. Activate Bike Rental Plugin in WordPress Plugins.
3. Open Bike Rentals > Settings and review/save the configuration.
4. All days initially remain closed. Configure open hours before public selection testing.
5. With WooCommerce active, create a Simple product and open Product data > Rental Settings.
6. Set the regular price using WooCommerce and the duration using Rental Settings.
7. Use Advanced > Menu order for display order. No packages are created automatically.
8. Open Fleet to set total quantity and manage blocks; the initial setup quantity is 10.
9. Open Reservations to test persistent records. Enter times in the WordPress timezone.
10. Resolve any database installation notice before using the test tools. SFTP updates
    trigger schema checks without requiring reactivation. InnoDB and named locks are required.
11. Use Availability test with occupied local intervals (including buffers) to verify fit.
12. Insert [bike_rental_booking] into a WordPress/Divi test page. Test mobile and keyboard use.
13. Public holds use selling prices, signed HttpOnly cookies, session-bound request tokens,
    and the existing inventory lock. JavaScript is required.
14. Public starts enforce hours, notice, increments, and horizon. Calendar-day packages use
    start + (N - 1) days at business pickup time, regardless of final-day delivery hours.
    Hourly endpoints must still fit operating hours. Occupied buffers protect inventory.

No server-side build tools, Composer, Node.js, WP-CLI, or SSH are required.
Settings, package metadata, fleet, blocks, and reservations are retained on updates,
deactivation, and uninstall. No automatic table deletion is performed.

== Changelog ==

= 0.6.1 =
* Responsive server-rendered rental cards replace the package dropdown, using WooCommerce images, short descriptions, formatted prices, duration, and optional promotions.
* Accessible keyboard selection reveals the existing booking controls. Scoped styles adapt to desktop, tablet, and mobile with image fallbacks and contained descriptions.
* No changes to payment, deposit, reservation, Square, or schema logic. Test-site/Divi browser acceptance remains pending.

= 0.6.0 =
* Add Full Payment checkout through WooCommerce Square and a guarded generic Deposit mode.
* Link one existing reservation to one primary Woo order, with historical payment mode.
* Enforce guest ownership, cart quantity, snapshots, bounded holds, and late-payment capacity.
* Add staff payment summaries, crosslinks, exceptions, and bounded reconciliation.
* Preserve rental status on financial refunds and Woo Completed. Support CPT and HPOS CRUD.
* Keep schema 1. Deposit processing and Milestone 7 waivers are not implemented.
* Square sandbox and dedicated-site deployment validation remain required.

= 0.5.4 =
* Show public start-time dropdown labels in 12-hour AM/PM format, retaining original booking values.
* Refresh the booking asset version. No scheduling or schema changes.

= 0.5.3 =
* Apply delivery/start-hour validation only to the start of calendar-day rentals.
* Allow business pickup on any final weekday, including closed delivery days and outside delivery hours.
* Retain generic start + (N - 1) arithmetic and the valid positive-duration requirement.
* Test durations 1-7 on every weekday plus longer packages, occupied buffers, holds, and start validation.
* Remove the obsolete calendar pickup-hours warning. Keep hourly rules, schema 1, and Milestone 6 deferred.

= 0.5.2 =
* Preserve scheduling rejection messages instead of reporting every empty time list as unavailable inventory.
* Add protected candidate diagnostics, including final-day pickup and buffer-only conflicts.
* Warn administrators about calendar pickup times outside operating hours; clarify inclusive dates and AM/PM.
* Verify real calendar package metadata, 3/5-day endpoints, full start-day candidates, closed intermediate days, timezone changes, and occupied buffers. Keep schema 1 and Milestone 6 deferred.

= 0.5.1 =
* Bound in-progress Active rentals to their occupied interval; extend only overdue rentals.
* Reject early activation across creation, admin edits, and status helpers.
* Validate completion timing while allowing actual early returns from Active.
* Preserve shared locks, revisions, return turnaround, and schema 1.

= 0.5.0 =
* Add public booking shortcode, scoped mobile layout, loading/error states, and hold summary.
* Add validated catalog/time/availability reads and protected guest session/hold/status REST routes.
* Calculate elapsed-hour and inclusive calendar-day endpoints in the WordPress timezone.
* Snapshot selling prices and retain request-key idempotency, expiry, and shared inventory locking.
* Keep schema 1. Checkout, orders, Square, deposits, waivers, and emails remain deferred.

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
