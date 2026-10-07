<?php

namespace App\Http\Controllers;

use App\Models\PurchaseReturn;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;

class WastageTrackerController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Wastage_Tracker_view', PurchaseReturn::class);

        // Determine filter period
        if ($request->filled('from') && $request->filled('to')) {
            $from = $request->from;
            $to = $request->to;
        } else {
            $month = $request->month ?? Carbon::now()->month;
            $year = $request->year ?? Carbon::now()->year;
            $from = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
            $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        }

        // Query wastage returns
        $wastage_returns = PurchaseReturn::with('provider', 'warehouse')
            ->where('deleted_at', null)
            ->where('return_type', 'wastage')
            ->whereBetween('date', [$from, $to])
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'date' => $item->date . ' ' . $item->time,
                    'Ref' => $item->Ref,
                    'supplier_name' => $item->provider ? $item->provider->name : '---',
                    'warehouse_name' => $item->warehouse ? $item->warehouse->name : '---',
                    'GrandTotal' => number_format($item->GrandTotal, 2, '.', ''),
                    'statut' => $item->statut,
                ];
            });

        // Calculate totals for filtered period
        $total_wastage = PurchaseReturn::where('deleted_at', null)
            ->where('return_type', 'wastage')
            ->whereBetween('date', [$from, $to])
            ->sum('GrandTotal');

        return response()->json([
            'wastage_returns' => $wastage_returns,
            'total_wastage' => number_format($total_wastage, 2, '.', ''),
            'filters' => [
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }
}
