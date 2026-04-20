<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\HistoryTransaction;
use App\Models\Outlet;
use App\Models\ShiftKaryawan;
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

        $prevStartDate = Carbon::parse($startDate)->subDays($endDate->diffInDays($startDate) + 1)->startOfDay();
        $prevEndDate = $startDate->copy()->subDay()->endOfDay();

        // 1. Ambil daftar Outlet
        $outletsQuery = Outlet::query();
        if ($user->role === 'karyawan') {
            $outletsQuery->where('id', $user->outlet_id);
        } elseif ($user->role === 'manager') {
            $outletsQuery->where('owner_id', $user->id);
            if ($outletId) $outletsQuery->where('id', $outletId);
        } else {
            if ($outletId) $outletsQuery->where('id', $outletId);
        }

        $allowedOutletIds = $outletsQuery->pluck('id');

        // 2. Query Utama Transaksi 'Paid' (Aman dengan prefix tabel)
        $trxQuery = HistoryTransaction::where('history_transactions.status', 'paid')
            ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
            ->whereBetween('history_transactions.paid_at', [$startDate, $endDate]);

        $prevTrxQuery = HistoryTransaction::where('history_transactions.status', 'paid')
            ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
            ->whereBetween('history_transactions.paid_at', [$prevStartDate, $prevEndDate]);

        // --- A. SUMMARY & KPI ---
        $summaryData = (clone $trxQuery)->selectRaw('
            COUNT(history_transactions.id) as total_trx,
            SUM(history_transactions.total_price) as total_revenue
        ')->first();

        $prevSummaryData = (clone $prevTrxQuery)->selectRaw('
            COUNT(history_transactions.id) as prev_trx,
            SUM(history_transactions.total_price) as prev_revenue
        ')->first();

        $revenue = (int) ($summaryData->total_revenue ?? 0);
        $prevRevenue = (int) ($prevSummaryData->prev_revenue ?? 0);
        $revenueGrowth = $prevRevenue > 0 ? round((($revenue - $prevRevenue) / $prevRevenue) * 100, 1) : null;

        $transactions = (int) ($summaryData->total_trx ?? 0);
        $prevTransactions = (int) ($prevSummaryData->prev_trx ?? 0);
        $trxGrowth = $prevTransactions > 0 ? round((($transactions - $prevTransactions) / $prevTransactions) * 100, 1) : null;

        $avgOrder = $transactions > 0 ? (int) ($revenue / $transactions) : 0;

        $itemsSold = (int) DB::table('order_items')
            ->whereIn('order_id', (clone $trxQuery)->select('history_transactions.order_id'))
            ->sum('qty');

        // --- TRIK REVERSE CALCULATION UNTUK DISKON DAN PAJAK ---
        $grossData = DB::table('order_items')
            ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
            ->where('history_transactions.status', 'paid')
            ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
            ->whereBetween('history_transactions.paid_at', [$startDate, $endDate])
            ->selectRaw('
                DATE(history_transactions.paid_at) as date,
                SUM(order_items.total_price) as gross
            ')
            ->groupBy(DB::raw('DATE(history_transactions.paid_at)'))
            ->pluck('gross', 'date');

        $totalGross = $grossData->sum();
        $totalDiscount = $totalGross > $revenue ? $totalGross - $revenue : 0;
        $totalTax = $revenue > $totalGross ? $revenue - $totalGross : 0;

        // --- B. REVENUE CHART & SALES REPORT ---
        $salesDaily = (clone $trxQuery)
            ->selectRaw('
                DATE(history_transactions.paid_at) as date,
                COUNT(history_transactions.id) as transactions,
                SUM(history_transactions.total_price) as net
            ')
            ->groupBy(DB::raw('DATE(history_transactions.paid_at)'))
            ->orderByDesc('date')
            ->get();

        $revenueChart = $salesDaily->sortBy('date')->map(fn($item) => [
            'date' => $item->date,
            'revenue' => (int) $item->net
        ])->values();

        $salesReport = $salesDaily->map(function($item) use ($grossData) {
            $net = (int) $item->net;
            $gross = (int) ($grossData[$item->date] ?? $net);
            $discount = $gross > $net ? $gross - $net : 0;
            $tax = $net > $gross ? $net - $gross : 0;

            return [
                'date' => $item->date,
                'transactions' => (int) $item->transactions,
                'gross' => $gross,
                'discount' => $discount,
                'tax' => $tax,
                'net' => $net,
            ];
        })->values();

        // --- C. TOP PRODUCTS ---
        $topProducts = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->whereIn('order_items.order_id', (clone $trxQuery)->select('history_transactions.order_id'))
            ->selectRaw('
                products.name,
                categories.name as category,
                SUM(order_items.qty) as sold,
                SUM(order_items.total_price) as revenue,
                AVG(order_items.price) as avg_price
            ')
            ->groupBy('products.id', 'products.name', 'categories.name')
            ->orderByDesc('sold')
            ->limit(100)
            ->get()
            ->map(fn($item) => [
                'name' => $item->name,
                'category' => $item->category ?? 'Lainnya',
                'sold' => (int) $item->sold,
                'revenue' => (int) $item->revenue,
                'avg_price' => (int) $item->avg_price
            ]);

        // --- D. CASHIER PERFORMANCE ---
        $cashierPerformance = (clone $trxQuery)
            ->leftJoin('users', 'history_transactions.cashier_id', '=', 'users.id')
            ->join('outlets', 'history_transactions.outlet_id', '=', 'outlets.id')
            ->selectRaw('
                users.name as name,
                outlets.name as outlet_name,
                COUNT(history_transactions.id) as transactions,
                SUM(history_transactions.total_price) as revenue,
                AVG(history_transactions.total_price) as avg_trx
            ')
            ->groupBy('users.id', 'users.name', 'outlets.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn($item) => [
                'name' => $item->name ?? 'Kasir Terhapus',
                'outlet_name' => $item->outlet_name,
                'transactions' => (int) $item->transactions,
                'revenue' => (int) $item->revenue,
                'avg_trx' => (int) $item->avg_trx
            ]);

        // --- E. PAYMENT METHODS ---
        $paymentMethods = (clone $trxQuery)
            ->selectRaw('history_transactions.payment_method as method, SUM(history_transactions.total_price) as total, COUNT(history_transactions.id) as count, AVG(history_transactions.total_price) as avg_amount')
            ->groupBy('history_transactions.payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(fn($item) => [
                'method' => $item->method ?? 'Lainnya',
                'total' => (int) $item->total,
                'count' => (int) $item->count,
                'avg_amount' => (int) $item->avg_amount,
                'percentage' => $transactions > 0 ? round(($item->count / $transactions) * 100, 1) : 0
            ]);

        // --- F. CATEGORY PERFORMANCE ---
        $categoryPerformance = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->whereIn('order_items.order_id', (clone $trxQuery)->select('history_transactions.order_id'))
            ->selectRaw('
                categories.name as category,
                SUM(order_items.qty) as total_sold,
                SUM(order_items.total_price) as revenue
            ')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get()
            ->map(fn($item) => [
                'name' => $item->category ?? 'Tanpa Kategori',
                'sold' => (int) $item->total_sold,
                'revenue' => (int) $item->revenue,
                'percentage' => $revenue > 0 ? round(($item->revenue / $revenue) * 100, 1) : 0
            ]);

        // --- G. HOURLY SALES ---
        $hourlySales = (clone $trxQuery)
            ->selectRaw('
                HOUR(history_transactions.paid_at) as hour,
                COUNT(history_transactions.id) as transactions,
                SUM(history_transactions.total_price) as revenue
            ')
            ->groupBy(DB::raw('HOUR(history_transactions.paid_at)'))
            ->orderBy('hour')
            ->get()
            ->map(fn($item) => [
                'hour' => (int) $item->hour,
                'transactions' => (int) $item->transactions,
                'revenue' => (int) $item->revenue
            ])->keyBy('hour');

        $fullHourly = collect(range(0, 23))->map(function($h) use ($hourlySales) {
            $data = $hourlySales->get($h, (object)['transactions' => 0, 'revenue' => 0]);
            return [
                'hour' => $h,
                'transactions' => (int) $data['transactions'],
                'revenue' => (int) $data['revenue']
            ];
        });

        // --- H. TABLE PERFORMANCE ---
        $tablePerformance = DB::table('orders')
            ->join('tables', 'orders.table_id', '=', 'tables.id')
            ->join('history_transactions', 'orders.id', '=', 'history_transactions.order_id')
            ->whereIn('history_transactions.id', (clone $trxQuery)->select('history_transactions.id'))
            ->selectRaw('
                tables.name,
                COUNT(orders.id) as orders_count,
                SUM(history_transactions.total_price) as revenue
            ')
            ->groupBy('tables.id', 'tables.name')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get()
            ->map(fn($item) => [
                'name' => $item->name ?? 'Takeaway',
                'orders' => (int) $item->orders_count,
                'revenue' => (int) $item->revenue,
                'avg_check' => $item->orders_count > 0 ? round($item->revenue / $item->orders_count) : 0
            ]);

        // --- I. STATION PERFORMANCE ---
        $stationPerformance = DB::table('order_items')
            ->leftJoin('stations', 'order_items.station_id', '=', 'stations.id')
            ->whereIn('order_items.order_id', (clone $trxQuery)->select('history_transactions.order_id'))
            ->selectRaw('
                stations.name,
                SUM(order_items.qty) as items_prepared,
                COUNT(DISTINCT order_items.order_id) as orders,
                SUM(order_items.total_price) as revenue
            ')
            ->groupBy('stations.id', 'stations.name')
            ->orderByDesc('items_prepared')
            ->limit(10)
            ->get()
            ->map(fn($item) => [
                'name' => $item->name ?? 'Tanpa Station',
                'items_prepared' => (int) $item->items_prepared,
                'orders' => (int) $item->orders,
                'revenue' => (int) $item->revenue
            ]);

        // --- J. SHIFT SUMMARY (FIXED: Menghapus shift_ke) ---
        $shiftSummary = ShiftKaryawan::whereIn('outlet_id', $allowedOutletIds)
            ->whereNotNull('ended_at')
            ->whereBetween('ended_at', [$startDate, $endDate])
            ->selectRaw('
                AVG(closing_balance_system - opening_balance) as avg_shift_revenue,
                COUNT(id) as total_shifts,
                AVG(difference) as avg_variance
            ')
            ->first();

        // --- K. CUSTOMER METRICS ---
        $customerMetrics = (clone $trxQuery)
            ->selectRaw('
                COUNT(DISTINCT history_transactions.customer_name) as unique_customers,
                AVG(history_transactions.total_price) as avg_check,
                SUM(CASE WHEN history_transactions.customer_name IS NOT NULL AND history_transactions.customer_name != "" THEN 1 ELSE 0 END) as named_customers
            ')
            ->first();

        return response()->json([
            'summary' => [
                'revenue' => $revenue,
                'transactions' => $transactions,
                'avg_order' => $avgOrder,
                'items_sold' => $itemsSold,
                'total_discount' => $totalDiscount,
                'total_tax' => $totalTax,
                'revenue_growth' => $revenueGrowth,
                'trx_growth' => $trxGrowth,
                'unique_customers' => (int) ($customerMetrics->unique_customers ?? 0),
                'avg_check' => (int) ($customerMetrics->avg_check ?? 0)
            ],
            'revenue_chart' => $revenueChart,
            'sales_report' => $salesReport,
            'top_products' => $topProducts,
            'cashier_performance' => $cashierPerformance,
            'payment_methods' => $paymentMethods,
            'category_performance' => $categoryPerformance,
            'hourly_sales' => $fullHourly,
            'table_performance' => $tablePerformance,
            'station_performance' => $stationPerformance,
            'shift_summary' => [
                'avg_shift_revenue' => (int) ($shiftSummary->avg_shift_revenue ?? 0),
                'total_shifts' => (int) ($shiftSummary->total_shifts ?? 0),
                'avg_variance' => (int) ($shiftSummary->avg_variance ?? 0)
            ],
            'period_info' => [
                'current' => ['start' => $startDate->toDateString(), 'end' => $endDate->toDateString()],
                'previous' => ['start' => $prevStartDate->toDateString(), 'end' => $prevEndDate->toDateString()]
            ]
        ]);
    }

    public function export(Request $request)
    {
        $startDate = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : Carbon::today()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date)->endOfDay() : Carbon::today()->endOfDay();
        $outletId = $request->outlet_id;
        $user = auth()->user();

        $outletsQuery = Outlet::query();
        if ($user->role === 'karyawan') {
            $outletsQuery->where('id', $user->outlet_id);
        } elseif ($user->role === 'manager') {
            $outletsQuery->where('owner_id', $user->id);
            if ($outletId) $outletsQuery->where('id', $outletId);
        } else {
            if ($outletId) $outletsQuery->where('id', $outletId);
        }
        $allowedOutletIds = $outletsQuery->pluck('id');

        $trxQuery = HistoryTransaction::where('history_transactions.status', 'paid')
            ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
            ->whereBetween('history_transactions.paid_at', [$startDate, $endDate]);

        $summaryData = (clone $trxQuery)->selectRaw('
            COUNT(history_transactions.id) as total_trx,
            SUM(history_transactions.total_price) as total_revenue
        ')->first();

        $grossData = DB::table('order_items')
            ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
            ->where('history_transactions.status', 'paid')
            ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
            ->whereBetween('history_transactions.paid_at', [$startDate, $endDate])
            ->selectRaw('DATE(history_transactions.paid_at) as date, SUM(order_items.total_price) as gross')
            ->groupBy(DB::raw('DATE(history_transactions.paid_at)'))
            ->pluck('gross', 'date');

        $salesDaily = (clone $trxQuery)
            ->selectRaw('DATE(history_transactions.paid_at) as date, COUNT(history_transactions.id) as transactions, SUM(history_transactions.total_price) as net')
            ->groupBy(DB::raw('DATE(history_transactions.paid_at)'))
            ->orderBy('date')
            ->get();

        $topProductsRaw = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->whereIn('order_items.order_id', (clone $trxQuery)->select('history_transactions.order_id'))
            ->selectRaw('products.name, categories.name as category, SUM(order_items.qty) as sold, SUM(order_items.total_price) as revenue')
            ->groupBy('products.id', 'products.name', 'categories.name')
            ->orderByDesc('sold')
            ->limit(50)
            ->get();

        $filename = 'laporan-penjualan-' . $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d') . '.csv';
        $csv = fopen('php://temp', 'r+');

        fputcsv($csv, ['LAPORAN PENJUALAN ENTERPRISE']);
        fputcsv($csv, ['Periode', $startDate->format('d/m/Y') . ' s/d ' . $endDate->format('d/m/Y')]);
        fputcsv($csv, ['Outlet', $outletId ? Outlet::find($outletId)?->name ?? 'Semua' : 'Semua']);
        fputcsv($csv, []);

        $totalRevenue = (int)($summaryData->total_revenue ?? 0);
        $totalGross = $grossData->sum();
        $totalDiscount = $totalGross > $totalRevenue ? $totalGross - $totalRevenue : 0;
        $totalTax = $totalRevenue > $totalGross ? $totalRevenue - $totalGross : 0;

        fputcsv($csv, ['RINGKASAN']);
        fputcsv($csv, ['Total Transaksi', 'Pendapatan', 'Diskon', 'Pajak', 'Rata-rata Order']);
        fputcsv($csv, [
            (int)($summaryData->total_trx ?? 0),
            'Rp ' . number_format($totalRevenue),
            'Rp ' . number_format($totalDiscount),
            'Rp ' . number_format($totalTax),
            'Rp ' . number_format($totalRevenue / max(1, (int)($summaryData->total_trx ?? 0)))
        ]);
        fputcsv($csv, []);

        fputcsv($csv, ['PENJUALAN HARIAN']);
        fputcsv($csv, ['Tanggal', 'Transaksi', 'Gross', 'Diskon', 'Pajak', 'Netto']);
        foreach ($salesDaily as $day) {
            $net = (int) $day->net;
            $gross = (int) ($grossData[$day->date] ?? $net);
            $discount = $gross > $net ? $gross - $net : 0;
            $tax = $net > $gross ? $net - $gross : 0;

            fputcsv($csv, [
                $day->date,
                $day->transactions,
                'Rp ' . number_format($gross),
                'Rp ' . number_format($discount),
                'Rp ' . number_format($tax),
                'Rp ' . number_format($net)
            ]);
        }
        fputcsv($csv, []);

        fputcsv($csv, ['TOP PRODUCTS']);
        fputcsv($csv, ['Produk', 'Kategori', 'Terjual', 'Pendapatan']);
        foreach ($topProductsRaw as $prod) {
            fputcsv($csv, [
                $prod->name,
                $prod->category ?? 'Lainnya',
                $prod->sold,
                'Rp ' . number_format($prod->revenue)
            ]);
        }

        rewind($csv);
        $csvContent = stream_get_contents($csv);
        fclose($csv);

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
