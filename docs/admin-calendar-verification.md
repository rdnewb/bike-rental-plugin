# Milestone 7A — weekly reservation calendar (0.7.0)

## Navigation and display

Open **Bike Rentals > Calendar**. The default week contains today in the configured WordPress timezone and starts on WordPress's configured first day of the week. Previous Week, Today and Next Week preserve the selected status filter. Malformed dates/statuses safely use the current week/all statuses.

The seven date columns display daily **Peak reserved / Fleet capacity** and **Available at peak**. The header shows weekly peak and lowest availability. Each row represents one reservation or quantity block, never an individually serialized bike. Its bar spans the actual occupied interval, clipped at week boundaries; hourly fractions remain proportional within each local day, including 23/25-hour DST days. Date/time labels use 12-hour AM/PM with timezone abbreviation and year.

The row label shows reference, snapshotted package, quantity, status, customer when available and any internal exception. Rental times appear above its bar. Native keyboard-accessible Times / details reveals occupied times and order number. Both the reference and accessible bar link to the existing reservation edit/view page. Inventory blocks link to their Fleet record and show quantity, reason and interval; indefinite blocks continue through the visible week. Active blocks remain visible under every reservation-status filter.

| Status | Style | Inventory claim |
| --- | --- | --- |
| Hold | Gray, dashed | Only while its hold expiry is later than the database clock; expired timestamps are labeled even before cleanup |
| Confirmed | Blue | Scheduled occupied interval |
| Active | Green | Scheduled occupied interval until its occupied end has passed; then open-ended until returned/completed |
| Completed | Dark neutral | None from the reservation; actual-return turnaround blocks still count |
| Cancelled | Red, crossed-out status | None |
| Expired | Light gray, dotted | None |
| Inventory block | Orange | Active quantity block's interval, including indefinite blocks |
| Exception | Prominent magenta border and explicit exception text | Uses the reservation's existing allocation status; exception styling alone does not change capacity |

## Capacity, security and performance

`AdminCalendar::load()` checks `Settings::can_manage()` (`manage_options` or `manage_woocommerce`) and the normal storage gate. `render()` independently checks permission. There is no public calendar route and no calendar mutation endpoint. Read-only GET navigation does not require a write nonce. Customer names are never added to public booking responses or custom rental tables.

The model performs one shared capacity-row transaction and calls the existing `Availability::evaluate()` for each of the seven local-day UTC intervals. No new sweep or inventory model exists. Each total includes all current claims, regardless of the display filter: live holds, confirmed/active reservations, stored preparation/turnaround buffers and active quantity blocks. Existing half-open interval semantics and overdue rules are reused, including `Availability::reservation_end()`. Peak is simultaneous usage, not the sum of all rentals touching a day. Negative available quantity is shown rather than hiding an inventory conflict.

Display queries request only rows intersecting the visible week, plus overdue active rows whose effective occupied interval intersects it. They use the existing status/occupied interval and block type/interval indexes. Filtered views add a prepared status predicate. No full-history fetch or schema change is needed. The current implementation executes seven bounded engine reads rather than caching allocation results or duplicating the sweep. All inventory reads share one lock and database timestamp for a consistent view.

Distinct order IDs are collected after the inventory transaction, then fetched in one `wc_get_orders()` batch through Woo CRUD. Only billing display name and order number are retained in the request-local view model. CPT/HPOS are supported. Missing/deleted orders and inactive WooCommerce omit customer details without breaking the calendar. No payment logic or order data is written.

The view is server rendered with scoped CSS and native links/forms/details. No JavaScript calendar library, third-party bundle or new license dependency is added. A 1260px minimum timeline keeps seven readable day columns; its focusable scroll container handles narrow screens without widening the admin page. The reference column and horizontal scale prioritize pooled operational intervals rather than individual bikes or drag-and-drop editing.

## Automated verification

`tests/milestone7a.php` tests current/configured weeks, navigation links, valid/invalid filters, hourly/multi-day rows, every status, expired holds, overdue active intervals, peak overlap versus disjoint usage, buffers, blocks, configured fleet quantity, exact agreement with the existing availability engine, Woo customer/missing order data, escaping, authorized/unauthorized access, and spring/fall DST. Run in both CPT and HPOS modes.

`tests/milestone7a-browser.cjs` exercises the rendered calendar at 1440/1024/768/375px, verifies contained horizontal scroll, one bar per event, accessible links, native details keyboard operation and filter markup. Screenshot inspection uses synthetic fixture data. This local harness does not reproduce the complete WordPress/Divi admin shell.

`tests/reservations-without-woocommerce.php` additionally loads/renders the calendar and invalid booking links with WooCommerce actually deactivated. Existing lifecycle/concurrency suites verify allocation and return rules; this display feature does not change them.

## Dedicated-site acceptance still required

### Local release verification — September 20, 2026

All **2,064 check executions passed**, representing **1,806 distinct checks**; 258 checkout/calendar checks were run once with CPT orders and once with HPOS. Setup-only repeats are excluded. PHP syntax passed for all **47** runtime/test PHP files, JavaScript syntax passed, and local desktop/mobile screenshots were inspected.

| Suite | Passed executions |
| --- | ---: |
| Foundation + packages | 208 |
| Reservation storage/editing + availability + Active rules | 407 |
| Calendar-day duration + generic duration/weekday matrix | 667 |
| Public booking | 74 |
| Concurrent inventory / payment / cart cleanup | 70 |
| Full Payment checkout, CPT + HPOS | 192 |
| Rental Store API checkout, CPT + HPOS | 42 |
| Ordinary Store API checkout, CPT + HPOS | 8 |
| Cart hold cleanup, CPT + HPOS | 94 |
| Product grid PHP + browser | 68 |
| Missing WooCommerce / cross-process persistence | 13 |
| Milestone 7A PHP, CPT + HPOS | 180 |
| Milestone 7A browser | 41 |
| **Total** | **2,064** |

Environment: PHP 8.3.33, WordPress 7.0, WooCommerce 11.1.0, MariaDB 11.4.8 and local Chrome. Payment evidence is simulated; no real Square charge. The public-booking fixture still emits its known duplicate Woo block-registration notices from repeated initialization; assertions pass. The new calendar/deep-link integration and missing-Woo suites have no PHP warnings. The archived Acowebs preflight is excluded because deposit compatibility remains unapproved; no deposit guard was changed.

The final source review covers only this milestone's 20 files:

- Runtime: `bike-rental-plugin.php`, `readme.txt`, `src/Plugin.php`, `src/PublicBooking.php`, `src/booking-form.php`, `src/Availability.php` (visibility of the shared end helper only), `src/DataAdmin.php`, new `src/AdminCalendar.php`, new `src/calendar-page.php`, `assets/js/booking.js`, `assets/css/booking.css`, new `assets/css/calendar.css`.
- Tests: `tests/foundation.php`, `tests/reservations-without-woocommerce.php`, new `tests/milestone7a.php`, new `tests/milestone7a-browser.cjs`.
- Documentation: root `README.md`, `CHANGELOG.md`, this report and `docs/deep-link-booking-verification.md`.

PaymentMode, Checkout, CheckoutReservation, Payments, CartHolds and Database are unchanged. The release ZIP contains only the complete inner plugin directory; local fixtures, screenshots, tests and development documentation are excluded. SFTP destination: `wp-content/plugins/bike-rental-plugin/`.

Local artifact: `.release/milestone7a/bike-rental-plugin-0.7.0.zip`. All 36 runtime file contents were compared against the ZIP entries. SHA-256: `726CD6B8F500415078703E552C21A5E88A2CD42564CCA4245D5CE50AB9857D49`.

### Manual test steps

1. As an administrator and shop manager, open Calendar. Confirm the current week, configured timezone and range; navigate previous/today/next and apply all seven status options. Verify subscriber/guest accounts cannot access it.
2. View hourly, 3-day and 7-day examples. Confirm one bar per reservation, actual occupied boundaries including buffers, readable AM/PM times and links to the correct edit/view page.
3. View mixed Hold/Confirmed/Active/Completed/Cancelled/Expired examples and a payment/inventory exception. Confirm text/styles, hold expiry, overdue Active continuation and customer name from a linked test Woo order.
4. With fleet 10, reserve 3 bikes at 9 AM–1 PM and 4 at 11 AM–3 PM; with zero buffers/other claims the peak is 7 and available is 3. Two disjoint groups of 4 have peak 4, not 8. Compare the calendar with Availability test over each local day's UTC-equivalent interval.
5. Add an overlapping 2-bike maintenance block: the first example's peak becomes 9, available 1. Verify multi-day and indefinite blocks, reason labels and Fleet links. Confirm disabling a block removes its inventory claim.
6. Verify preparation/turnaround across midnight, a live hold followed by its expiry, overdue Active followed by actual completion, and a completion turnaround block. Display filters must never increase the reported available quantity by hiding claims.
7. Compare a DST transition week and a different WordPress timezone. Restore site settings afterward. Review layout in the real WordPress admin at laptop/tablet widths; horizontal scrolling stays inside the calendar.

## Known limitations

- This is current allocation state viewed over dates, **not historical utilization accounting**. Completed rows do not recreate past claims; status/revision audit history is not implemented.
- It is a point-in-time view; refresh or navigate for current data. No polling, drag-and-drop editing, monthly view, individual-bike rows or document/waiver features.
- Dense weeks create one row per event and may require vertical scrolling; pagination/grouping is deferred. Narrow timelines scroll horizontally. Very short rentals have proportionally small bars, with full-size reference links as accessible alternatives.
- Overdue active rows and indefinite blocks can have start dates far before the week because their effective inventory interval still intersects it.
- Customer details depend on available Woo order data. Package names come from reservation snapshots, not current product titles.
- Dedicated-site/browser-theme acceptance and real Square sandbox verification remain pending. No deployment or payment transaction is claimed by local fixtures.

Schema remains **1**. No payment/deposit implementation changes and no waiver integration.
