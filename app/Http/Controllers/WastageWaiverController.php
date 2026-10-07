<?php

namespace App\Http\Controllers;

use App\Models\PaymentPurchase;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\WastageWaiverTransaction;
use App\Services\WastageWaiverService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class WastageWaiverController extends BaseController
{
    // ------------- Waiver ledger, per-supplier summary and current balance --------------\\

    public function index(Request $request, WastageWaiverService $waiver)
    {
        $this->authorizeForUser($request->user('api'), 'Wastage_Waiver_view', PurchaseReturn::class);

        // Months that ended since the last visit are closed here as well,
        // so history is right even when the scheduler did not run
        $waiver->closePastPeriods();

        $provider_id = $request->filled('provider_id') ? (int) $request->provider_id : null;

        // A month is filtered by its waiver period; a custom range by entry date
        $custom_range = $request->filled('from') && $request->filled('to');
        if ($custom_range) {
            $from = $request->from;
            $to = $request->to;
            $period = null;
        } else {
            $month = $request->month ?? Carbon::now()->month;
            $year = $request->year ?? Carbon::now()->year;
            $period = Carbon::create($year, $month, 1)->format('Y-m');
            $from = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
            $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        }

        $filter = function ($query) use ($custom_range, $from, $to, $period, $provider_id) {
            $query->when($custom_range, fn ($q) => $q->whereBetween('date', [$from, $to]), fn ($q) => $q->where('period', $period))
                ->when($provider_id, fn ($q) => $q->where('provider_id', $provider_id));
        };

        $transactions = WastageWaiverTransaction::with('provider', 'purchase', 'payment', 'user')
            ->where($filter)
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'date' => $item->date,
                    'period' => $item->period,
                    'type' => $item->type,
                    'supplier_name' => $item->provider ? $item->provider->name : '---',
                    'purchase_id' => $item->purchase_id,
                    'purchase_ref' => $item->purchase ? $item->purchase->Ref : '---',
                    'purchase_deleted' => $item->purchase ? ! is_null($item->purchase->deleted_at) : true,
                    'payment_ref' => $item->payment ? $item->payment->Ref : '---',
                    'rate' => $item->rate,
                    'base_amount' => is_null($item->base_amount) ? null : number_format($item->base_amount, 2, '.', ''),
                    'amount' => number_format($item->amount, 2, '.', ''),
                    'user' => $item->user ? $item->user->username : '---',
                ];
            });

        // Totals per supplier and type for the filtered entries
        $rows = WastageWaiverTransaction::where($filter)
            ->selectRaw('provider_id, type, COALESCE(SUM(amount), 0) as total')
            ->groupBy('provider_id', 'type')
            ->get()
            ->groupBy('provider_id');

        $provider_names = Provider::whereIn('id', $rows->keys())->pluck('name', 'id');

        $current_balances = WastageWaiverTransaction::where('period', $waiver->currentPeriod())
            ->selectRaw('provider_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('provider_id')
            ->pluck('total', 'provider_id');

        $suppliers_summary = [];
        $totals = ['earned' => 0, 'used' => 0, 'expired' => 0];
        foreach ($rows as $id => $types) {
            $by_type = $types->pluck('total', 'type');
            $earned = (float) ($by_type['earn'] ?? 0) + (float) ($by_type['earn_reversal'] ?? 0);
            $used = -((float) ($by_type['redeem'] ?? 0) + (float) ($by_type['redeem_reversal'] ?? 0));
            $expired = -(float) ($by_type['expire'] ?? 0);

            $totals['earned'] += $earned;
            $totals['used'] += $used;
            $totals['expired'] += $expired;

            $suppliers_summary[] = [
                'provider_id' => $id,
                'supplier_name' => $provider_names[$id] ?? '---',
                'earned' => number_format($earned, 2, '.', ''),
                'used' => number_format($used, 2, '.', ''),
                'expired' => number_format($expired, 2, '.', ''),
                'balance' => number_format((float) ($current_balances[$id] ?? 0), 2, '.', ''),
            ];
        }

        $current_balance = $provider_id
            ? (float) ($current_balances[$provider_id] ?? 0)
            : (float) $current_balances->sum();

        $suppliers = Provider::where('deleted_at', '=', null)->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'transactions' => $transactions,
            'suppliers_summary' => $suppliers_summary,
            'suppliers' => $suppliers,
            'current_balance' => number_format($current_balance, 2, '.', ''),
            'earned' => number_format($totals['earned'], 2, '.', ''),
            'used' => number_format($totals['used'], 2, '.', ''),
            'expired' => number_format($totals['expired'], 2, '.', ''),
            'waiver_rate' => $waiver->rate(),
            'filters' => [
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    // ------------- Spendable waiver of one supplier, for the payment forms --------------\\

    public function balance(Request $request, WastageWaiverService $waiver)
    {
        $user = $request->user('api');
        if (! $user->can('create', PaymentPurchase::class)
            && ! $user->can('pay_supplier_due', Provider::class)
            && ! $user->can('Wastage_Waiver_view', PurchaseReturn::class)) {
            $this->authorizeForUser($user, 'create', PaymentPurchase::class);
        }

        $provider_id = $request->provider_id;
        if (! $provider_id && $request->filled('purchase_id')) {
            $provider_id = Purchase::where('id', $request->purchase_id)->value('provider_id');
        }

        return response()->json([
            'balance' => $provider_id ? number_format($waiver->balance((int) $provider_id), 2, '.', '') : '0.00',
        ]);
    }
}
