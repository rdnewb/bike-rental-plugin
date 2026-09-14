# Milestone 6 compatibility gate — not released

**Archived preflight:** The status and next actions below describe the earlier deposit-dependent Milestone 6 attempt. Milestone 6A supersedes that plan with Full Payment and guarded Deposit mode; see [the current verification report](milestone-6a-verification.md). This report and its reproduction test are retained as evidence, not as a runtime dependency.

Milestone 6 is **incomplete**. No checkout/payment implementation is enabled in the rental plugin, and no 0.6.0 release or SFTP deployment has been made. The current plugin remains **0.5.4**, database schema **1**, on `main`. Milestone 7 has not started.

The authorized integration inspection reproduced two Acowebs problems affecting required acceptance criteria. The user's instruction requires reporting compatibility problems before implementing a custom payment solution. No vendor files or financial calculations have been patched.

## Environment and scope of evidence

Inspected on September 13, 2026:

- The dedicated site's published checkout-page content contains the **WooCommerce Checkout Block**.
- Public assets identify WooCommerce **11.1.0** and Acowebs Deposits & Partial Payments **1.2.12**. These versions have not been verified through authenticated administration.
- The browser connection exposes no available signed-in browser. The public checkout renders the site's coming-soon screen. Square's installed version, connection, sandbox mode, and actual site deposit/tax configuration remain unverified.
- Local reproduction uses official Acowebs 1.2.12 and WooCommerce 11.1.0 packages in the guarded disposable WordPress installation. The fixture uses WordPress 7.0, PHP 8.3.33, and MariaDB 11.4.8.
- Tests execute real WooCommerce cart/tax/order CRUD and registered Acowebs Checkout Block hooks. They do **not** execute the complete browser/Store API checkout flow or contact Square. Hook replay proves the extension's callback behavior, not that every browser refresh triggers it.
- A synthetic 7% tax rate is test data, not a tax recommendation or an assertion about the shop's tax configuration. Test email delivery is suppressed. Test products, orders, tax rate, and modified options are cleaned up/restored.

Official package sources: [Acowebs](https://wordpress.org/plugins/deposits-partial-payments-for-woocommerce/), [WooCommerce](https://wordpress.org/plugins/woocommerce/). Square source 5.5.0 was also inspected for integration planning; this is **not** a claim about the version installed on the dedicated site.

## Blocker 1: fractional balance does not reconcile

The test creates real cart and child-order records:

| Unit price | Quantity | Configured deposit | Woo full total including tax | Deposit | Primary's recorded balance | Balance child's amount at 2 decimals |
|---|---:|---:|---:|---:|---:|---:|
| $100.00 | 1 | 50% | $107.00 | $50.00 | $57.00 | $57.00 |
| $100.00 | 3 | 50% | $321.00 | $150.00 | $171.00 | $171.00 |
| $19.99 | 3 | 50% | $64.17 | $29.99 | $34.18 | **$34.19** |
| $100.00 | 1 | 25% | $107.00 | $25.00 | $82.00 | $82.00 |

For the fractional case, Acowebs retains **34.185** in the future-payment schedule and balance child. At the configured two-decimal currency precision this is $34.19. The deposit and balance therefore total **$64.18**, one cent more than the full order's $64.17. The primary metadata instead records approximately 34.18 (`34.18000000000001` before formatting).

Relevant vendor paths, relative to `deposits-partial-payments-for-woocommerce/`:

- `includes/class-awcdp-front-end.php::awcdp_update_deposit_meta()` computes an unrounded per-line deposit and remaining amount.
- `awcdp_calculated_total()` rounds the aggregate deposit.
- `awcdp_build_payment_schedule()` carries the independently derived remainder forward.
- `awcdp_block_checkout_create_order()` copies that schedule into real child payment orders while separately computing the primary's remaining-balance metadata.

Required acceptance items 17–18 (tax/rounding reconciliation) cannot be marked passed. No Square charge was made, so this is a demonstrated order/schedule mismatch rather than an observed Square overcharge.

## Blocker 2: repeated processing replaces payment children

For each of the four cases, firing `woocommerce_store_api_checkout_order_processed` a second time against the same primary order:

1. Creates a new deposit child and a new balance child.
2. Replaces the child IDs in the primary's payment schedule.
3. Leaves the original two children in WooCommerce: **four persisted payment children** for one primary order.

Acowebs registers two handlers on this hook:

- Priority 10: `AWCDP_Front_End::awcdp_block_checkout_create_order()` creates a fresh schedule/children without checking existing IDs.
- Priority 20: `AWCDP_Blocks_Checkout::create_partial_payments_for_order()` contains an existing-ID check, but executes after the priority-10 handler has already replaced them.

Required retry/idempotency acceptance cannot be marked passed. This test does not demonstrate duplicate Square charges; it demonstrates duplicate financial order records and lost current associations on callback replay.

## Other verified behavior to account for in the bridge

- Acowebs 1.2.12's tested configuration defers the entire tax amount to the balance. A 50% setting on a $100 taxable rental produces $50 due now and $57 later for the fixture's $107 total. It does not produce two $53.50 payments.
- The Checkout Block changes the primary Woo order's total to the deposit. The original full line items and tax remain; the payment schedule and remaining-balance metadata must reconcile before the rental plugin can display a financial summary.
- Payment children have zero separately recorded tax in these tests; the primary retains the tax. This must be checked in real Square settlement/refund reporting before acceptance.
- Merely setting the primary's status to `processing` causes Acowebs to set `_awcdp_deposits_deposit_paid=yes` even with no transaction ID. That flag or order status alone must **never** confirm a reservation. A subsequent adapter must validate authoritative Square captured-payment evidence.
- The 25% case verifies that the extension's configured percentage is honored; the rental plugin should not implement its own fixed 50% calculation.

## Reproduction and verification

`tests/deposit-compatibility.php` refuses execution without the existing disposable CLI opt-in, exact database/host/prefix checks, WooCommerce 11.1.0, Acowebs 1.2.12, and WordPress 7.0 or later. It is not uploaded to WordPress or exposed as an endpoint.

Run with the existing disposable PHP executable/configuration:

```powershell
$env:BRP_ALLOW_DISPOSABLE_TESTS = '1'
$env:BRP_TEST_WP_ROOT = "$env:TEMP\brp-m3-integration\wordpress"
& "$env:TEMP\brp-php83-verification\php.exe" -c "$env:TEMP\brp-m3-integration\php.ini" tests/deposit-compatibility.php
```

Observed result: **64 checks: 53 passed, 11 failed; exit code 1**. The failures are three fractional reconciliation checks and two replay checks for each of four cases. These intentionally remain failing acceptance checks, not assertions rewritten to accept the defects.

Existing foundation/package regression: **208 passed**. The new PHP test passes syntax validation, and `git diff --check` passes. Full inventory/calendar/concurrency suites were not rerun because no runtime code changed. No real Square sandbox tests have been completed.

## Next action and remaining acceptance

Preferred resolution: obtain an Acowebs vendor fix/supported version for both defects and rerun this same matrix. Alternatively, select another approved WooCommerce deposit extension and validate it with Square. A custom workaround needs an explicit decision and review; none has been implemented.

Once the deposit path is resolved, finish the rental bridge and all requested Milestone 6 acceptance tests:

- Protected hold-to-cart transfer, exact quantity/snapshot, direct add-to-cart protection, and guest ownership.
- One primary Woo order linked to the existing reservation; stable Acowebs child associations.
- Payment-submission-only hold extension capped at original creation plus 30 minutes; ordinary refresh never extends it.
- Captured initial payment confirmation, definitive initial failure release, bounded ambiguous state, and locked capacity recheck for late success.
- Balance/refund handling without new allocation, reconciliation, admin crosslinks/payment exceptions, and Square inventory independence.
- Guest delivery address, actual Checkout Block display, tax/rounding, coupons policy, and unchanged earlier milestone behavior.

On the dedicated site, first verify the installed plugin versions and Square sandbox configuration through authenticated administration. Then run successful deposit, decline, retry, quantity-three, late-payment conflict, balance payment, refund, and Square stock-independence cases. Use Woo/Square financial records as evidence. Do not substitute the local hook tests for these payment tests.

## Change and deployment record

Files changed in this compatibility step:

- `tests/deposit-compatibility.php`
- `docs/milestone-6-compatibility.md`
- `README.md` (link to this blocker report)

No production plugin files, version markers, schema definitions, or vendor files changed. No branch created; no commit, push, or SFTP upload performed.

Recommended commit message for this work: `test: document Acowebs blockers before Milestone 6 checkout integration`

Future runtime SFTP destination: **`wp-content/plugins/bike-rental-plugin/`**, uploading the repository's inner `bike-rental-plugin/` directory. **There is nothing to upload from this compatibility step.** Do not upload the repository root, tests, reports, or local fixture.
