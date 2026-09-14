# Changelog

## 0.6.0 — Milestone 6A Full Payment checkout

- Replace the hold placeholder with a protected WooCommerce Checkout Block transfer. Reuse the existing reservation, lock its quantity, and reject direct/stale/forged rental purchases, coupons, and mixed rental carts.
- Add generic `payment_mode` settings: Full Payment by default; Deposit is guarded with no silent fallback or required deposit extension. Snapshot the mode at checkout transfer and preserve it on later global/package changes.
- Link one primary Woo order under the inventory lock. Payment submission may extend expiry to original creation plus 30 minutes; refresh and retries cannot renew beyond that deadline.
- Verify persisted WooCommerce/Square capture evidence before confirmation. Recheck capacity for delayed success and retain a prominent staff exception on conflicts. Keep refunds and Woo financial statuses separate from rental return/cancellation.
- Use WooCommerce price/tax/address/order APIs, preserve normal-product checkout, declare CPT/HPOS-compatible order handling, and add bounded reconciliation plus admin payment crosslinks/summaries.
- Keep schema 1. No deposit processor, custom gateway, Square API client, or waiver workflow. Real dedicated-site Square sandbox acceptance remains pending; see `docs/milestone-6a-verification.md` for evidence and limitations.

## 0.5.4 — 12-hour start-time dropdown

- Display public start-time options as 12-hour labels with AM/PM, including correct midnight/noon labels. Keep the submitted `HH:MM` values and scheduling behavior unchanged.
- Bump the asset version to refresh the booking script after deployment. Schema remains 1; no Milestone 6 work.
- Verify JavaScript syntax and seven actual option-label/value cases, including midnight/noon; all 208 foundation/package checks pass. After SFTP update, select a package/date and confirm AM/PM labels in the browser. Full inventory suites were not rerun for this display-only change.

## 0.5.3 — Generic calendar duration and independent pickup

- Apply final-day operating-hour checks only to hourly packages. Calendar-day pickup uses the configured business pickup time regardless of whether that day is open for new deliveries or that time falls within delivery hours.
- Keep one inclusive `start date + (duration amount - 1)` calculation for every valid configured calendar duration. No package/day-specific scheduling branches; the existing package editor supports 1–365 days.
- Preserve Day 1 hours, increment, notice, horizon, valid local-time/positive-interval checks, and authoritative buffered inventory allocation. Remove the now-incorrect calendar pickup-hours warning and update settings guidance.
- Add a 49-case matrix covering every weekday and durations 1–7 with closed other weekdays, pickup outside delivery hours, UTC/buffers, real holds, capacity, and rejected starts. Also test 8/14/30/90/365 days, invalid durations, one-day edge cases, and unchanged hourly endpoint rules.
- This approved policy supersedes the calendar final-day restrictions tested in 0.5.2; existing snapshots/settings and schema 1 are retained. Milestone 6 has not started.
- Pass 1,411 automated checks, including 602 new matrix checks, and syntax validation for all 34 PHP files. Browser/Divi verification remains pending test-site deployment.

## 0.5.2 — Calendar-day rejection diagnostics and settings guidance

- Fix the public start-time endpoint discarding all scheduling errors and presenting configuration failures as generic unavailable inventory. Preserve safe final-day/notice/horizon explanations when no candidate has a valid schedule.
- Add a capability-protected PHP diagnostic method with candidate rejection reasons and separate buffer-only capacity diagnostics. No public debug parameter, new REST route, or automatic noisy logging.
- Warn administrators when the configured calendar-day pickup time falls outside an open day's operating hours, and explain final included day and AM/PM entry. Reject unknown duration metadata explicitly in the scheduling helper.
- Preserve the already-correct start + (days - 1) calculation, final-day-only pickup validation, open endpoints, closed intermediate days, and separate occupied buffers. No hourly scheduling policy change or schema change.
- Reproduce 20 valid calendar starts at 17:00 pickup versus zero at 05:00 pickup with 08:00–18:00 hours; exact deployed-site settings remain unconfirmed. See the calendar verification report before attributing the live issue to that configuration.
- Pass 809 automated checks, including 65 new calendar checks and all 744 prior checks. All 33 PHP files pass syntax validation. No Milestone 6 work or remote deployment.

## 0.5.1 — Active reservation timing correction

- Limit in-progress Active reservations to their scheduled occupied interval. Only after the occupied end (including turnaround) has passed do they become open-ended until staff records completion. Use the same locked database UTC clock for availability reads and proposed allocations.
- Reject Active creation, status changes, and schedule edits before the scheduled rental start with a clear validation message. Preparation time does not permit early activation.
- Reject premature Completed records; Active to Completed records an actual return, including early returns. Preserve actual-return turnaround blocks and no-op/revision protection.
- Explain statuses in administration and document handling of legacy future Active records and overdue conflicts with existing bookings. No automatic record migration, schema change, or Milestone 6 work.
- Pass 744 automated checks, including 54 new Active timing checks and all 45 real multiprocess concurrency checks; all 32 PHP files pass syntax validation. Test-site browser/Divi verification remains pending deployment.

## 0.5.0 — Public rental selection and temporary guest holds

- Add `[bike_rental_booking]` with scoped native controls, loading/error announcements, live quantity limits, calculated pickup, and temporary-hold receipt/countdown/refresh recovery.
- Add public REST catalog/times/availability reads and protected guest session, hold creation, and owned-status requests. Require exact fields, same origin, signed HttpOnly cookie, and session-bound CSRF token for hold operations; suppress private database errors.
- Enforce public start horizon, elapsed notice, opening-relative increments, open endpoints, UTC hourly duration, and inclusive calendar-day pickup. Allow interior closed days and reject invalid DST endpoints.
- Reuse M4 sweep/capacity locking for guest allocations. Capture current selling prices, retain 15-minute expiry and five-minute cleanup, and allow one live public hold per guest session.
- Show association indicators in administration without secrets. Keep schema 1 and all prior administrative editing behavior.
- Verify 690 checks, including 74 public-booking and 45 real multiprocess concurrency checks, plus actual local HTTP behavior and JavaScript syntax. Visual/mobile/keyboard/Divi acceptance remains pending; UI tools had no browser available.
- Stop before Milestone 6: no cart/order creation, checkout redirect, Square calls, deposits, waivers, customer emails, or admin calendar enhancements.

## 0.4.0 — Shared fleet availability and double-booking protection

- Add one sweep-line service using peak simultaneous usage of clipped, half-open occupied intervals; include confirmed/active reservations, unexpired holds, and active dated/indefinite blocks.
- Serialize every allocation, edit, status change, block mutation, and fleet change with the permanent InnoDB capacity-row lock. Fresh replacement checks exclude the edited row and reject conflicts without partial writes.
- Add SQL session-token guards against WordPress reconnecting and retrying an allocation after losing its lock. Failed statements/locks/commits return a bounded retryable error and roll back; no automatic transaction retries.
- Add 15-minute holds, server-derived request hashes and session matching, duplicate-request reuse, and capacity rechecks for expired-hold confirmation.
- Register one five-minute WP-Cron cleanup under a registration lock; expire up to 100 rows per run. Capacity correctness is independent of cleanup timing. Remove the schedule on deactivation while retaining business data.
- Keep active rentals allocated until staff completes them; positive captured turnaround becomes a dated quantity block at actual return.
- Add admin-only availability testing, hold expiry display/confirmation, and clear conflict feedback while preserving package snapshots, revisions, protected fields, permissions, and nonces.
- Verify 611 checks, including 40 real simultaneous-process concurrency checks, with unchanged schema version 1. Dedicated-site SFTP/browser acceptance remains pending.
- Milestone 5 has not started. Public booking, checkout, Square, deposits, waivers, calendar, and emails remain deferred.

## 0.3.1 — Correct administrative reservation editing

- Replace status-restricted detail forms with one full admin/shop-manager edit form for package, quantity, start/end, any valid reservation status, and a short issue code/note.
- Validate selected rental packages and every editable field behind nonce/capability checks; compare revisions both before validation and in the database update.
- Package replacement captures current WooCommerce selling price and new package details. Date/quantity/status changes refresh the current snapshot without silently repricing the retained package.
- Preserve references, creation timestamps, order relationships, IDs/hashes, and other reservations. Real changes increment revision/update modified time; unchanged saves preserve the row exactly.
- Keep raw snapshots read-only. Previous snapshot revisions are not retained yet; future audit/history work may preserve them.
- Add the visible development-only availability warning. No customer booking, conflict checks, payment, or waiver work begins.
- Keep database schema version 1. Verify 477 checks, including 100 new editing checks and a competing database connection; dedicated-site deployment/browser verification remains pending.

## 0.3.0 — Reservation storage and fleet capacity foundation

- Add exactly two prefixed InnoDB tables, schema version 1, and verified idempotent installation during normal initialization for SFTP updates.
- Add a persistent singleton fleet capacity row and quantity-based blocks with add/edit/disable administration.
- Add reservation storage, collision-resistant references, immutable package snapshots, and UTC interval persistence with strict WordPress-local input/display.
- Add six lifecycle statuses, retained cancellation/completion records, optimistic revision checks, and minimal reservation list/detail/test forms.
- Protect fleet and reservation administration with management capabilities, real WordPress nonces, prepared queries, and server-side validation.
- Preserve settings, product metadata, fleet, blocks, and reservations across upgrades, reactivation, and uninstall.
- Verify 208 existing regression checks and 169 real WordPress/WooCommerce/MariaDB checks. Dedicated-site SFTP/browser acceptance remains pending.
- Keep public availability, booking, checkout, payments, deposits, waivers, emails, and full calendar UI out of scope.

## 0.2.0 — Rental package management

- Add a Rental Settings tab to the standard WooCommerce Simple product editor.
- Store five rental metadata fields through WooCommerce product CRUD APIs, protected by product-edit permissions and a product-bound nonce.
- Add a reusable package reader and active-package listing using current regular prices, publication status, and WooCommerce menu order.
- Add a paginated read-only Bike Rentals > Packages overview linking to the product editor.
- Validate durations, flags, and plain-text promotional labels; retain previous rental metadata on invalid submissions.
- Load package integration only when WooCommerce is ready, with an actionable missing-dependency notice.
- Preserve the existing settings foundation; no automatic product creation, reservations, inventory, checkout, payments, or waivers.

## 0.1.0 — Plugin foundation

- Add the generic Bike Rental Plugin bootstrap and native settings page.
- Add validated scheduling settings and weekly operating hours.
- Allow administrators and users with WooCommerce management capability to manage settings.
- Show configured timezone, foundation readiness, and detected dependencies separately from integration testing.
- Preserve options across activation, file updates, deactivation, and uninstall.
- Keep public booking, packages, inventory, payment, and waiver processing outside this milestone.
