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

        // 2. Query Utama Transaksi 'Paid'
        $trxQuery = HistoryTransaction::where('history_transactions.status', 'paid')
            ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
            ->whereBetween('history_transactions.paid_at', [$startDate, $endDate]);

        $prevTrxQuery = HistoryTransaction::where('history_transactions.status', 'paid')
            ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
            ->whereBetween('history_transactions.paid_at', [$prevStartDate, $prevEndDate]);

        // --- A. SUMMARY & KPI ---
        // PERBAIKAN: Ambil langsung SUM diskon dan pajak dari tabel transaksi
        $summaryData = (clone $trxQuery)->selectRaw('
            COUNT(history_transactions.id) as total_trx,
            SUM(history_transactions.total_price) as total_revenue,
            SUM(history_transactions.discount_amount) as total_discount,
            SUM(history_transactions.tax_amount) as total_tax
        ')->first();

        $prevSummaryData = (clone $prevTrxQuery)->selectRaw('
            COUNT(history_transactions.id) as prev_trx,
            SUM(history_transactions.total_price) as prev_revenue
        ')->first();

        $revenue = (int) ($summaryData->total_revenue ?? 0);
        $totalDiscount = (int) ($summaryData->total_discount ?? 0); // Diskon Akurat
        $totalTax = (int) ($summaryData->total_tax ?? 0); // Pajak Akurat

        $prevRevenue = (int) ($prevSummaryData->prev_revenue ?? 0);
        $revenueGrowth = $prevRevenue > 0 ? round((($revenue - $prevRevenue) / $prevRevenue) * 100, 1) : null;

        $transactions = (int) ($summaryData->total_trx ?? 0);
        $prevTransactions = (int) ($prevSummaryData->prev_trx ?? 0);
        $trxGrowth = $prevTransactions > 0 ? round((($transactions - $prevTransactions) / $prevTransactions) * 100, 1) : null;

        $avgOrder = $transactions > 0 ? (int) ($revenue / $transactions) : 0;

        $itemsSold = (int) DB::table('order_items')
            ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
            ->where('history_transactions.status', 'paid')
            ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
            ->whereBetween('history_transactions.paid_at', [$startDate, $endDate])
            ->sum('order_items.qty');

        // Data Gross (Kotor) dari harga per item
        $grossData = DB::table('order_items')
            ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
            ->where('history_transactions.status', 'paid')
            ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
            ->whereBetween('history_transactions.paid_at', [$startDate, $endDate])
            ->selectRaw('
                DATE(history_transactions.paid_at) as date_val,
                SUM(order_items.total_price) as gross
            ')
            ->groupBy(DB::raw('DATE(history_transactions.paid_at)'))
            ->get()
            ->mapWithKeys(function ($item) {
                return [Carbon::parse($item->date_val)->format('Y-m-d') => $item->gross];
            });

        // --- B. REVENUE CHART & SALES REPORT ---
        // PERBAIKAN: SUM langsung diskon dan pajak per tanggal
        $salesDaily = (clone $trxQuery)
            ->selectRaw('
                DATE(history_transactions.paid_at) as date_val,
                COUNT(history_transactions.id) as transactions,
                SUM(history_transactions.total_price) as net,
                SUM(history_transactions.discount_amount) as discount,
                SUM(history_transactions.tax_amount) as tax
            ')
            ->groupBy(DB::raw('DATE(history_transactions.paid_at)'))
            ->orderByDesc('date_val')
            ->get();

        $revenueChart = $salesDaily->sortBy('date_val')->map(fn($item) => [
            'date' => Carbon::parse($item->date_val)->format('Y-m-d'),
            'revenue' => (int) $item->net,
            'transactions' => (int) $item->transactions
        ])->values();

        $salesReport = $salesDaily->map(function($item) use ($grossData) {
            $dateKey = Carbon::parse($item->date_val)->format('Y-m-d');
            $net = (int) $item->net;
            $discount = (int) $item->discount; // Akurat 100%
            $tax = (int) $item->tax; // Akurat 100%

            // Jika ada diskriminasi data item vs nota, gunakan net + diskon - pajak
            $gross = (int) ($grossData[$dateKey] ?? ($net + $discount - $tax));

            return [
                'date' => $dateKey,
                'transactions' => (int) $item->transactions,
                'gross' => $gross,
                'discount' => $discount,
                'tax' => $tax,
                'net' => $net,
            ];
        })->values();

        // --- C. TOP PRODUCTS ---
        $topProducts = DB::table('order_items')
            ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('history_transactions.status', 'paid')
            ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
            ->whereBetween('history_transactions.paid_at', [$startDate, $endDate])
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
            ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('history_transactions.status', 'paid')
            ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
            ->whereBetween('history_transactions.paid_at', [$startDate, $endDate])
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
            $data = $hourlySales->get($h, ['transactions' => 0, 'revenue' => 0]);
            return [
                'hour' => $h,
                'transactions' => (int) $data['transactions'],
                'revenue' => (int) $data['revenue']
            ];
        });

        // --- H. TABLE PERFORMANCE ---
        $tablePerformance = DB::table('orders')
            ->leftJoin('tables', 'orders.table_id', '=', 'tables.id')
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
            ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
            ->leftJoin('stations', 'order_items.station_id', '=', 'stations.id')
            ->where('history_transactions.status', 'paid')
            ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
            ->whereBetween('history_transactions.paid_at', [$startDate, $endDate])
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

        // --- J. SHIFT SUMMARY ---
        $shiftSummary = ShiftKaryawan::whereIn('outlet_id', $allowedOutletIds)
            ->whereNotNull('ended_at')
            ->whereBetween('ended_at', [$startDate, $endDate])
            ->selectRaw('
                AVG(closing_balance_system - opening_balance) as avg_shift_revenue,
                SUM(closing_balance_system - opening_balance) as total_shift_revenue,
                COUNT(id) as total_shifts,
                AVG(difference) as avg_variance,
                SUM(difference) as total_variance
            ')
            ->first();

        // --- K. CUSTOMER METRICS ---
        $customerMetrics = (clone $trxQuery)
            ->leftJoin('orders', 'history_transactions.order_id', '=', 'orders.id')
            ->selectRaw('
                COUNT(DISTINCT NULLIF(TRIM(orders.customer_name), "")) as unique_customers,
                AVG(history_transactions.total_price) as avg_check,
                SUM(CASE WHEN NULLIF(TRIM(orders.customer_name), "") IS NOT NULL THEN 1 ELSE 0 END) as named_customers
            ')
            ->first();

        return response()->json([
            'summary' => [
                'revenue' => $revenue,
                'transactions' => $transactions,
                'avg_order' => $avgOrder,
                'items_sold' => $itemsSold,
                'total_discount' => $totalDiscount, // Sudah akurat!
                'total_tax' => $totalTax, // Sudah akurat!
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
                'total_shift_revenue' => (int) ($shiftSummary->total_shift_revenue ?? 0),
                'total_shifts' => (int) ($shiftSummary->total_shifts ?? 0),
                'avg_variance' => (int) ($shiftSummary->avg_variance ?? 0),
                'total_variance' => (int) ($shiftSummary->total_variance ?? 0)
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
        $reportType = $request->report_type ?? 'summary'; // Tangkap tab yang aktif
        $format = $request->format ?? 'excel'; // pdf atau excel
        $user = auth()->user();

        // 1. Filter Outlet
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

        // Query Utama
        $trxQuery = HistoryTransaction::where('status', 'paid')
            ->whereIn('outlet_id', $allowedOutletIds)
            ->whereBetween('paid_at', [$startDate, $endDate]);

        // Siapkan File CSV
        $filename = 'Laporan_' . ucfirst($reportType) . '_' . $startDate->format('Ymd') . '-' . $endDate->format('Ymd') . '.csv';
        $csv = fopen('php://temp', 'r+');

        // Header Umum Laporan
        fputcsv($csv, ['LAPORAN ENTERPRISE - ' . strtoupper($reportType)]);
        fputcsv($csv, ['Periode', $startDate->format('d/m/Y') . ' s/d ' . $endDate->format('d/m/Y')]);
        $outletName = $outletId ? Outlet::find($outletId)?->name ?? 'Semua Cabang' : 'Semua Cabang';
        fputcsv($csv, ['Outlet', $outletName]);
        fputcsv($csv, []); // Baris kosong

        // --- LOGIKA EKSPOR BERDASARKAN TAB ---

        if ($reportType === 'summary' || $reportType === 'sales') {
            // EKSPOR: PENJUALAN HARIAN
            $grossData = DB::table('order_items')
                ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
                ->where('history_transactions.status', 'paid')
                ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
                ->whereBetween('history_transactions.paid_at', [$startDate, $endDate])
                ->selectRaw('DATE(history_transactions.paid_at) as date_val, SUM(order_items.total_price) as gross')
                ->groupBy(DB::raw('DATE(history_transactions.paid_at)'))
                ->get()->mapWithKeys(fn ($item) => [Carbon::parse($item->date_val)->format('Y-m-d') => $item->gross]);

            $salesDaily = (clone $trxQuery)
                ->selectRaw('DATE(paid_at) as date_val, COUNT(id) as transactions, SUM(total_price) as net, SUM(discount_amount) as discount, SUM(tax_amount) as tax')
                ->groupBy(DB::raw('DATE(paid_at)'))->orderBy('date_val')->get();

            fputcsv($csv, ['Tanggal', 'Jumlah Trx', 'Total Kotor (Gross)', 'Diskon', 'Pajak/Biaya', 'Pendapatan Bersih (Net)']);

            $totalGross = 0; $totalDiscount = 0; $totalTax = 0; $totalNet = 0;

            foreach ($salesDaily as $day) {
                $dateKey = Carbon::parse($day->date_val)->format('Y-m-d');
                $net = (int) $day->net;
                $discount = (int) $day->discount;
                $tax = (int) $day->tax;
                $gross = (int) ($grossData[$dateKey] ?? ($net + $discount - $tax));

                $totalGross += $gross; $totalDiscount += $discount; $totalTax += $tax; $totalNet += $net;

                fputcsv($csv, [$dateKey, $day->transactions, $gross, $discount, $tax, $net]);
            }
            fputcsv($csv, []);
            fputcsv($csv, ['TOTAL KESELURUHAN', '', $totalGross, $totalDiscount, $totalTax, $totalNet]);

        }
        elseif ($reportType === 'products') {
            // EKSPOR: KINERJA PRODUK
            $topProducts = DB::table('order_items')
                ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
                ->where('history_transactions.status', 'paid')
                ->whereIn('history_transactions.outlet_id', $allowedOutletIds)
                ->whereBetween('history_transactions.paid_at', [$startDate, $endDate])
                ->selectRaw('products.name, categories.name as category, SUM(order_items.qty) as sold, AVG(order_items.price) as avg_price, SUM(order_items.total_price) as revenue')
                ->groupBy('products.id', 'products.name', 'categories.name')
                ->orderByDesc('sold')->get();

            fputcsv($csv, ['Nama Produk', 'Kategori', 'Qty Terjual', 'Harga Rata-rata', 'Total Pendapatan Kotor (Gross)']);
            foreach ($topProducts as $prod) {
                fputcsv($csv, [$prod->name, $prod->category ?? 'Lainnya', $prod->sold, round($prod->avg_price), $prod->revenue]);
            }
        }
        elseif ($reportType === 'staff') {
            // EKSPOR: KINERJA KASIR
            $staffs = (clone $trxQuery)
                ->leftJoin('users', 'history_transactions.cashier_id', '=', 'users.id')
                ->join('outlets', 'history_transactions.outlet_id', '=', 'outlets.id')
                ->selectRaw('users.name as name, outlets.name as outlet_name, COUNT(history_transactions.id) as transactions, SUM(history_transactions.total_price) as revenue')
                ->groupBy('users.id', 'users.name', 'outlets.name')->orderByDesc('revenue')->get();

            fputcsv($csv, ['Nama Kasir', 'Outlet', 'Transaksi Ditangani', 'Uang Diterima (Bersih)']);
            foreach ($staffs as $staff) {
                fputcsv($csv, [$staff->name ?? 'Terhapus', $staff->outlet_name, $staff->transactions, $staff->revenue]);
            }
        }
        elseif ($reportType === 'shifts') {
            // EKSPOR: KINERJA SHIFT
            $shifts = ShiftKaryawan::whereIn('outlet_id', $allowedOutletIds)
                ->leftJoin('users', 'shift_karyawans.user_id', '=', 'users.id')
                ->whereNotNull('ended_at')
                ->whereBetween('ended_at', [$startDate, $endDate])
                ->selectRaw('users.name as cashier, shift_karyawans.started_at, shift_karyawans.ended_at, opening_balance, closing_balance_system, closing_balance_actual, difference')
                ->orderByDesc('ended_at')->get();

            fputcsv($csv, ['Kasir', 'Waktu Mulai', 'Waktu Selesai', 'Modal Awal', 'Catatan Sistem', 'Uang Fisik (Laci)', 'Selisih (Variance)']);
            foreach ($shifts as $shift) {
                fputcsv($csv, [
                    $shift->cashier ?? 'Tidak Diketahui',
                    $shift->started_at,
                    $shift->ended_at,
                    $shift->opening_balance,
                    $shift->closing_balance_system,
                    $shift->closing_balance_actual,
                    $shift->difference // Minus berarti kurang uang, Plus berarti lebih
                ]);
            }
        }

        // Output File
        rewind($csv);
        $csvContent = stream_get_contents($csv);
        fclose($csv);

        // Jika request format PDF (opsional untuk nanti jika Anda install DOMPDF)
        if ($format === 'pdf') {
            // return \PDF::loadHTML('...')->download($filename . '.pdf');
            // Saat ini fallback ke CSV karena PDF murni butuh package
        }

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
