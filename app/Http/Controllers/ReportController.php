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

        // Previous period for growth comparison
        $prevStartDate = Carbon::parse($startDate)->subDays($endDate->diffInDays($startDate) + 1)->startOfDay();
        $prevEndDate = $startDate->copy()->subDay()->endOfDay();

        // 1. Ambil daftar Outlet yang boleh dilihat user ini
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

        // 2. Query Utama Transaksi 'Paid' - Current Period
        $trxQuery = HistoryTransaction::where('status', 'paid')
            ->whereIn('outlet_id', $allowedOutletIds)
            ->whereBetween('paid_at', [$startDate, $endDate]);

        // Previous period query
        $prevTrxQuery = HistoryTransaction::where('status', 'paid')
            ->whereIn('outlet_id', $allowedOutletIds)
            ->whereBetween('paid_at', [$prevStartDate, $prevEndDate]);

        // --- A. SUMMARY & KPI (Enhanced) ---
        $summaryData = (clone $trxQuery)->selectRaw('
            COUNT(id) as total_trx,
            SUM(total_price) as total_revenue,
            SUM(discount_amount) as total_discount,
            SUM(tax_amount) as total_tax
        ')->first();

        $prevSummaryData = (clone $prevTrxQuery)->selectRaw('
            COUNT(id) as prev_trx,
            SUM(total_price) as prev_revenue
        ')->first();

        $revenue = (int) ($summaryData->total_revenue ?? 0);
        $prevRevenue = (int) ($prevSummaryData->prev_revenue ?? 0);
        $revenueGrowth = $prevRevenue > 0 ? round((($revenue - $prevRevenue) / $prevRevenue) * 100, 1) : null;

        $transactions = (int) ($summaryData->total_trx ?? 0);
        $prevTransactions = (int) ($prevSummaryData->prev_trx ?? 0);
        $trxGrowth = $prevTransactions > 0 ? round((($transactions - $prevTransactions) / $prevTransactions) * 100, 1) : null;

        $avgOrder = $transactions > 0 ? (int) ($revenue / $transactions) : 0;

        $itemsSold = (int) DB::table('order_items')
            ->whereIn('order_id', (clone $trxQuery)->select('order_id'))
            ->sum('qty');

        // --- B. REVENUE CHART & SALES REPORT (Tab 1 & 2) ---
        $salesDaily = (clone $trxQuery)
            ->selectRaw('
                DATE(paid_at) as date,
                COUNT(id) as transactions,
                SUM(subtotal_price) as gross,
                SUM(discount_amount) as discount,
                SUM(tax_amount) as tax,
                SUM(total_price) as net
            ')
            ->groupBy(DB::raw('DATE(paid_at)'))
            ->orderByDesc('date')
            ->get();

        $revenueChart = $salesDaily->sortBy('date')->map(fn($item) => [
            'date' => $item->date,
            'revenue' => (int) $item->net
        ])->values();

        $salesReport = $salesDaily->map(fn($item) => [
            'date' => $item->date,
            'transactions' => (int) $item->transactions,
            'gross' => (int) $item->gross,
            'discount' => (int) $item->discount,
            'tax' => (int) $item->tax,
            'net' => (int) $item->net,
        ])->values();

        // --- C. TOP PRODUCTS & FAST MOVING (Tab 3 - Enhanced) ---
        $topProducts = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->whereIn('order_items.order_id', (clone $trxQuery)->select('order_id'))
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

        // --- D. CASHIER PERFORMANCE (Tab 4) ---
        $cashierPerformance = (clone $trxQuery)
            ->join('users', 'history_transactions.cashier_id', '=', 'users.id')
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
                'name' => $item->name,
                'outlet_name' => $item->outlet_name,
                'transactions' => (int) $item->transactions,
                'revenue' => (int) $item->revenue,
                'avg_trx' => (int) $item->avg_trx
            ]);

        // --- E. PAYMENT METHODS (Enhanced) ---
        $paymentMethods = (clone $trxQuery)
            ->selectRaw('payment_method as method, SUM(total_price) as total, COUNT(id) as count, AVG(total_price) as avg_amount')
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(fn($item) => [
                'method' => $item->method ?? 'Lainnya',
                'total' => (int) $item->total,
                'count' => (int) $item->count,
                'avg_amount' => (int) $item->avg_amount,
                'percentage' => $transactions > 0 ? round(($item->count / $transactions) * 100, 1) : 0
            ]);

        // === NEW FEATURES FOR COMPLETE F&B ANALYTICS ===

        // 1. CATEGORY PERFORMANCE
        $categoryPerformance = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->whereIn('order_items.order_id', (clone $trxQuery)->select('order_id'))
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
                'name' => $item->category ?? 'Uncategorized',
                'sold' => (int) $item->total_sold,
                'revenue' => (int) $item->revenue,
                'percentage' => $revenue > 0 ? round(($item->revenue / $revenue) * 100, 1) : 0
            ]);

        // 2. HOURLY SALES (for heatmap)
        $hourlySales = (clone $trxQuery)
            ->selectRaw('
                HOUR(paid_at) as hour,
                COUNT(id) as transactions,
                SUM(total_price) as revenue
            ')
            ->groupBy(DB::raw('HOUR(paid_at)'))
            ->orderBy('hour')
            ->get()
            ->map(fn($item) => [
                'hour' => (int) $item->hour,
                'transactions' => (int) $item->transactions,
                'revenue' => (int) $item->revenue
            ])->keyBy('hour');

        // Fill missing hours 0-23
        $fullHourly = collect(range(0, 23))->map(function($h) use ($hourlySales) {
            $data = $hourlySales->get($h, (object)['transactions' => 0, 'revenue' => 0]);
            return [
                'hour' => $h,
                'transactions' => (int) $data->transactions,
                'revenue' => (int) $data->revenue
            ];
        });

        // 3. TABLE PERFORMANCE
        $tablePerformance = DB::table('orders')
            ->join('tables', 'orders.table_id', '=', 'tables.id')
            ->join('history_transactions', 'orders.id', '=', 'history_transactions.order_id')
            ->whereIn('history_transactions.id', (clone $trxQuery)->select('id'))
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

        // 4. STATION PERFORMANCE (Kitchen workload)
        $stationPerformance = DB::table('order_items')
            ->join('stations', 'order_items.station_id', '=', 'stations.id')
            ->whereIn('order_items.order_id', (clone $trxQuery)->select('order_id'))
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
                'name' => $item->name,
                'items_prepared' => (int) $item->items_prepared,
                'orders' => (int) $item->orders,
                'revenue' => (int) $item->revenue
            ]);

        // 5. DISCOUNT BREAKDOWN
        $discountBreakdown = (clone $trxQuery)
            ->whereNotNull('discount_amount')
            ->where('discount_amount', '>', 0)
            ->selectRaw('
                discount_amount,
                COUNT(id) as usage_count,
                SUM(discount_amount) as total_discount
            ')
            ->groupBy('discount_amount')
            ->orderByDesc('total_discount')
            ->limit(10)
            ->get()
            ->map(fn($item) => [
                'amount' => (int) $item->discount_amount,
                'usage_count' => (int) $item->usage_count,
                'total_discount' => (int) $item->total_discount,
                'avg_per_trx' => $item->usage_count > 0 ? round($item->total_discount / $item->usage_count) : 0
            ]);

        // 6. SHIFT SUMMARY
        $shiftSummary = ShiftKaryawan::whereIn('outlet_id', $allowedOutletIds)
            ->whereNotNull('ended_at')
            ->whereBetween('ended_at', [$startDate, $endDate])
            ->selectRaw('
                AVG(closing_balance_system - opening_balance) as avg_shift_revenue,
                AVG((closing_balance_system - opening_balance) / NULLIF(shift_ke, 0)) as avg_per_shift,
                COUNT(id) as total_shifts,
                AVG(difference) as avg_variance
            ')
            ->first();

        // 7. Customer Metrics
        $customerMetrics = (clone $trxQuery)
            ->selectRaw('
                COUNT(DISTINCT customer_name) as unique_customers,
                AVG(total_price) as avg_check,
                SUM(CASE WHEN customer_name IS NOT NULL AND customer_name != "" THEN 1 ELSE 0 END) as named_customers
            ')
            ->first();

        return response()->json([
            // Existing (compatible)
            'summary' => [
                'revenue' => $revenue,
                'transactions' => $transactions,
                'avg_order' => $avgOrder,
                'items_sold' => $itemsSold,
                'total_discount' => (int) ($summaryData->total_discount ?? 0),
                'total_tax' => (int) ($summaryData->total_tax ?? 0),
                // New growth metrics
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

            // New comprehensive F&B analytics
            'category_performance' => $categoryPerformance,
            'hourly_sales' => $fullHourly,
            'table_performance' => $tablePerformance,
            'station_performance' => $stationPerformance,
            'discount_breakdown' => $discountBreakdown,
            'shift_summary' => [
                'avg_shift_revenue' => (int) ($shiftSummary->avg_shift_revenue ?? 0),
                'avg_per_shift' => (int) ($shiftSummary->avg_per_shift ?? 0),
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
        // TODO: Implement CSV export using Maatwebsite\Excel or native
        return response()->json(['message' => 'Export CSV lengkap untuk semua metrics sedang dikembangkan. Gunakan data JSON untuk sekarang.'], 501);
    }
}

