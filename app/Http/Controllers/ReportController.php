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

        // Tangkap filter dari Vue (start_date dan end_date)
        $startDate = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : Carbon::today()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date)->endOfDay() : Carbon::today()->endOfDay();
        $outletId = $request->outlet_id;

        // 1. Query Dasar Transaksi Selesai (Paid)
        $query = HistoryTransaction::where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate]);

        // 2. Filter Berdasarkan Role (Konsep Multi-Cabang)
        if ($user->role === 'karyawan') {
            $query->where('outlet_id', $user->outlet_id);
        } elseif ($user->role === 'manager') {
            $managerOutlets = Outlet::where('owner_id', $user->id)->pluck('id');
            if ($outletId && $managerOutlets->contains($outletId)) {
                $query->where('outlet_id', $outletId);
            } else {
                $query->whereIn('outlet_id', $managerOutlets);
            }
        } else {
            // Developer
            if ($outletId) {
                $query->where('outlet_id', $outletId);
            }
        }

        // --- A. SUMMARY ---
        $summaryData = (clone $query)->selectRaw('COUNT(id) as total_trx, SUM(total_price) as total_revenue')->first();
        $revenue = (int) ($summaryData->total_revenue ?? 0);
        $transactions = (int) ($summaryData->total_trx ?? 0);
        $avgOrder = $transactions > 0 ? (int) ($revenue / $transactions) : 0;

        $itemsSold = (int) DB::table('order_items')
            ->whereIn('order_id', (clone $query)->select('order_id'))
            ->sum('qty');

        // --- B. REVENUE CHART (TREN PENDAPATAN) ---
        $revenueChart = (clone $query)
            ->selectRaw('DATE(paid_at) as date, SUM(total_price) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($item) => [
                'date' => $item->date,
                'revenue' => (int) $item->revenue
            ]);

        // --- C. TOP PRODUCTS (PRODUK TERLARIS) ---
        $topProducts = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereIn('order_items.order_id', (clone $query)->select('order_id'))
            ->selectRaw('products.name, SUM(order_items.qty) as sold, SUM(order_items.total_price) as revenue')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('sold')
            ->limit(10)
            ->get()
            ->map(fn($item) => [
                'name' => $item->name,
                'sold' => (int) $item->sold,
                'revenue' => (int) $item->revenue
            ]);

        // --- D. PAYMENT METHODS (METODE PEMBAYARAN) ---
        $paymentMethods = (clone $query)
            ->selectRaw('payment_method as method, SUM(total_price) as total')
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(fn($item) => [
                'method' => $item->method ?? 'Unknown',
                'total' => (int) $item->total
            ]);

        // --- E. OUTLET PERFORMANCE (KINERJA CABANG) ---
        $outletPerformance = (clone $query)
            ->join('outlets', 'history_transactions.outlet_id', '=', 'outlets.id')
            ->selectRaw('outlets.name, SUM(history_transactions.total_price) as revenue, COUNT(history_transactions.id) as transactions')
            ->groupBy('outlets.id', 'outlets.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn($item) => [
                'name' => $item->name,
                'revenue' => (int) $item->revenue,
                'transactions' => (int) $item->transactions
            ]);

        // Return JSON sesuai format yang diharapkan oleh Vue
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
        // Placeholder untuk fitur export.
        // Nanti kamu bisa tambahkan library Maatwebsite/Excel atau DomPDF di sini.
        return response()->json(['message' => 'Fitur export sedang dalam tahap pengembangan.'], 501);
    }
}
