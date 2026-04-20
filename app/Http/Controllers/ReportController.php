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

        // 2. Query Utama Transaksi 'Paid'
        $trxQuery = HistoryTransaction::where('status', 'paid')
            ->whereIn('outlet_id', $allowedOutletIds)
            ->whereBetween('paid_at', [$startDate, $endDate]);

        // --- A. SUMMARY & KPI ---
        $summaryData = (clone $trxQuery)->selectRaw('
            COUNT(id) as total_trx,
            SUM(total_price) as total_revenue,
            SUM(discount_amount) as total_discount,
            SUM(tax_amount) as total_tax
        ')->first();

        $revenue = (int) ($summaryData->total_revenue ?? 0);
        $transactions = (int) ($summaryData->total_trx ?? 0);
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
            ->groupBy('date')
            ->orderByDesc('date') // Descending agar tanggal terbaru di atas (untuk tabel)
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
        ]);

        // --- C. TOP PRODUCTS (Tab 3) ---
        // Menggunakan order_items yang sukses dibayar
        $topProducts = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->whereIn('order_items.order_id', (clone $trxQuery)->select('order_id'))
            ->selectRaw('
                products.name,
                categories.name as category,
                SUM(order_items.qty) as sold,
                SUM(order_items.total_price) as revenue
            ')
            ->groupBy('products.id', 'products.name', 'categories.name')
            ->orderByDesc('sold')
            ->limit(100) // Dibatasi 100 produk teratas untuk keamanan memori
            ->get()
            ->map(fn($item) => [
                'name' => $item->name,
                'category' => $item->category,
                'sold' => (int) $item->sold,
                'revenue' => (int) $item->revenue
            ]);

        // --- D. CASHIER PERFORMANCE (Tab 4) ---
        $cashierPerformance = (clone $trxQuery)
            ->join('users', 'history_transactions.cashier_id', '=', 'users.id')
            ->join('outlets', 'history_transactions.outlet_id', '=', 'outlets.id')
            ->selectRaw('
                users.name as name,
                outlets.name as outlet_name,
                COUNT(history_transactions.id) as transactions,
                SUM(history_transactions.total_price) as revenue
            ')
            ->groupBy('users.id', 'users.name', 'outlets.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn($item) => [
                'name' => $item->name,
                'outlet_name' => $item->outlet_name,
                'transactions' => (int) $item->transactions,
                'revenue' => (int) $item->revenue
            ]);

        // --- E. PAYMENT METHODS (Ringkasan Bawah) ---
        $paymentMethods = (clone $trxQuery)
            ->selectRaw('payment_method as method, SUM(total_price) as total, COUNT(id) as count')
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(fn($item) => [
                'method' => $item->method ?? 'Lainnya',
                'total' => (int) $item->total,
                'count' => (int) $item->count
            ]);

        return response()->json([
            'summary' => [
                'revenue' => $revenue,
                'transactions' => $transactions,
                'avg_order' => $avgOrder,
                'items_sold' => $itemsSold,
                'total_discount' => (int) ($summaryData->total_discount ?? 0),
                'total_tax' => (int) ($summaryData->total_tax ?? 0),
            ],
            'revenue_chart' => $revenueChart,
            'sales_report' => $salesReport,
            'top_products' => $topProducts,
            'cashier_performance' => $cashierPerformance,
            'payment_methods' => $paymentMethods
        ]);
    }

    public function export(Request $request)
    {
        return response()->json(['message' => 'Fitur export sedang disiapkan.'], 501);
    }
}
