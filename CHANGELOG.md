# Changelog

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
