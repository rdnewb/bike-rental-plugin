# Milestone 6A — 0.6.0 verification and deployment handoff

Local implementation is complete. **Dedicated-site deployment and real Square sandbox acceptance remain pending.** No remote files, payment settings, orders, or payments were changed. Work stayed on `main`; no branch was created. Schema remains **1**. Source-control completion requires a direct commit on `main` after final review; pushing is not authorized.

This milestone supersedes the deposit-dependent Milestone 6 plan. The prior Acowebs preflight report/test is retained as historical evidence, not a dependency or required passing suite for Full Payment. Deposit processing is **not implemented**. Milestone 7 has **not started**.

## Architecture and payment modes

| Component | Responsibility |
|---|---|
| `PaymentMode` | Generic mode selection/provider validation, payment summaries, and verification of captured payment evidence |
| `CheckoutReservation` | Inventory-locked hold validation, mode snapshot, primary-order claim, bounded payment deadline, and late-payment allocation |
| `Checkout` | Protected hold-to-cart transfer, cart/order validation, Checkout Block integration, and safe rental metadata |
| `Payments` | Woo payment observation, staff exceptions/crosslinks, and bounded reconciliation |
| WooCommerce | Catalog price, totals, tax, customer/delivery addresses, orders, emails, refunds, and financial records |
| WooCommerce Square | Actual card payment processing; no direct Square API calls from the rental plugin |
| Future deposit provider | Amount due now, outstanding balance, and payment-plan behavior; none is selected or implemented |

`brp_settings[payment_mode]` accepts `full` and `deposit`. Full Payment is the default, including existing installations without the new field. Reading old settings does not rewrite them. The Settings API retains capability/nonce protection and all-or-nothing validation. Invalid modes are rejected; a form omitting the new field preserves an existing mode.

Deposit mode produces a prominent administrator warning and blocks new rental checkout with a customer-safe error. There is **no silent fallback to full payment**. `is_deposit_mode_available()`, `validate_provider()`, `get_amount_due_now()`, `get_remaining_balance()`, `get_payment_summary()`, and `paid()` form the small future payment-provider boundary. No custom deposit computation, ledger, or gateway exists.

Full Payment needs no deposit extension. Detected active unsupported deposit extensions block rental checkout, to prevent them from modifying the full-payment path. Deactivate them before testing. This is a generic dependency check, not an Acowebs adapter.

## Checkout workflow and security

1. The existing public form creates its signed-guest-owned 15-minute hold.
2. JavaScript submits the existing request key to a protected POST `/bike-rental/v1/checkout` route. Same-origin checks and the session-bound CSRF token are required. Extra reservation/price/quantity/date parameters are rejected.
3. The server looks up that guest's hold, validates package/snapshot/quantity/schedule/currency/current selling price and capacity, and snapshots payment mode inside the current reservation JSON. No schema change is needed.
4. WooCommerce receives one rental cart line at the held quantity. Retrying transfer reuses it. The form redirects to checkout; a Continue button remains for recovery if transfer fails.
5. Checkout Block creates its ordinary Woo order. The bridge writes safe order/line metadata through CRUD. At actual payment submission, an atomic inventory-side claim links that order/item to the existing reservation.
6. WooCommerce Square processes the full Woo order total. Captured-payment evidence confirms the same reservation; pending/failed attempts do not.

The bridge supports the **WooCommerce Checkout Block**. Classic shortcode rental checkout is explicitly blocked; Woo's normal pay-for-order path is validated separately for owned, unexpired initial-payment retries. Non-rental checkout remains unaffected.

Cart quantity is not editable in the Checkout Block; independent classic cart changes and Store API edits are rejected. Product pages, add-to-cart URLs, and Store API direct rental purchases cannot bypass the hold. Invalid restored cart items are disabled for payment with a booking-form explanation. A price change after holding requires a new booking selection; browser/cart-session prices are never authoritative.

Rental carts are limited to one reservation with no other products and **no coupons**. Existing unrelated carts are not silently emptied. All money/tax calculation remains in WooCommerce. Zero-total/free rental checkout is not supported in this milestone.

Rental cart and order details include reference, package, quantity, local start/end, delivery time, duration, promotional label when present, and payment mode. Times use AM/PM. Raw session hashes, request keys, and CSRF tokens are not copied into Woo order metadata or public item details. Returning to the booking form after confirmation displays confirmed status instead of an expired-hold message.

## Order relation and historical payment mode

The reservation uses its existing `order_id` and `order_item_id`. Woo private metadata includes `_brp_reservation_id`, `_brp_reservation_reference`, `_brp_package_product_id`, `_brp_quantity`, `_brp_start_utc`, `_brp_end_utc`, `_brp_snapshot`, `_brp_fingerprint`, and `_brp_payment_mode`.

The fingerprint identifies the agreed rental state; it is not an ownership credential or a payment ledger. Ownership still requires the signed guest session. Payment mode is snapshotted at transfer in reservation JSON and then on the Woo order. Changing the global setting or package later does not rewrite historical mode. Existing full-payment checkouts retain their agreed mode when the global setting changes to Deposit.

The capacity-row lock prevents two orders from claiming one reservation or one order from claiming two reservations. Duplicate callbacks are no-ops after confirmation. Woo CRUD supports both legacy CPT and HPOS storage; the plugin declares HPOS/Checkout Block compatibility and performs no direct SQL against Woo order tables.

Administrative reservation edits still do not rewrite financial orders. A changed post-checkout booking fingerprint produces a staff exception. Manual confirmation is not proof of payment; reconciliation flags a linked confirmed/active rental whose payment cannot be verified.

## Hooks and payment evidence

Key Woo hooks:

- Cart: `woocommerce_add_to_cart_validation`, `woocommerce_store_api_validate_add_to_cart`, `woocommerce_store_api_validate_cart_item`, `woocommerce_check_cart_items`, `woocommerce_before_calculate_totals`, `woocommerce_get_item_data`, quantity-limit/edit filters, and coupon validation.
- Checkout: `woocommerce_store_api_checkout_update_order_meta`, `woocommerce_store_api_checkout_order_processed`, `woocommerce_before_pay_action`, `woocommerce_available_payment_gateways`.
- Observation: `woocommerce_payment_complete` and `woocommerce_order_status_changed`, priority 50. Status changes trigger inspection, not automatic rental status mapping.
- Recovery: existing `brp_expire_holds` five-minute WP-Cron event, with payment reconciliation after hold cleanup.
- Administration: `woocommerce_admin_order_data_after_order_details` and the reservation detail view.

Full Payment confirmation requires the order's snapshotted mode, Square credit-card gateway ID, nonempty Woo transaction ID, paid date, Square's persisted `_wc_square_credit_card_charge_captured=yes`, and `_wc_square_credit_card_authorization_amount` matching the current full Woo total at Woo currency precision. Authorization-only, partial/mismatched amounts, and status-only changes cannot confirm a hold.

These Square metadata fields were inspected in official WooCommerce Square 5.5.0 source. **The installed dedicated-site Square version and actual successful capture behavior are not yet verified.** The only enabled rental gateway ID is `square_credit_card`; other payment methods have not been implemented. The rental plugin never handles card numbers/CVV, credentials, raw gateway requests, or payment tokens.

Configure Square for automatic charge/capture for the intended full-payment workflow. An authorization-only result remains unconfirmed until captured evidence exists; a later capture must pass the late-payment capacity check. Use the [official Square payment settings](https://woocommerce.com/document/woocommerce-square/payment-settings/) and [sandbox testing instructions](https://woocommerce.com/document/woocommerce-square/testing-the-woocommerce-square-extension-in-sandbox-mode/) for the installed extension.

## Expiry, late money, refunds, and reconciliation

- Ordinary holds last 15 minutes. Preparing cart, loading checkout, and refreshing do not extend the deadline.
- Actual payment submission may set expiry to **original creation +30 minutes**. Repeated submissions cannot extend it further, and an expired hold cannot initiate a new payment. An admin-renewed hold cannot evade the original payment deadline.
- Definitive failure and ambiguous/pending states remain unconfirmed and retain protection only until that bounded deadline. The existing expiry job releases them; Woo history is retained.
- Delayed successful payment reacquires the shared inventory lock. It confirms only if capacity fits. A conflict leaves the rental unallocated/expired and writes `payment_inventory_conflict` plus a prominent order/reservation warning. Subsequent callbacks do not silently clear a definitive conflict; staff must resolve it.
- A captured payment for a cancelled rental or a refund before first confirmation requires staff review. Already confirmed rentals retain inventory/status on both partial and full financial refunds.
- Woo Completed never marks a rental returned. Rental Active/Completed timing and turnaround rules remain those of prior milestones.
- Each reconciliation pass checks at most 50 reservation rows and 50 Woo primary orders, using independent cursors to inspect both directions. It detects missing/inconsistent links, missed paid callbacks, unpaid confirmed rentals, late payment, and duplicate associations. No automatic financial adjustments or refunds occur. WP-Cron depends on traffic; a full scan of larger order histories takes multiple passes.

Admins see crosslinks, historical mode, Woo order/payment state, totals/refunds, and prominent exceptions. Missing Woo/order data produces a safe unavailable message. Staff-only views retain capability checks. Readiness does not yet include waivers.

## Delivery and inventory

WooCommerce shipping/delivery address fields are used for rentals, even when a package was marked virtual. Configure an ordinary Woo delivery zone and rate (a zero-cost rate is valid if appropriate); the plugin does not invent rates. Guest checkout must be enabled in WooCommerce. Customer records remain in Woo, not the rental table.

Rental products ignore Woo retail stock management/in-stock flags for purchase availability. The custom capacity/occupied-interval engine remains authoritative and never writes fleet quantities to Square. Leave rental products out of Square catalog/stock synchronization during configuration; actual site stock-independence testing is still required. A Square catalog price change, if enabled, can invalidate a held price and require rebooking.

## Automated evidence

Disposable environment: WordPress **7.0**, WooCommerce **11.1.0**, PHP **8.3.33**, MariaDB **11.4.8**. Acowebs is deactivated. No real Square payments or outbound customer emails are used.

| Suite | Passing checks |
|---|---:|
| Foundation and packages | 208 |
| Storage, administrative editing, availability, active timing | 407 |
| Multiprocess inventory/checkout/payment races | 59 |
| Calendar scheduling and duration matrix | 667 |
| Public booking | 74 |
| WooCommerce missing/deactivation persistence | 10 |
| Checkout/payment mode/lifecycle/recovery — CPT | 96 |
| Checkout/payment mode/lifecycle/recovery — HPOS | 96 |
| Real rental Store API requests — CPT / HPOS | 21 + 21 |
| Real normal-product Store API requests — CPT / HPOS | 4 + 4 |
| **Total passing check executions** | **1,667** |

The storage-mode runs deliberately execute the same 121 checkout/Store API checks under CPT and HPOS: **1,546 distinct check cases** and **1,667 passing executions**. Counts exclude repeated setup/debug runs and the archived failing Acowebs preflight.

The checkout tests use real Woo CRUD and database services, with simulated persisted Square capture evidence. Store API tests execute actual guest cart/address/shipping/checkout routes with a **local test-only gateway double**, including decline and retry, fractional quantity-three tax totals, order metadata, and normal-product checkout. The normal-product scenario runs in a separate PHP process (`--normal`) to avoid reusing a completed request's in-memory Woo route state. These tests do **not** validate Square's SDK, network, settlement, or browser card form.

The multiprocess tests demonstrate concurrent lock waiting on distinct DB connections: late payment versus a new booking, two primary-order claims, and simultaneous payment callbacks. Existing hourly/calendar-day tests remain passing. All 41 PHP files pass syntax checks; booking JavaScript syntax and `git diff --check` also pass.

Run the guarded local tests with `BRP_ALLOW_DISPOSABLE_TESTS=1` and `BRP_TEST_WP_ROOT` pointing to the existing disposable WordPress installation. Run mutation suites sequentially. Run `tests/checkout.php`, `tests/checkout-store-api.php`, and `tests/checkout-store-api.php --normal` in separate processes for each storage mode. The tests refuse a non-disposable database/host/prefix.

## Dedicated-site status and exact remaining steps

**Real Square sandbox tests completed: none. SFTP deployment performed: none.** The browser connection currently exposes no signed-in browser or deployment session. The site's published checkout content was identified as Checkout Block; public assets indicated Woo 11.1.0 and Acowebs 1.2.12. Authenticated site configuration remains unverified.

1. Back up the test site, deactivate unsupported deposit plugins, and upload the inner `bike-rental-plugin/` directory to **`wp-content/plugins/bike-rental-plugin/`**. The main file must be `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`. Do not upload tests, docs, `.git`, or the repository root.
2. Verify runtime 0.6.0 and schema 1; retain existing packages, settings, fleet, and reservations. Set Payment Mode to Full Payment.
3. Confirm Woo guest checkout, delivery zones/rates, and Checkout Block configuration. Make the dedicated booking/checkout pages accessible to the guest test session. Verify Square sandbox and automatic capture through authenticated administration; do not use live cards/payments.
4. Book one hourly rental and one calendar-day rental. Verify AM/PM, dates, held quantity, delivery fields, full Woo totals/tax, one primary order, successful Square payment, confirmed reservation, and both admin links.
5. Decline a sandbox payment, retry before the bounded deadline, and refresh checkout. Confirm no duplicate order/reservation allocation; allow a failed hold to expire and verify capacity returns.
6. Book quantity three, including a fractional-price taxable case. Compare the full Woo total and tax with Square's captured amount.
7. Exercise delayed success with available capacity and with a competing allocation using an isolated test fixture. Confirm the conflict case cannot overbook and is visible to staff.
8. Try direct rental product purchase, a stale cart, quantity changes, and coupons; verify rejection. Complete an unrelated ordinary-product checkout separately.
9. Refund a sandbox rental payment through Woo/Square and verify the rental remains in its intentional rental state. Confirm retail Square/Woo stock does not govern fleet availability.
10. Select Deposit mode: new rental checkout must fail safely and the admin warning must appear. Restore Full Payment. Check old order/reservation mode snapshots remain unchanged. Perform desktop/mobile/keyboard browser checks of the public form and Checkout Block.

All ten deployment/manual steps above remain pending. Do not call the milestone fully accepted until the real sandbox results are recorded.

## Files changed and source-control handoff

New runtime: `src/PaymentMode.php`, `src/CheckoutReservation.php`, `src/Checkout.php`, `src/Payments.php`.

Updated runtime: main plugin file, `src/Plugin.php`, `src/Settings.php`, `src/settings-page.php`, `src/PublicBooking.php`, `src/Reservations.php`, `src/booking-form.php`, `src/reservations-page.php`, `assets/js/booking.js`, and `readme.txt` (all within the inner plugin directory).

Tests: new `tests/checkout.php` and `tests/checkout-store-api.php`; updated `tests/foundation.php`, `tests/packages.php`, `tests/reservations.php`, `tests/availability-concurrency.php`, and `tests/inventory-worker.php`. Old milestone scope assertions were updated to allow the now-authorized checkout bridge while retaining no custom gateway/order creation/remote API/stock-write/waiver checks.

Documentation: `README.md`, `CHANGELOG.md`, and this report. The prior compatibility report/test is retained as archived preflight evidence. This milestone's commit includes only its implementation, tests, and documentation. The deployment archive and local fixtures remain ignored. Obtain the actual commit hash from Git history; no push is authorized or performed.

Recommended commit message: **`feat: add Full Payment rental checkout and guarded deposit mode in 0.6.0`**

SFTP upload source: the repository's **inner `bike-rental-plugin/` folder**. Destination: **`wp-content/plugins/bike-rental-plugin/`**. Do not upload the repository root.

Prepared local archive: `.release/milestone6a/bike-rental-plugin-0.6.0.zip` (32 plugin files plus one directory entry; file hashes match the local runtime and archive paths contain only the inner plugin folder). SHA-256: `F1FA5F8C8AA6AF15F936DBBF62BBBAAA89673A68915899D914CE7E8EE724A8B2`. This ignored deployment artifact has not been uploaded.
