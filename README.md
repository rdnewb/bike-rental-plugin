# Bike Rental Plugin

A WordPress/WooCommerce bicycle rental plugin with shared fleet availability, public booking, staff reservation management, Full Payment checkout, rider waivers, and licensing.

**Runtime version: 1.0.3. Database schema: 2 (unchanged).**

## Booking and operations

- Publish `[bike_rental_booking]` on a dedicated page. Customers select an active rental package, dates, quantity, and one rider per bike before a temporary hold and checkout.
- Link directly to a package with `/reserve/?rental=product-slug`. Valid active packages appear selected; **Change Rental** returns to the grid. Invalid links use normal selection.
- Rental packages are WooCommerce Simple products configured under **Product data > Rental Settings**. Shared fleet capacity, operating hours, notice, booking horizon, preparation, and turnaround control availability.
- Hourly packages use elapsed time. Calendar-day packages end on start date + (duration - 1) at the configured pickup time. Intermediate and final days can be closed for new starts. End must follow start; ambiguous or nonexistent daylight-saving times are rejected.
- **Reservations** supports existing reservation lists, revision-safe edits, status changes, order links, rider rosters, waiver progress, and protected permanent deletion of eligible cancelled/expired records. New reservations, including staff-assisted bookings, must use the public booking page so riders, payment, and waivers are collected consistently. There is no direct admin creation form or action.
- **Calendar** shows occupied intervals, status, waiver progress, customers when available, blocks, and daily capacity. **Fleet** manages total fleet capacity and links to **Availability**, the dedicated page for creating, editing, and viewing inventory blocks. Availability also retains expired-hold cleanup.
- Holds expire after 15 minutes. Allocation rechecks availability under the shared inventory lock. Overdue active rentals occupy bikes until completed. Completing an active rental records its actual return, including an early return, with the captured turnaround buffer.
- Reservation snapshots preserve agreed package/pricing context internally. Keeping a package during an authorized edit preserves agreed price/duration; replacing it captures current selling terms. Snapshot JSON and request/session metadata are not displayed in administration. Prior snapshot revisions are not retained as a separate history.

## Availability blocks

Use **Availability > Add Availability Block**. Enter Start Date and End Date with date-only controls, choose Start Time and End Time from AM/PM dropdowns, and enter Quantity of Bikes and Reason. Times cover the full local day in the configured Booking Time Increment. Existing off-grid times remain available when editing older blocks; they are not rounded to the new increment.

**All Day** disables the time dropdowns. End Date is inclusive: selecting December 24 through December 26 stores local December 24 at midnight through December 27 at midnight. The underlying interval remains half-open, so an adjacent block can start at that ending boundary. A single day uses equal start/end dates. Dates advance by local calendar day, not a fixed 24 hours; DST can make a day 23 or 25 hours. Invalid or ambiguous local endpoints are rejected by the existing converter.

Timed blocks support the same or different dates, with end after start. **No end date (until disabled)** preserves indefinite blocks and is mutually exclusive with All Day. Quantity must be a positive integer within fleet capacity and available inventory; the existing locked capacity check and required sanitized reason still apply. Existing blocks use the same controls for editing and retain their Active/Inactive and Disable actions. Lists and Calendar label midnight-boundary intervals **All Day**; timed ranges use AM/PM. No all-day flag or schema migration is needed.

## Checkout and waivers

**Full Payment** transfers the existing hold into WooCommerce checkout and uses WooCommerce Square. Duplicate callbacks and cart cleanup retain existing reservation protections. Deposit checkout is unavailable without a compatible provider; there is no custom Square API client or deposit processor.

Under **Settings > Waivers**, configure a dedicated WPForms Elite + Signature form, distinct field mappings, legal text/version, signing page containing `[bike_rental_waiver]`, and invitation templates. **Require Rider Waivers** defaults to No. When enabled, verified paid bookings remain **Pending Waivers** until required adult/guardian evidence is complete. Each adult signs for themselves; a guardian signs separately for each minor. Existing reservations retain their captured policy.

Staff can inspect progress, resend invitations, and record authorized exemptions. Eligible unlinked cancelled/expired reservations can be individually deleted after confirmation; bounded cleanup removes abandoned unpaid, unlinked rider data after 30 terminal days. Orders and protected waiver evidence prevent deletion.

Provider configuration diagnostics and opt-in boolean support records remain available. Raw payloads and bearer values are not exposed by an admin diagnostics panel. Invitation links and protected hidden signing fields necessarily carry the token used for signing.

## Settings and license

**Settings** contains General, Booking Form Branding, Waivers, and the administrator-only License tab. Tabs save their own values without overwriting other tabs.

Branding controls headings, safe introductory HTML, button labels, colors including Product Card Text Color, card/button radius, optional Media Library logo, and administrator-only scoped CSS. Blank colors retain the default appearance; fonts inherit the theme.

License supports Activate, Deactivate, Check License Now, masked key display, plan, expiration, activation usage, validation times, connection status, and bounded seven-day outage grace. Keys are encrypted at rest. New-booking enforcement defaults ON; only `BRP_LICENSE_ENFORCE` in `wp-config.php` can override it. Existing reservations, orders, and waiver records remain accessible. Original-key recovery remains available after site security changes.

The licensing server is maintained independently in [NT License Controller](https://github.com/rdnewb/nt-license-controller) and is never included in the rental ZIP. Billing renewals and private update delivery are not provided by this plugin.

## Installation and deployment

1. Use WordPress 6.6+ and PHP 8.3+ with WooCommerce, a transactional InnoDB database, and the required payment/waiver providers. Back up files and database before an update.
2. Upload the complete inner `bike-rental-plugin/` directory to **`wp-content/plugins/bike-rental-plugin/`**, relative to the rental site's WordPress root. The main file is `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`. The host-specific absolute document root is not recorded in this repository.
3. Activate Bike Rental Plugin. Configure the business, named-city WordPress timezone, operating hours, and fleet capacity. All days initially remain closed; initial fleet capacity is 10 and must be reviewed.
4. Create active rental packages with valid prices and durations. Publish the booking page, select Full Payment, configure Square, and activate the license.
5. Configure waivers and the signing page if required, then perform the [production QA checklist](docs/admin-menu-1.0.3.md) before accepting bookings.

Use a maintenance window to avoid serving mixed PHP versions. Upload neither the repository root nor tests, docs, tooling, or controller source. Normal initialization verifies schema 2; reactivation is not required. Deactivation, uninstall, and file replacement preserve stored data. Do not restore an old database over newer business records or run older allocation code against current commitments.

## Architecture and security

Runtime code is under `bike-rental-plugin/`. `Plugin` coordinates hooks; `Settings`, `Branding`, and `Packages` validate configuration; `BlockInput` converts admin date/time controls; `Database`, `Reservations`, `Fleet`, and `Availability` manage persistence and allocation; `BookingSchedule`, `GuestSession`, and `PublicBooking` handle public booking. Checkout, payment, cart, waiver, and license services retain separate responsibilities.

The four prefixed tables are `brp_reservations`, `brp_availability`, `brp_riders`, and `brp_waivers`. Code version (`brp_plugin_version`) and schema version (`brp_db_version`) are separate. No schema migration is introduced in 1.0.3. Snapshots, hashes, and revision values remain stored for integrity and concurrency checks.

Admin mutations require capabilities and nonces. Public booking uses same-origin checks, a signed HttpOnly guest cookie, session-bound CSRF protection, and server-side ownership checks. Required hidden form values and protocol fields remain; they are not presented as diagnostic data. Inventory writes use prepared SQL and shared locking. License keys, payment credentials, session hashes, and raw callbacks are not displayed in normal reservation UI.

## Development and verification

Work directly on `main` unless instructed otherwise. Push or deploy only when explicitly authorized. Keep version markers synchronized; never commit credentials, local dependencies, or test exports.

Run `php -n tests/packages.php` for foundation/package checks, `php -n tests/production-cleanup.php` for reservation UI regressions, and `php -n tests/availability-ui.php` for block input, rendering, and in-memory allocation checks. The last suite can export markup with `BRP_BLOCK_FIXTURE_DIR` for `tests/availability-ui-browser.cjs`. Integration, browser, concurrency, payment, waiver, and licensing suites remain in `tests/`; database suites require the strictly guarded disposable WordPress fixture and run sequentially. Do not weaken guards or point tests at business data.

Build with `python tools/package.py`. The release is `.release/production/bike-rental-plugin-1.0.3.zip`; every entry is compared with runtime source. See [release verification and QA](docs/admin-menu-1.0.3.md) for actual results and pending QA. Live Square acceptance, WPForms signing/mail delivery, and host/theme behavior must be verified on the deployment site.

Developer references (not shipped): [reservation storage](docs/reservation-storage.md), [waiver architecture](docs/waiver-architecture.md), [WPForms setup](docs/wpforms-waiver-provider.md), [license setup](docs/license-client-setup.md), and [license architecture](docs/licensing-architecture.md). Other verification documents in `docs/` are historical developer records; their release counts and terminology describe the versions named there, not current release acceptance.
