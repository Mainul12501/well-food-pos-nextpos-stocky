# Purchase Return Type & Wastage Tracker Implementation Plan

## Context

Currently, all purchase returns are treated identically — the supplier is always receivable for due/payments. The business needs two distinct return types:
1. **Damaged Return** — supplier owes refund (current behavior)
2. **Wastage Return** — expired goods, supplier does NOT owe refund, but factory provides a 5% monthly waiver on wastage totals

This plan adds a `return_type` column, adjusts financial flows for wastage returns, and introduces a new Wastage Tracker page with a configurable waiver rate stored in the Settings table.

---

## Step 1: Database Migrations

### 1a. Add `return_type` to `purchase_returns`
**New file:** `database/migrations/xxxx_add_return_type_to_purchase_returns_table.php`

- Add `string('return_type')->default('damaged')` to `purchase_returns` table
- Values: `'damaged'` or `'wastage'`
- All existing records default to `'damaged'` (backward compatible)

### 1b. Add `wastage_waiver_rate` to `settings`
**New file:** `database/migrations/xxxx_add_wastage_waiver_rate_to_settings_table.php`

- Add `float('wastage_waiver_rate')->default(5)` to `settings` table
- Default value: `5` (represents 5%)

---

## Step 2: Update Models

### PurchaseReturn Model
**File:** `app/Models/PurchaseReturn.php`
- Add `'return_type'` to `$fillable` array

### Setting Model
**File:** `app/Models/Setting.php`
- Add `'wastage_waiver_rate'` to `$fillable` array
- Add `'wastage_waiver_rate' => 'double'` to `$casts` array

---

## Step 3: Backend — PurchasesReturnController

**File:** `app/Http/Controllers/PurchasesReturnController.php`

### store() (line ~168)
- Accept `return_type` from request, save to `$order->return_type`
- If `return_type === 'wastage'`, set `payment_statut = 'not_applicable'` instead of `'unpaid'`

### update() (line ~257)
- Allow updating `return_type`
- If changed to/from wastage, adjust `payment_statut` accordingly
- If wastage: ensure no payments exist before allowing type change, set `payment_statut = 'not_applicable'`

### index() — list endpoint
- Include `return_type` in response data so the frontend table can display it

### show() — detail endpoint
- Include `return_type` in response

### edit_purchase_return() — edit data endpoint
- Include `return_type` in response

---

## Step 4: Backend — Block Payments for Wastage Returns

### PaymentPurchaseReturnsController (line ~131)
**File:** `app/Http/Controllers/PaymentPurchaseReturnsController.php`

- In `store()`: After fetching `$PurchaseReturn`, check `return_type`. If `'wastage'`, return error response: `"Payments are not applicable for wastage returns"`

### ProvidersController — pay_purchase_return_due() (line ~494)
**File:** `app/Http/Controllers/ProvidersController.php`

- In `pay_purchase_return_due()`: Filter out wastage returns from the query (add `->where('return_type', 'damaged')`)

### ProvidersController — index() (line ~80)
- Exclude wastage returns from supplier due calculations:
  ```php
  ->where('return_type', 'damaged')  // add to both total_amount_return and total_paid_return queries
  ```

---

## Step 5: Frontend — Create/Edit Purchase Return Forms

### create_purchase_return.vue
**File:** `resources/src/views/app/pages/purchase_return/create_purchase_return.vue`

- Add a **Return Type** dropdown field (between Purchase and Status fields):
  - Options: `"For Damaged Return"` (value: `damaged`), `"For Wastage Return"` (value: `wastage`)
  - Default: `damaged`
  - Required field
- Include `return_type` in the submit payload

### edit_purchase_return.vue
**File:** `resources/src/views/app/pages/purchase_return/edit_purchase_return.vue`

- Same dropdown as create form
- Pre-populate from existing data
- Include `return_type` in the update payload

---

## Step 6: Frontend — Purchase Return List & Detail

### index_purchase_return.vue
**File:** `resources/src/views/app/pages/purchase_return/index_purchase_return.vue`

- Add `return_type` column to the table (display "Damaged" or "Wastage" with badge styling)
- **Hide payment action buttons** (Add Payment, View Payments) when `return_type === 'wastage'`
- For wastage rows, show `payment_statut` as "N/A" instead of "unpaid"

### detail_purchase_return.vue
**File:** `resources/src/views/app/pages/purchase_return/detail_purchase_return.vue`

- Display return type in the detail view
- Hide payment section for wastage returns

---

## Step 7: Wastage Tracker — Backend

### New Controller: `app/Http/Controllers/WastageTrackerController.php`

- **index()** method:
  - Accept filter params: `month`/`year` OR `from`/`to` date range (default: current month)
  - Query `purchase_returns` where `return_type = 'wastage'` and `deleted_at IS NULL`
  - Filter by selected period
  - Read `wastage_waiver_rate` from `Setting::first()` (configurable, default 5%)
  - Calculate:
    - Total wastage amount (`SUM(GrandTotal)`)
    - Waiver amount (`total * waiver_rate / 100`)
    - List of individual wastage returns with supplier name, date, ref, warehouse, grand total
  - Return: `{ wastage_returns, total_wastage, waiver_rate, waiver_amount, filters }`

### New Route in `routes/api.php`:
```php
Route::get('wastage-tracker', 'WastageTrackerController@index');
```

### New Permission:
- `Wastage_Tracker_view` — add to permissions seeder and permission create/edit pages

---

## Step 8: Wastage Tracker — Frontend Page

### New Vue component: `resources/src/views/app/pages/wastage_tracker/index_wastage_tracker.vue`

- **Filter bar** with toggle:
  - **Monthly mode** (default): Month/Year dropdown selectors
  - **Custom range mode**: From/To date pickers
  - Toggle button to switch between modes
- **Summary cards** at top:
  - Total Wastage Amount (e.g., 2000 tk)
  - Waiver Rate (from settings, e.g., 5%)
  - Waiver Amount (e.g., 100 tk)
- **Table** listing individual wastage returns for the selected period:
  - Columns: Date, Ref, Supplier, Warehouse, Grand Total, Status
- Follow existing page patterns (breadcrumb, loading spinner, vue-good-table)

### Waiver Rate in Settings Page
**File:** `resources/src/views/app/pages/settings/system_settings.vue` (or equivalent)
- Add a "Wastage Waiver Rate (%)" input field in the system settings form
- Saves to `settings.wastage_waiver_rate` via existing settings update API

---

## Step 9: Router & Sidebar

### Router (`resources/src/router.js`)
- Add route for wastage tracker page:
  ```js
  {
    name: "wastage_tracker",
    path: "wastage_tracker",
    component: () => import("./views/app/pages/wastage_tracker/index_wastage_tracker")
  }
  ```

### Sidebar (`resources/src/containers/layouts/largeSidebar/VerticalSidebar.vue`)
- Add new menu item after Purchase Return (line ~473):
  ```html
  <li v-if="currentUserPermissions && currentUserPermissions.includes('Wastage_Tracker_view')"
      :class="{ active: isActiveRoute('wastage_tracker') }" class="nav-item">
    <router-link to="/app/wastage_tracker" class="nav-link">
      <i class="nav-icon i-Receipt"></i>
      <span class="nav-text" v-if="!isCollapsed">{{ $t("WastageTracker") }}</span>
    </router-link>
  </li>
  ```

---

## Step 10: Translations & Permissions UI

### Translation files
- Add keys: `WastageTracker`, `ReturnType`, `ForDamagedReturn`, `ForWastageReturn`, `WaiverAmount`, `TotalWastage`, `NotApplicable`

### Permission Create/Edit pages
**Files:**
- `resources/src/views/app/pages/settings/permissions/Create_permission.vue`
- `resources/src/views/app/pages/settings/permissions/Edit_permission.vue`

- Add `Wastage_Tracker_view` checkbox

### Permissions Seeder
**File:** `database/seeders/PermissionsSeeder.php`
- Add `Wastage_Tracker_view` permission

---

## Files to Modify (Summary)

| # | File | Change |
|---|------|--------|
| 1 | `database/migrations/xxxx_add_return_type_to_purchase_returns.php` | **New** — add `return_type` column |
| 2 | `database/migrations/xxxx_add_wastage_waiver_rate_to_settings.php` | **New** — add `wastage_waiver_rate` column |
| 3 | `app/Models/PurchaseReturn.php` | Add `return_type` to fillable |
| 4 | `app/Models/Setting.php` | Add `wastage_waiver_rate` to fillable & casts |
| 5 | `app/Http/Controllers/PurchasesReturnController.php` | Handle return_type in store/update/index/show |
| 6 | `app/Http/Controllers/PaymentPurchaseReturnsController.php` | Block payments for wastage |
| 7 | `app/Http/Controllers/ProvidersController.php` | Exclude wastage from dues |
| 8 | `app/Http/Controllers/SettingsController.php` | Handle wastage_waiver_rate in settings API |
| 9 | `app/Http/Controllers/WastageTrackerController.php` | **New** — tracker API |
| 10 | `resources/src/.../create_purchase_return.vue` | Add return type dropdown |
| 11 | `resources/src/.../edit_purchase_return.vue` | Add return type dropdown |
| 12 | `resources/src/.../index_purchase_return.vue` | Show type column, hide payment for wastage |
| 13 | `resources/src/.../detail_purchase_return.vue` | Show type, hide payment section |
| 14 | `resources/src/.../wastage_tracker/index_wastage_tracker.vue` | **New** — tracker page |
| 15 | `resources/src/.../settings/system_settings.vue` | Add waiver rate field |
| 16 | `routes/api.php` | Add wastage tracker route |
| 17 | `resources/src/router.js` | Add wastage tracker route |
| 18 | `resources/src/.../VerticalSidebar.vue` | Add Wastage Tracker menu item |
| 19 | `resources/src/.../Create_permission.vue` | Add permission checkbox |
| 20 | `resources/src/.../Edit_permission.vue` | Add permission checkbox |
| 21 | `database/seeders/PermissionsSeeder.php` | Add permission |
| 22 | Translation files (lang JSON) | Add new keys |

---

## Verification

1. **Migration**: Run `php artisan migrate` — confirm `return_type` column added with default `'damaged'`
2. **Create Return**: Create a damaged return → verify payment buttons visible, supplier due updated
3. **Create Wastage Return**: Create a wastage return → verify no payment buttons, supplier due NOT affected
4. **Edit Return**: Change type and verify behavior switches correctly
5. **Supplier Page**: Verify supplier dues only count damaged returns
6. **Wastage Tracker**: Navigate to new menu item → verify monthly wastage totals and 5% waiver calculation
7. **Permissions**: Create a role without `Wastage_Tracker_view` → verify menu item hidden
