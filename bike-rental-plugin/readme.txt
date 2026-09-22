=== Bike Rental Plugin ===
Requires at least: 6.6
Requires PHP: 8.3
Stable tag: 0.9.2
License: GPL-2.0-or-later
Text Domain: bike-rental-plugin

Reusable bicycle-rental settings, packages, reservations, and shared fleet availability.

== Description ==

Version 0.9.2 defaults new-booking license enforcement ON. Only the
BRP_LICENSE_ENFORCE wp-config.php constant overrides it; the former filter and
development bypass no longer apply. The License page omits the controller URL
and transmission description. Existing records, administration and grace remain intact.

Version 0.9.1 clearly shows License Active after activation, with a disabled masked
key field. Check License Now and Deactivate use the stored key automatically.
Grace and inactive states remain distinct; original-key recovery is collapsed
while active. No changes to licensing protocol, enforcement or schema 2.

Version 0.9.0 adds the administrator-only License tab, encrypted key storage,
activation/deactivation, manual/daily checks and seven-day bounded outage grace.
The initial 0.9.0 compatibility default is superseded by 0.9.2 enforcement. PHP sodium
is required for key storage. No new booking/page-load remote checks. Existing
reservations, returns, orders and waiver evidence remain accessible when invalid.
No Stripe billing, renewals or private update delivery. Rental schema remains 2.
See repository docs/license-client-setup.md and licensing-phase1-verification.md.

Version 0.8.2 added configurable product-card text color and retains pre-checkout rider collection and generic waiver management.
Waivers default to No. Configure the Waivers settings tab and dedicated mapped form
before enabling. Full Payment remains selected; deposit architecture is unchanged.
Paid bookings with required waivers enter Pending Waivers and retain inventory until
all individual adult/guardian waivers complete. One rider per bike; adults are 18+.
Minors require guardian name, email and relationship, with one waiver per minor.
Customers enter validated riders on the temporary hold before checkout. Waiver requests
and invitations begin only after verified payment. Publish [bike_rental_waiver] on a
page selected as Waiver Signing Page. Configure adult/guardian email templates in Waivers.
Cancelled/expired records with no protected order/waiver/audit evidence may be individually
deleted after admin confirmation. Thirty-day abandoned unlinked rider cleanup retains
reservation audit metadata and all protected evidence. Schema remains 2.
Secure invitations, admin resend/exempt actions, legal text/version retention, and
customer/admin/calendar progress are included. Schema 2 adds InnoDB riders and waivers
without changing existing reservation policies or duplicating signature blobs.
Real WPForms signing/mail delivery and Square test-site validation remain required.
See repository docs/waiver-architecture.md, wpforms-waiver-provider.md and waiver-verification.md.

Earlier release behavior (superseded where noted above):

Version 0.7.3 refines public rental cards: full-width centered 4:3 featured-image crops,
safe WooCommerce short descriptions between title and price, natural description height,
and no automatic standalone duration line. Edit Product short description in WooCommerce;
the long description is not duplicated. Empty excerpts omit the description area. Images
retain responsive thumbnail sizes and alt text; rounded corners and missing-image fallbacks
remain. Branding, deep links, booking, payments, deposits, availability and calendar logic
are unchanged. Schema remains 1; waiver integration has not started.

Version 0.7.2 organizes Bike Rentals > Settings into native General (default) and Booking
Form Branding tabs. General includes operational settings and dependency information.
Each tab saves only its own settings, preserves the other tab, and returns to the same
tab with WordPress notices. Direct links use admin.php?page=brp-settings&tab=branding
or tab=general. Invalid tabs fall back to General. No data migration; schema remains 1.

Booking Form Branding includes the existing 0.7.1 controls: heading, safe intro,
card/change button labels, seven color pickers (including Product Card Text Color), radius presets, Media Library logo and
administrator-only scoped CSS. Blank colors retain current appearance. Typography uses
theme font families; no fonts or font controls are added. Save and refresh to preview.
Branding uses the existing settings option and nonce/capability checks. Custom CSS supports
restricted flat .brp-booking rules only, rewritten to the specific shortcode instance.
No global selectors, imports, URLs, scripts or arbitrary stylesheets. Choose accessible
color combinations; contrast is not automatically corrected. Restore defaults resets
branding only. Booking, payments, deposits, availability and calendar behavior are unchanged.

Milestone 7A adds dedicated booking-page links such as /reserve/?rental=3-day-rental.
Use the WooCommerce product slug; numeric product ID fallback is supported. Valid links
show one selected card and booking controls. Change Rental reveals the full grid without
reloading. Invalid/inactive links show normal selection. Use one shortcode on the booking
page; ordinary Divi button links need no JavaScript. Keep its plain page URL canonical.
Bike Rentals > Calendar provides a read-only weekly pooled-inventory timeline, status
filters, occupied-time bars, quantity blocks, and daily peak totals from the existing
availability engine. WordPress timezone/DST and configured week start are respected.
Totals include all current claims regardless of filters; historical completed/cancelled/
expired rows remain visible but do not allocate. No payment, deposit or waiver changes.

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
Schema version 2 verifies four prefixed InnoDB tables during normal initialization.
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

= 0.9.2 =
- Default new-booking license enforcement ON, overridden only by BRP_LICENSE_ENFORCE in wp-config.php. Remove the filter and environment/development bypass.
- Hide the controller URL and daily-transmission description on the License page.
- Preserve bounded seven-day grace, existing reservation/order/waiver access and schema 2.


= 0.9.1 =
Clear active-license confirmation and disabled masked key field. Stored-key checks
and deactivation remain available; recovery entry is collapsed while active.

= 0.9.0 =
Licensing Phase 1: independent NT License Controller 0.1.0 and cached client
activation. Administrator-only License tab; protected encrypted storage; daily
validation and seven-day bounded grace. Internal opt-in new-booking enforcement.
Existing rental/payment/waiver workflows preserved; rental schema unchanged at 2.

= 0.8.2 =
* Add Product Card Text Color to Booking Form Branding and public shortcode cards.
* Blank preserves existing colors; button text retains its separate setting.
* Schema and booking/payment/waiver logic unchanged.

= 0.8.1 =
* Persist a validated adult/guardian roster atomically with public holds before checkout.
* Activate waiver requests after payment; add generic plain-text invitation templates.
* Add configured [bike_rental_waiver] signing page and runtime population diagnostics.
* Verify rendered mapped values and stored provider entries; keep tokens out of source URL metadata.
* Add controlled cancelled/expired deletion and bounded abandoned-rider retention cleanup.
* Keep schema 2, Full Payment totals, Square and deposit architecture unchanged.
* Real Elite/Signature, mail and Square acceptance remains pending.

= 0.8.0 =
* Add optional generic rider waivers, per-rider adult/guardian invitations and WPForms adapter.
* Preserve paid inventory as Pending Waivers until verified payment and all waivers complete.
* Add schema 2 riders/waivers, hashed expiring links, audited exemptions and progress displays.
* Preserve legacy reservation policies, Square processing, financial totals and deposit guards.
* Add automated integration/browser coverage; live provider/email validation remains pending.

= 0.7.3 =
* Show safe, server-rendered short descriptions at natural height between title and price.
* Remove standalone card duration labels without changing package metadata or scheduling.
* Fill card image width using centered 4:3 cover crops, preserving responsive images,
  branding radius, missing-image fallback, accessibility and existing selection behavior.

= 0.7.2 =
* Split settings into native General and Booking Form Branding tabs with direct links.
* Preserve the other tab's values on save or branding reset using the existing option,
  validation, capabilities and Settings API nonce/return URL. No public runtime changes.

= 0.7.1 =
* Add configurable booking content, labels, colors, radius presets and responsive media logo.
* Use wrapper CSS variables and safe administrator-only per-instance CSS overrides.
* Retain theme fonts, existing default appearance and all booking/calendar/checkout behavior.
* Add branding validation, browser checks and documentation. Schema remains 1; no waiver work.

= 0.7.0 =
* Add validated rental slug/ID deep links, selected-card display, Change Rental and URL state.
* Add weekly admin reservation/quantity-block timeline, filters and shared-engine peak totals.
* Read linked customer data through Woo CRUD; retain schema 1 and all payment/deposit behavior.
* Add automated verification and manual dedicated-site checklists. Waivers remain deferred.

= 0.6.2 =
* Release matching temporary holds on rental cart removal, cart emptying, and cart restoration/reset, using locked cancellation and retaining audit records.
* Clear matching rental session pointers and show a fresh booking grid after cancellation, including browser Back/Forward cache recovery.
* Preserve finalized reservations, payment/authorization states, unrelated cart/customer data, and normal timeout for navigation away. Schema remains 1; deposit behavior is unchanged.

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
