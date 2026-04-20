<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\HistoryTransaction;
use App\Models\Outlet;
use Carbon\Carbon;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        // Filter Tanggal
        $startDate = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : Carbon::today()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date)->endOfDay() : Carbon::today()->endOfDay();
        $outletId = $request->outlet_id;

        // 1. Ambil daftar Outlet yang boleh dilihat user ini (Manager/Dev/Karyawan)
        $outletsQuery = Outlet::query();
        if ($user->role === 'karyawan') {
            $outletsQuery->where('id', $user->outlet_id);
        } elseif ($user->role === 'manager') {
            $outletsQuery->where('owner_id', $user->id); // Filter berdasarkan owner_id
            if ($outletId) $outletsQuery->where('id', $outletId);
        } else {
            if ($outletId) $outletsQuery->where('id', $outletId);
        }

        $allowedOutlets = $outletsQuery->get();
        $allowedOutletIds = $allowedOutlets->pluck('id');

        // 2. Query Utama Transaksi 'Paid'
        $trxQuery = HistoryTransaction::where('status', 'paid')
            ->whereIn('outlet_id', $allowedOutletIds)
            ->whereBetween('paid_at', [$startDate, $endDate]);

        // --- A. SUMMARY ---
        $summaryData = (clone $trxQuery)->selectRaw('COUNT(id) as total_trx, SUM(total_price) as total_revenue')->first();
        $revenue = (int) ($summaryData->total_revenue ?? 0);
        $transactions = (int) ($summaryData->total_trx ?? 0);
        $avgOrder = $transactions > 0 ? (int) ($revenue / $transactions) : 0;

        $itemsSold = (int) DB::table('order_items')
            ->whereIn('order_id', (clone $trxQuery)->select('order_id'))
            ->sum('qty');

        // --- B. REVENUE CHART ---
        $revenueChart = (clone $trxQuery)
            ->selectRaw('DATE(paid_at) as date, SUM(total_price) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($item) => [
                'date' => $item->date,
                'revenue' => (int) $item->revenue
            ]);

        // --- C. TOP PRODUCTS ---
        $topProducts = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereIn('order_items.order_id', (clone $trxQuery)->select('order_id'))
            ->selectRaw('products.name, SUM(order_items.qty) as sold, SUM(order_items.total_price) as revenue')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('sold')
            ->limit(10)
            ->get();

        // --- D. PAYMENT METHODS ---
        $paymentMethods = (clone $trxQuery)
            ->selectRaw('payment_method as method, SUM(total_price) as total')
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(fn($item) => [
                'method' => $item->method ?? 'Tunai',
                'total' => (int) $item->total
            ]);

        // --- E. OUTLET PERFORMANCE (FIX: Tampilkan semua cabang, walau revenue 0) ---
        $outletPerformance = $allowedOutlets->map(function($out) use ($startDate, $endDate) {
            $perf = HistoryTransaction::where('outlet_id', $out->id)
                ->where('status', 'paid')
                ->whereBetween('paid_at', [$startDate, $endDate])
                ->selectRaw('SUM(total_price) as revenue, COUNT(id) as transactions')
                ->first();

            return [
                'name' => $out->name,
                'revenue' => (int) ($perf->revenue ?? 0),
                'transactions' => (int) ($perf->transactions ?? 0)
            ];
        })->sortByDesc('revenue')->values();

        return response()->json([
            'summary' => [
                'revenue' => $revenue,
                'transactions' => $transactions,
                'avg_order' => $avgOrder,
                'items_sold' => $itemsSold
            ],
            'revenue_chart' => $revenueChart,
            'top_products' => $topProducts,
            'payment_methods' => $paymentMethods,
            'outlet_performance' => $outletPerformance
        ]);
    }

    public function export(Request $request)
    {
        return response()->json(['message' => 'Fitur export sedang disiapkan.'], 501);
    }
}
