# Wastage Return — Audit Report, Decisions & Suggestions

Date: 2026-10-06
Plan audited: `plans/purchase-return-type-wastage-tracker.md`
Scope: plan completeness, plus every place a purchase return feeds a calculation (stock, supplier dues, reports, dashboard, tax, PDFs).
No code was changed as part of this audit.

---

## 1. Summary

- The plan is almost fully implemented. All 22 files in the plan's summary table are in place, all migrations have run, and the compiled bundle is newer than the last source edit.
- The core wastage flow calculates correctly: create, edit, stock movement, payment blocking, the supplier list page and the Wastage Tracker.
- Three plan gaps remain (section 3).
- Five places produce wrong numbers for wastage returns (section 4).
- Five points need a business decision (section 5).

Confirmed rule (decided by the owner): **a wastage return always records the full purchase grand total**, regardless of the quantities entered.

---

## 2. What is implemented and working

| Area | Where | Status |
|---|---|---|
| `return_type` column, default `damaged` | `database/migrations/2026_09_29_000001_...` | Done, migrated |
| `wastage_waiver_rate` setting, default 5 | `database/migrations/2026_09_29_000002_...` | Done, migrated |
| `wastage_waiver_amount` column | `database/migrations/2026_10_05_000001_...` | Done, migrated (uncommitted) |
| Translations | `2026_09_29_000003_...`, `2026_10_05_000002_...` | Done, migrated |
| Models (`fillable`, `casts`) | `app/Models/PurchaseReturn.php`, `app/Models/Setting.php` | Done |
| Store / update / list / detail / edit data | `app/Http/Controllers/PurchasesReturnController.php` | Done |
| Payments blocked for wastage | `app/Http/Controllers/PaymentPurchaseReturnsController.php:135-139` | Done |
| Supplier list excludes wastage from dues | `app/Http/Controllers/ProvidersController.php:83, 89, 505` | Done |
| Type change to wastage blocked when payments exist | `PurchasesReturnController.php:275-282` | Done |
| Settings API and settings page field | `SettingsController.php`, `system_settings.vue:727-739` | Done |
| Tracker API, route, policy | `WastageTrackerController.php`, `routes/api.php:657`, `PurchaseReturnPolicy.php:74` | Done |
| Tracker page, router | `wastage_tracker/index_wastage_tracker.vue`, `router.js:1000-1015` | Done |
| Create / edit forms (dropdown, full-purchase total) | `create_purchase_return.vue`, `edit_purchase_return.vue` | Done |
| List page (type column, N/A status, payment buttons hidden) | `index_purchase_return.vue` | Done |
| Permission checkboxes and seeder | `Create_permission.vue`, `Edit_permission.vue`, `PermissionsSeeder.php:846-850` | Done |

---

## 3. Plan gaps

### 3.1 Detail page still shows Paid and Due for wastage
- File: `resources/src/views/app/pages/purchase_return/detail_purchase_return.vue:167-186`
- Plan Step 6 says to hide the payment section for wastage returns.
- Suggestion: wrap both rows in `v-if="purchase_return.return_type !== 'wastage'"`.

### 3.2 Menu item missing from the second sidebar
- File: `resources/src/containers/layouts/largeSidebar/Sidebar.vue` (Purchase Return item is at line 195)
- The item was added only to `VerticalSidebar.vue`. The layout switches between the two sidebars by `getSidebarLayout`, so users on the non-vertical layout have no link to the tracker.
- Suggestion: add the Wastage Tracker item after Purchase Return, guarded by `Wastage_Tracker_view`.

### 3.3 Permission exists only in the seeder
- File: `database/seeders/PermissionsSeeder.php:846-850`
- The local database has the permission (id 195, attached to one role). A production database will not get it unless the seeder is re-run.
- Suggestion: add a migration that inserts `Wastage_Tracker_view` if missing and attaches it to the admin role.

---

## 4. Wrong numbers today

### 4.1 Supplier reports count wastage as money the supplier owes
- `app/Http/Controllers/ReportController.php:999-1009` — `Providers_Report`
- `app/Http/Controllers/ReportController.php:4190-4200` — `download_report_provider_pdf`
- Both sum every purchase return into `total_amount_return` and `return_Due`.
- Suggestion: add `->where('return_type', 'damaged')` to all four sums, matching `ProvidersController`.

### 4.2 Tax summary treats wastage as a refund
- `app/Http/Controllers/AccountingV2/ReportsController.php:229-246`
- It subtracts every return's `GrandTotal` from taxable purchases and its `TaxNet` from input tax.
- The supplier refunds nothing on wastage, so each wastage return understates purchases by the full purchase amount.
- Suggestion: add `->where('return_type', 'damaged')` to the query.

### 4.3 "Due" shows the full amount on wastage returns
The backend computes `due = GrandTotal - paid_amount` for every return, so a wastage return shows its whole total as due.

| Place | File |
|---|---|
| List API | `PurchasesReturnController.php:136` |
| Detail API | `PurchasesReturnController.php:1087` |
| PDF data | `PurchasesReturnController.php`, `Return_pdf` method |
| Detail page rows | `detail_purchase_return.vue:167-186` |
| PDF template (Paid, "Amount Due") | `resources/views/pdf/Purchase_Return_pdf.blade.php:223-227` |
| List PDF export, Due total | `index_purchase_return.vue:921-938` |

- Suggestion: return `due = 0` for wastage in the three backend places, and hide the Paid / Due rows in the detail page and PDF template. The list export total then corrects itself.

### 4.4 Two returns on one purchase double-count the full total
- `PurchasesReturnController::store()` does not check whether the purchase already has a return. Only the purchases list hides the button (`index_purchase.vue:97`).
- Evidence: purchase 15 had two wastage returns at the same time (ids 4 and 5, both now deleted).
- Under the full-purchase rule, each such return would carry the whole purchase total and its waiver.
- Suggestion: in `store()`, reject the request when a non-deleted return already exists for `purchase_id`.

### 4.5 A wastage return can be saved with zero quantities
- `create_purchase_return.vue:573-576` uses `||` where it needs `&&`, so the "please add return quantity" check never fires.
- The edit page probably has the same check; it was not opened.
- Effect for wastage: the full purchase amount and waiver are recorded while no stock leaves.
- Related: stock only drops by the quantities typed, while the amount is always the full purchase.
- Suggestion: fix the condition to `&&`. Optionally, for wastage, pre-fill each line's quantity with the purchased quantity so stock and amount agree.

---

## 5. Decisions needed

### 5.1 Should pending wastage returns count in the tracker?
- `WastageTrackerController.php:33-67` sums all wastage returns regardless of status.
- Recommendation: count only completed returns; add `->where('statut', 'completed')` to the four queries.

### 5.2 Should the waiver change when an old return is edited?
- `PurchasesReturnController.php:444-446` recalculates the waiver with the current settings rate on every edit.
- Editing an old return after a rate change silently changes its waiver.
- Recommendation: keep the stored waiver unless the return type or grand total changes.

### 5.3 Should the waiver reduce what is owed to the supplier?
- Today the waiver is tracked only. Nothing reduces supplier dues or posts income to an account.
- If the 5% should come off supplier dues, that is new work in `ProvidersController` and the supplier reports.
- Recommendation: decide how the factory actually settles the waiver (credit note, cash, deduction from the next bill) before building anything.

### 5.4 Should the tracker respect warehouse and "own records" restrictions?
- Any user with `Wastage_Tracker_view` sees wastage for all warehouses and all users, unlike the purchase return list.
- Recommendation: apply the same warehouse and record-view filters as `PurchasesReturnController::index()`.

### 5.5 Should totals mix damaged and wastage returns?
These are display-only and do not affect profit.

| Total | File |
|---|---|
| Dashboard "Purchase Returns" tile | `DashboardController.php:453` |
| Profit and loss tile | `ReportController.php:1850` |
| Analytics "Total Purchase Return" | `ReportController.php:2348` |
| Return ratio | `ReportController.php:2447` |

- Recommendation: keep damaged only in these totals, and show wastage as its own figure where it is useful (dashboard, profit and loss).

---

## 6. Other notes

- **No backfill for the waiver amount.** The `wastage_waiver_amount` migration defaults to 0. Wastage returns created on production between the 29 Sep release and this one will show a 0 waiver until re-saved. A one-off update (`GrandTotal * rate / 100` where `return_type = 'wastage'` and the amount is 0) would fix it.
- **Rate card can disagree with the amounts.** The tracker shows the current rate from settings, while the waiver amounts are stored at the rate in force when each return was saved.
- **Line items do not add up to the total on wastage.** Tax, discount and shipping on a wastage return come from the entered lines, while the grand total comes from the purchase. The detail page and PDF will show lines that do not sum to the total.
- **Not verified.** Three report lists in `ReportController.php` probably show the same per-row "due": `Returns_Provider` (`:1177`), `Returns_Purchase_Warehouse` (`:1566`) and `get_purchase_return_by_user` (`:2987`). They were not opened.

---

## 7. Suggested order of work

1. Supplier reports filter (4.1) and tax summary filter (4.2) — small, removes wrong money figures.
2. Due = 0 for wastage in API, detail page and PDF (4.3, 3.1).
3. Backend guard against a second return per purchase (4.4) and the quantity check fix (4.5).
4. Sidebar item (3.2) and permission migration (3.3) — needed before deploying.
5. Waiver backfill (section 6), if production already has wastage returns.
6. Decisions in section 5, once answered.
