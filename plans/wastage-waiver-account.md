# Wastage Waiver Account — Implementation Plan

Date: 2026-10-07
Status: draft; two decisions confirmed (section 3.1), four assumptions still open (section 3.2)
Supersedes: the waiver parts of `plans/purchase-return-type-wastage-tracker.md`
Related audit: `suggestion/wastage-return-audit.md`

---

## 1. Context

The first implementation calculated a waiver on each wastage return (`purchase_returns.wastage_waiver_amount`). The business rule has changed.

### New requirements

1. Every paid purchase earns a waiver, which is added to a wastage waiver account.
2. No waiver is earned until the purchase is paid.
3. A purchase can be paid, fully or partly, with the waiver balance.
4. The waiver balance resets to 0 on the 1st day of each month.
5. Past months' waiver data must be kept and be filterable.
6. Existing wastage returns stay as a purchase return type only. No waiver is calculated on them any more.

### What stays the same

- The `return_type` column and the damaged / wastage distinction.
- Wastage returns have payment status N/A and accept no payments.
- A wastage return records the full purchase grand total.
- The `settings.wastage_waiver_rate` setting (default 5), now used as the earn rate on paid purchases.

---

## 2. Design

### 2.1 A ledger, not a stored balance

A new append-only table, `wastage_waiver_transactions`, records every movement. Rows are never edited or deleted. A correction is a new reversal row.

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint | Primary key |
| `period` | char(7) | `YYYY-MM` the entry belongs to; indexed |
| `date` | date | Business date of the movement |
| `type` | string | `earn`, `earn_reversal`, `redeem`, `redeem_reversal`, `expire` |
| `amount` | decimal(15,2) | Signed: positive adds to the balance, negative reduces it |
| `rate` | decimal(5,2), nullable | Rate copied from settings at earn time |
| `base_amount` | decimal(15,2), nullable | Amount the rate was applied to |
| `purchase_id` | FK, nullable | Purchase that earned or was paid |
| `payment_purchase_id` | FK, nullable | Payment row for redeem entries |
| `provider_id` | FK, required | Supplier the waiver belongs to; every balance is per supplier |
| `reversal_of_id` | FK, nullable | Entry this row reverses |
| `user_id` | FK, nullable | Who caused it; null for the scheduled close |
| `note` | string, nullable | Free text |
| timestamps | | |

Indexes: `(provider_id, period)`, `(purchase_id, type)`, `payment_purchase_id`.

### 2.2 Balance and monthly reset

- **Balance** = sum of `amount` for one supplier in the current period. There is no global balance; the "total" shown on screens is only the sum of the supplier balances.
- **Reset** happens by itself when the month changes, because the new period has no entries. It does not depend on the server cron.
- **Month close**: a scheduled command on the 1st writes one `expire` entry (negative) per supplier for the previous period's unused balance, so history shows what was lost and each supplier's closed period sums to 0.
- **History**: past months are read from the same table, filtered by `period` and optionally by supplier. No separate archive table is needed.
- Month boundaries use the application timezone.

### 2.2a How per-supplier waiver works

- **No separate account table.** A supplier's waiver account is simply all ledger rows with that `provider_id`. A supplier with no rows has a balance of 0; nothing has to be created when a supplier is added.
- **Earn** goes to the supplier of the purchase that became paid (`purchases.provider_id`).
- **Redeem** is only allowed against the balance of the same supplier as the purchase being paid. Waiver earned from supplier A can never pay a purchase from supplier B.
- **Pay supplier due** (bulk payment from the supplier page) uses that supplier's balance, capped at min(balance, total due).
- **Locking.** The balance check and redeem lock the supplier's `providers` row (`lockForUpdate`) inside the transaction. Payments for different suppliers do not block each other.
- **Changing a purchase's supplier** is blocked when the purchase has an active earn or any waiver payment, because the waiver would otherwise sit with the wrong supplier. The user must remove the payments first.
- **Deleting a supplier** is blocked while the supplier has a non-zero waiver balance in the current period. Past ledger rows are kept either way.
- **Rate.** One rate from settings for all suppliers. The rate is stored on each `earn` row, so a per-supplier rate can be added later without changing history.

### 2.3 Why not a row in `accounts`

The `accounts` table holds cash and bank accounts (DBBL, bKash). A waiver row there would inflate cash totals and be picked up by every cash report. The waiver is supplier credit, so it gets its own ledger.

### 2.4 Rules

1. **Earn.** When a received purchase's `payment_statut` becomes `paid`, post `earn = rate × base`, with the rate copied from settings at that moment. At most one active earn per purchase.
2. **Earn base.** Purchase grand total minus the part paid by waiver (assumption 2).
3. **Un-earn.** If the purchase stops being paid (payment edited or deleted, purchase total raised, purchase deleted), post `earn_reversal` for the active earn, subject to rule 3a.
3a. **No negative balance.** An un-earn is refused when the waiver it would remove has already been spent. The check is on the supplier and the period of the original earn:
   - unspent = earned − earn reversals − redeems + redeem reversals, for that supplier and period
   - if unspent < the earn being reversed, the whole action (purchase delete, payment delete, payment edit, purchase total change) is rejected with HTTP 422 and nothing is changed
   - message: "This purchase cannot be deleted. It earned a waiver of {amount} for {supplier} in {month}, and {spent} of it has already been used. Remove the waiver payments first." (wording adjusted per action; translatable)
   - bulk delete: the whole batch is rejected and the message names the first purchase that failed
   - closed month: if the earn's month is closed and the waiver was unspent, it had expired. The reversal is posted to that month together with an equal `expire` adjustment, so the month still sums to 0 and the current month's balance is not touched.
4. **Redeem.** A purchase payment may use the "Wastage Waiver" payment method. The amount must not exceed the supplier's current balance or the purchase due. It moves no cash account and posts a `redeem` entry linked to the payment row.
5. **Mixed payment.** Part waiver and part cash is two payment rows; the existing screens already support several payments per purchase.
6. **Un-redeem.** Deleting or editing a waiver payment posts `redeem_reversal`, subject to assumption 4.
7. **Concurrency.** Balance check and redeem run in one database transaction with a lock, so two users cannot spend the same waiver.
8. **Rounding.** All amounts are rounded to 2 decimals at posting time.

---

## 3. Decisions and assumptions

### 3.1 Confirmed by the owner (2026-10-07)

| Decision | Detail |
|---|---|
| Waiver is per supplier | Each supplier has its own balance; waiver can only pay that supplier's purchases. See section 2.2a. |
| Deleting a paid purchase reduces the waiver | The earn is reversed. If that would make the supplier's waiver negative (the waiver is already spent), the deletion is refused with a clear error message. See rule 3a. |

### 3.2 Still to confirm

| # | Assumption | Alternative |
|---|---|---|
| 1 | The part of a purchase paid by waiver does not earn new waiver. | Earn on the full grand total, so waiver generates more waiver. |
| 2 | The earning month is the date of the payment that completed the purchase. A payment back-dated into a closed month earns nothing spendable. | Always earn into the current month, whatever the payment date. |
| 3 | Waiver payments from a closed month cannot be edited or deleted, because the balance they would refund has expired. | Allow it and post the refund into the closed month, where it expires immediately. |
| 4 | The waiver already stored on existing wastage returns is discarded, not carried into the new ledger. | Post it as opening `earn` entries for the month of each return. |
| 5 | The no-negative rule also covers payment delete, payment edit and purchase total change, not only purchase delete, since each of them can un-earn a waiver. | Apply it to purchase delete only and let the other actions go negative. |

---

## 4. Implementation steps

### Step 1 — Revoke the old per-return waiver

| File | Change |
|---|---|
| `app/Http/Controllers/PurchasesReturnController.php` | In `store()` (lines ~189-199) and `update()` (lines ~431-447, 468): remove the waiver rate lookup and the `wastage_waiver_amount` calculation. Keep the full-purchase grand total rule for wastage. |
| `app/Http/Controllers/WastageTrackerController.php` | Remove `wastage_waiver_amount`, `waiver_rate`, `waiver_amount` and `cumulative_waiver_amount` from the query and response. Keep `total_wastage` and the list. |
| `resources/src/views/app/pages/wastage_tracker/index_wastage_tracker.vue` | Remove the Waiver Rate, Waiver Amount and Cumulative Waiver cards and the Waiver Amount column. Keep Total Wastage and the table. |
| `app/Models/PurchaseReturn.php` | Leave `wastage_waiver_amount` in `fillable` and `casts` until the column is dropped. |
| `purchase_returns.wastage_waiver_amount` column | Keep for one release so a rollback is possible; drop it in a follow-up migration. |
| `database/migrations/2026_10_05_000002_add_cumulative_waiver_translation.php` | Already migrated locally and not yet committed. Keep it; the key is harmless, and may be reused on the new page. |

### Step 2 — Database

New migrations:

1. `create_wastage_waiver_transactions_table` — the table in section 2.1.
2. `add_is_waiver_to_payment_methods_table` — boolean `is_waiver`, default false; insert one "Wastage Waiver" method with the flag set. Identify the method by the flag, never by name or id.
3. `add_wastage_waiver_permission` — insert `Wastage_Waiver_view` if missing and attach it to the admin role, so production gets it without re-running the seeder. Also add it to `database/seeders/PermissionsSeeder.php`.
4. `add_wastage_waiver_translations` — new translation keys, following the pattern of `2026_09_29_000003_add_wastage_tracker_translations.php`.

New model: `app/Models/WastageWaiverTransaction.php` with relations to purchase, payment, provider and user.

### Step 3 — `app/Services/WastageWaiverService.php`

One class owns every waiver rule. Controllers never write to the ledger directly.

| Method | Responsibility |
|---|---|
| `currentPeriod()` | Returns `YYYY-MM` in the app timezone. |
| `balance(int $providerId, ?string $period = null)` | Sum of entries for the supplier and period. |
| `balances(?string $period = null)` | Balance per supplier for the period, for the Waiver Account page. |
| `syncPurchase(Purchase $purchase, $date)` | Called after any change to a purchase's payment state. Posts `earn` if the purchase is received, paid and has no active earn; posts `earn_reversal` if it has an active earn and is no longer paid; re-posts if the earn base changed. |
| `assertCanUnearn(Purchase $purchase)` | Rule 3a. Throws a `WaiverException` carrying the translated message when the earn is already spent. Called before any change is written. |
| `redeem(PaymentPurchase $payment)` | Locks the supplier row, validates against that supplier's balance and the purchase due, then posts `redeem`. |
| `reverseRedeem(PaymentPurchase $payment)` | Posts `redeem_reversal`, enforcing assumption 3. |
| `closePeriod(string $period)` | For each supplier with a positive balance and no expire entry yet, posts the `expire` entry. Safe to run twice. |
| `summary(string $period, ?int $providerId = null)` | Earned, reversed, redeemed, expired and closing balance, for one supplier or all. |

`WaiverException` is rendered as JSON `{ success: false, message }` with status 422, so every screen can show the message in a toast.

### Step 4 — Call the service from every place that changes purchase payment state

Purchase payment status is currently set in six places. Each must call the service inside its existing transaction.

| File | Method | Call |
|---|---|---|
| `app/Http/Controllers/PaymentPurchasesController.php` | `store()` (line 138) | If waiver method: skip the cash account update, `redeem()`. Then `syncPurchase()`. |
| same | `update()` (line 210) | Handle method or amount change: `reverseRedeem()` / `redeem()` as needed. Then `syncPurchase()`. |
| same | `destroy()` (line 283) | `reverseRedeem()` if waiver payment. Then `syncPurchase()`. |
| `app/Http/Controllers/ProvidersController.php` | `pay_supplier_due()` (line 436) | Per purchase paid in the loop: same as `store()`. Wrap the loop in a transaction; it has none today. |
| `app/Http/Controllers/PurchasesController.php` | `update()` (line ~446) | Block a supplier change when the purchase has an active earn or a waiver payment. `assertCanUnearn()` if the new total makes it unpaid. `syncPurchase()` after the grand total and status are saved. |
| same | `destroy()` (line ~552) and bulk delete (line ~654) | `assertCanUnearn()` first, for every purchase in the batch, before anything is deleted. Then `reverseRedeem()` for each waiver payment and `earn_reversal` for the purchase. |
| `app/Http/Controllers/ProvidersController.php` | `destroy()` / bulk delete | Block while the supplier has a non-zero waiver balance in the current period. |

`assertCanUnearn()` is also called at the top of `PaymentPurchasesController::update()` and `destroy()` when the change would leave the purchase unpaid.

Validation added to the payment endpoints for the waiver method:

- amount ≤ the supplier's current waiver balance
- amount ≤ purchase due
- no `account_id` accepted
- clear error messages returned as JSON with a 422 status

Frontend: the purchase delete, bulk delete and payment delete handlers in `index_purchase.vue` and `detail_purchase.vue` currently show a generic failure toast. They must show `message` from the 422 response when present.

### Step 5 — Accounting hook

- `app/Providers/AccountingV2ServiceProvider.php:147` fires `PaymentPurchaseCreated` for every purchase payment. Its listener has not been read yet.
- A waiver payment must not be posted as cash paid out. It should post as a supplier discount (debit accounts payable, credit purchase discount / other income).
- First task of this step: read the listener and the `PaymentPurchaseDeleted` listener, then decide the journal mapping.

### Step 6 — Scheduler

- New command `app/Console/Commands/WaiverClosePeriod.php`, signature `waiver:close-period {period?}`; defaults to the previous month.
- Register in `app/Console/Kernel.php`: `->monthlyOn(1, '00:05')`.
- Because the balance is derived by period, a missed run does not let expired waiver be spent. The command can be run by hand later to write the missing `expire` entry.

### Step 7 — API

New controller `app/Http/Controllers/WastageWaiverController.php`:

| Route | Purpose |
|---|---|
| `GET wastage-waiver` | Ledger for a period (month/year or from/to) with an optional `provider_id` filter, the period summary, and the balance per supplier. Guarded by `Wastage_Waiver_view`. |
| `GET wastage-waiver/balance?provider_id=` | Current balance of one supplier, for the payment modals. Guarded by the purchase payment permission. |

Add both to `routes/api.php` next to the existing wastage tracker route (line ~657), and a policy method alongside `Wastage_Tracker_view`.

### Step 8 — Frontend

| File | Change |
|---|---|
| `resources/src/views/app/pages/wastage_waiver/index_wastage_waiver.vue` (new) | Supplier filter plus month/year filter and custom range, as on the tracker page. Cards: current balance, earned, used and expired for the selected supplier (or all) and month. A per-supplier table: supplier, earned, used, expired, balance. Ledger table: date, type, purchase ref (link), supplier, rate, amount, user. |
| `resources/src/router.js` | Route for the new page. |
| `resources/src/containers/layouts/largeSidebar/VerticalSidebar.vue` and `Sidebar.vue` | Menu item in both sidebars. Also add the missing Wastage Tracker item to `Sidebar.vue`. |
| `resources/src/views/app/pages/purchases/index_purchase.vue` | Payment modal: when the waiver method is selected, fetch and show the balance of that purchase's supplier, hide the account selector, cap the amount at min(balance, due). Show the server message on a refused delete. |
| `resources/src/views/app/pages/purchases/detail_purchase.vue` | Show the server message on a refused delete. |
| `resources/src/views/app/pages/people/providers.vue` | Same waiver handling for the "pay supplier due" modal, using that supplier's balance. Optional: a Waiver Balance column in the supplier list. |
| `resources/src/views/app/pages/settings/permissions/Create_permission.vue`, `Edit_permission.vue` | `Wastage_Waiver_view` checkbox. |
| `resources/src/views/app/pages/settings/system_settings.vue` | Update the waiver rate hint text to say it applies to paid purchases. |

### Step 9 — Reports

- `payments_purchases.vue` and its API: show the waiver method like any other, so waiver payments can be filtered.
- `ReportController::ProfitAndLoss` (line ~1887): exclude waiver payments from `paiement_purchases` / "payments sent", or show them as a separate figure, because no cash left.
- `DashboardController::Payment_chart` (line ~562): same check for purchase payments.

### Step 10 — Tests

Feature tests in `tests/Feature/WastageWaiverTest.php`:

1. Purchase becomes paid → one `earn` at the settings rate.
2. Partial payment → no earn.
3. Payment deleted so the purchase is partial again → `earn_reversal`.
4. Paying again → a new `earn`, not a duplicate.
5. Purchase paid partly by waiver → earn base excludes the waiver part.
6. Redeem above the balance → rejected.
7. Redeem above the purchase due → rejected.
8. Waiver payment changes no cash account balance.
9. Two simultaneous redeems cannot exceed the balance.
10. Month rollover: new period balance is 0; `closePeriod` writes one `expire`; running it twice writes no second entry.
11. Filtering a past period returns its entries and summary.
12. Wastage return create and update no longer write a waiver amount.
13. Waiver earned from supplier A cannot pay a purchase of supplier B, even when A's balance is large enough.
14. Two suppliers earn and redeem in the same month; each balance only reflects its own entries.
15. Deleting a paid purchase whose waiver is unspent → allowed, `earn_reversal` posted, supplier balance reduced.
16. Deleting a paid purchase whose waiver is already spent → 422 with the message, and the purchase, its payments and the ledger are unchanged.
17. Bulk delete containing one such purchase → the whole batch is rejected.
18. Deleting a purchase paid in a closed month with unspent (expired) waiver → allowed; that month still sums to 0 and the current balance is unchanged.
19. Changing the supplier of a purchase with an active earn → rejected.
20. `closePeriod` writes one `expire` entry per supplier with a positive balance.

---

## 5. Rollout

1. Take a database backup.
2. Deploy migrations, then code, then the compiled frontend.
3. Confirm the server cron runs `php artisan schedule:run` every minute.
4. Earning starts from the go-live date. Purchases already paid before go-live earn nothing (no backfill).
5. Grant `Wastage_Waiver_view` to the roles that need it.
6. Follow-up release: drop `purchase_returns.wastage_waiver_amount`.

### Rollback

- The old column is kept for one release, so the previous code still runs against the migrated database.
- The new table, the payment method row and the permission are additive and can be left in place.

---

## 6. Open items carried from the audit

These are independent of the waiver change and still apply (see `suggestion/wastage-return-audit.md`):

- Supplier reports still count wastage returns as money owed (`ReportController.php:999`, `:4190`).
- Tax summary treats wastage as a refund (`AccountingV2/ReportsController.php:229`).
- Wastage returns show their full total as "Due" in the list, detail page and PDF.
- `PurchasesReturnController::store()` allows a second return on the same purchase.
- The quantity check in `create_purchase_return.vue:573-576` never fires.

---

## 7. Files summary

| # | File | Change |
|---|---|---|
| 1 | `database/migrations/xxxx_create_wastage_waiver_transactions_table.php` | New |
| 2 | `database/migrations/xxxx_add_is_waiver_to_payment_methods_table.php` | New |
| 3 | `database/migrations/xxxx_add_wastage_waiver_permission.php` | New |
| 4 | `database/migrations/xxxx_add_wastage_waiver_translations.php` | New |
| 5 | `app/Models/WastageWaiverTransaction.php` | New |
| 6 | `app/Models/PaymentMethod.php` | `is_waiver` in fillable / casts |
| 7 | `app/Services/WastageWaiverService.php` | New |
| 8 | `app/Http/Controllers/WastageWaiverController.php` | New |
| 9 | `app/Console/Commands/WaiverClosePeriod.php` | New |
| 10 | `app/Console/Kernel.php` | Schedule the command |
| 11 | `app/Http/Controllers/PaymentPurchasesController.php` | Waiver method handling, service calls |
| 12 | `app/Http/Controllers/ProvidersController.php` | Service calls in `pay_supplier_due()` |
| 13 | `app/Http/Controllers/PurchasesController.php` | Service calls in update, destroy, bulk delete |
| 14 | `app/Http/Controllers/PurchasesReturnController.php` | Remove per-return waiver |
| 15 | `app/Http/Controllers/WastageTrackerController.php` | Remove waiver figures |
| 16 | AccountingV2 purchase payment listeners | Waiver payments not posted as cash |
| 17 | `app/Policies/PurchaseReturnPolicy.php` (or a new policy) | `Wastage_Waiver_view` |
| 18 | `routes/api.php` | Two new routes |
| 19 | `database/seeders/PermissionsSeeder.php` | New permission |
| 20 | `resources/src/views/app/pages/wastage_waiver/index_wastage_waiver.vue` | New |
| 21 | `resources/src/views/app/pages/wastage_tracker/index_wastage_tracker.vue` | Remove waiver cards and column |
| 22 | `resources/src/router.js` | New route |
| 23 | `resources/src/containers/layouts/largeSidebar/VerticalSidebar.vue`, `Sidebar.vue` | Menu items |
| 24 | `resources/src/views/app/pages/purchases/index_purchase.vue` | Payment modal |
| 25 | `resources/src/views/app/pages/people/providers.vue` | Pay-due modal |
| 26 | `resources/src/views/app/pages/settings/permissions/Create_permission.vue`, `Edit_permission.vue` | Permission checkbox |
| 27 | `resources/src/views/app/pages/settings/system_settings.vue` | Hint text |
| 28 | `app/Http/Controllers/ReportController.php`, `DashboardController.php` | Separate waiver payments from cash |
| 29 | `tests/Feature/WastageWaiverTest.php` | New |
