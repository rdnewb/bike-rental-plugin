# Cart removal correction — 0.6.2

Runtime **0.6.2**, schema **1**, directly on `main`. No deposit or Milestone 7 work. Payment confirmation, refunds, provider selection, and Square integration are not otherwise changed. Dedicated-site deployment/manual acceptance and real Square sandbox validation remain pending.

## Root cause and correction

0.6.1 stored rental metadata in Woo cart lines but registered no cart-removal, emptying, or restoration cleanup. Removing a line therefore left its `hold` row consuming inventory until expiry. The browser also retained the request key in sessionStorage and unconditionally restored its old receipt when the shortcode loaded.

`CartHolds` now tracks the exact server-side cart binding: reservation ID, package product ID, and reservation fingerprint. Cleanup passes that binding and the authenticated guest hash to `CheckoutReservation::release_cart_hold()`. Under the existing shared inventory-row lock, the service reads the current row, verifies ownership/product/fingerprint, and changes only `hold` to **`cancelled`**, with `issue_code=cart_removed` and null hold expiry. Cancellation represents explicit abandonment; expiration remains the timeout state.

The existing revision writer updates the timestamp, revision, and current snapshot once. Duplicate removals are no-ops. No reservation or financial order is deleted, and reference, creation timestamp, original owner/request hashes, and order/item IDs remain available for audit. Cancelled inventory immediately stops contributing to availability.

## Supported hooks

Inspected against the locally installed WooCommerce 11.1.0 source and exercised in real WordPress tests:

| Hook | Purpose |
|---|---|
| `woocommerce_cart_item_removed` (priority 5, two arguments) | Read the actual removed cart line before totals/session updates and release its binding |
| `woocommerce_before_cart_emptied` (priority 5) | Remember both serialized and loaded bindings before cart lines disappear, including a not-yet-loaded legacy cart |
| `woocommerce_cart_emptied` (priority 5) | Reconcile now-missing bindings; runs for programmatic `empty_cart()` too |
| `woocommerce_load_cart_from_session` (priority 5) | Remember serialized bindings before Woo discards invalid/restored items; also handles existing 0.6.1 carts |
| `woocommerce_cart_loaded_from_session` (priority 1000) | Compare remembered bindings against the restored cart and release only missing ones |

Successful checkout transfer records its binding after the cart item is added. Cart initialization and lazy loading now happen before hold preparation, so cleanup during restoration cannot be followed by adding an already-cancelled hold. Public receipt restoration loads the Woo cart before reading the reservation, allowing the same reconciliation on REST/Back-button recovery. `wc_load_cart()` alone only initializes the objects; `get_cart()` triggers Woo's session restoration when it has not yet run.

There is no order-deletion hook, browser-close listener, navigation-abandonment timer, or direct SQL deletion. Existing one-reservation-per-cart validation is unchanged. Arbitrary PHP code that changes a cart without firing Woo lifecycle hooks is detected on the next proper cart load if its binding remains available. Total loss of both the Woo cart and its binding metadata cannot safely identify a reservation; ordinary hold expiration remains the fallback rather than guessing the guest's latest booking.

## Payment and concurrency protection

Confirmed, active, completed, cancelled, and expired rows are not transitioned by cleanup. An unpaid pending/failed linked hold without payment evidence may be cancelled, retaining its order history. A later payment uses the existing cancelled-reservation staff-review behavior.

Square's inspected gateway source calls `empty_cart()` after successful payment and after an authorization left on hold. Accordingly, a linked order with a paid status, paid date, transaction ID, or `on-hold` state is protected from automatic cancellation. A missing or inconsistent linked order is also left for existing reconciliation/timeout. These checks prevent cleanup from interpreting financial completion or ambiguity as customer abandonment; they do not confirm payment or change any order status.

Payment callbacks and removal share the same inventory lock. If confirmation wins, removal cannot cancel the confirmed rental. If removal wins before confirmation evidence is available, late money is flagged for staff review without reallocation. Tests exercise both lock orders and duplicate concurrent removal.

On a database retry error, the cart binding is retained for the next reconciliation, with normal hold timeout as the backstop. The customer is not shown a revision-conflict error for repeated cleanup callbacks.

## Exactly which state is cleared

- **Woo `brp_cart_holds`:** removes only the matching binding after cleanup; retains retryable failures.
- **Woo `store_api_draft_order` / `order_awaiting_payment`:** clears only if the referenced order's rental metadata identifies this reservation. Financial orders and stored reservation/order links are preserved.
- **Woo removed-item data:** removes only matching rental Undo entries. A removed rental must use the booking workflow again; ordinary-product Undo data remains intact.
- **Browser `sessionStorage['brp-booking:' + api + pathname]`:** clears when its owned receipt reports `cancelled`, along with the in-memory request key, selection, and review. The package grid is shown immediately. `pageshow` with `persisted=true` rechecks a Back/Forward-cached receipt.
- **Retained:** signed `brp_guest_<blog_id>` cookie and CSRF identity, unrelated Woo session/customer fields and cart items, other order pointers, and historical reservation session/request hashes. The plugin never destroys the whole Woo session. Woo's own `empty_cart()` still performs its normal cart cleanup.

## Automated verification

Disposable WordPress 7.0, WooCommerce 11.1.0, PHP 8.3.33, MariaDB 11.4.8, and local headless Chrome. No real Square requests or customer emails.

| Suite | Passing check executions |
|---|---:|
| Foundation/packages | 208 |
| Storage/editing/availability/Active policy | 407 |
| Calendar scheduling/duration matrix | 667 |
| Public REST booking | 74 |
| Multiprocess races, including cart removal | 70 |
| Checkout lifecycle — CPT + HPOS | 96 + 96 |
| Rental Store API — CPT + HPOS | 21 + 21 |
| Ordinary-product Store API — CPT + HPOS | 4 + 4 |
| New cart cleanup integration — CPT + HPOS | 47 + 47 |
| Product card rendering | 23 |
| Product grid/browser receipt recovery | 45 |
| Missing-WooCommerce persistence | 10 |
| **Total** | **1,840** |

**1,672 distinct check cases**; 168 checkout/cart cases run under both order-storage modes. Counts exclude setup/debug repeats and archived deposit preflight tests. All **44 PHP files** pass syntax checks; booking JavaScript and the browser test pass Node syntax checks. `git diff --check` also passes.

`tests/cart-holds.php` covers actual cart removal/emptying, Checkout Block Store API removal, real Woo session restoration, immediate inventory reuse, wrong-owner/product/fingerprint rejection, matching/unrelated session pointers, no Undo resurrection, finalized-state protection, payment-driven emptying, late payment, and retry after a forced database failure. The concurrency suite proves simultaneous InnoDB contention on independent connections. The browser suite uses real rendered shortcode markup with mocked REST responses to verify fresh-grid recovery, storage clearing, new booking, and Back/Forward-cache revalidation. Separate PHP/Store API tests exercise the real backend.

Run the guarded PHP suites with `BRP_ALLOW_DISPOSABLE_TESTS=1` and the existing `BRP_TEST_WP_ROOT`. Run `tests/cart-holds.php` under CPT and HPOS sequentially. The existing `tests/product-grid.php` fixture export and `tests/product-grid-browser.cjs` instructions remain in the 0.6.1 verification report.

## Remaining manual test-site checks

No authenticated remote browser or deployment was used for this correction. After uploading and clearing caches:

1. Create a rental, confirm its cart line, remove it, and return to the booking page. Verify the old receipt is gone, the grid is shown, and the same interval can be booked immediately. Repeat using the browser Back button and Checkout Block Remove.
2. Add an ordinary product alongside the rental, remove only the ordinary product, and verify the rental hold remains. Mixed-cart checkout remains intentionally disallowed by the existing Version 1 rule.
3. Empty the whole cart and verify the hold is cancelled and the shortcode starts fresh. Repeat removal/refresh to check idempotency.
4. Navigate away without removing anything and verify the hold retains its original bounded expiry. Test an ordinary non-rental checkout separately.
5. Complete Square sandbox checkout and verify payment-driven cart emptying preserves the confirmed reservation. Check guest customer data and admin order/reservation links remain intact. Prior real Square sandbox acceptance is still pending.

The previous active deposit-extension configuration guard remains unchanged. Cart cleanup does not bypass that checkout restriction.

## Files, release, and commit

Runtime: new `src/CartHolds.php`; updates to `src/CheckoutReservation.php`, `src/Checkout.php`, `src/PublicBooking.php`, `src/Plugin.php`, `bike-rental-plugin.php`, `assets/js/booking.js`, and `readme.txt` within the inner plugin folder.

Tests: new `tests/cart-holds.php`; updates to `tests/availability-concurrency.php`, `tests/inventory-worker.php`, `tests/product-grid-browser.cjs`, and version assertions in `tests/foundation.php`. Documentation: `README.md`, `CHANGELOG.md`, and this report. No unrelated files are included.

Commit directly to `main` with: `fix: release temporary rental holds when cart items are removed in 0.6.2`. No branch or push. The actual commit hash is reported after successful creation.

SFTP source: the repository's **inner `bike-rental-plugin/` folder**. Exact destination: **`wp-content/plugins/bike-rental-plugin/`**. The main file must be `wp-content/plugins/bike-rental-plugin/bike-rental-plugin.php`. Do not upload tests, docs, the repository root, or `.git`.

Prepared archive: `.release/milestone6a/bike-rental-plugin-0.6.2.zip`. All **33 archived runtime file hashes** match the local files. Archive SHA-256: `0A2C3C4B702DB47D4CDA1F8445A138DB4586D0A9FD4505E6F2F88D8A38B69F06`. The ignored local archive has not been uploaded.
