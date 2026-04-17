<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\HistoryTransaction;
use App\Models\Order;
use Carbon\Carbon;


class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $dateFrom = $request->date_from ? Carbon::parse($request->date_from) : Carbon::today()->subDays(7);
        $dateTo = $request->date_end ? Carbon::parse($request->date_end) : Carbon::today();
        $outletId = $request->outlet_id ?? $user->outlet_id;

        // Base query for paid history transactions
        $historyQuery = HistoryTransaction::where('status', 'paid')
            ->whereBetween('paid_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if (!$user->isDeveloper()) {
            $historyQuery->where('outlet_id', $outletId);
        } elseif ($outletId) {
            $historyQuery->where('outlet_id', $outletId);
        }

        $financial = $this->getFinancialStats($historyQuery->clone(), $dateFrom, $dateTo, $outletId ?? $user->outlet_id);
        $menuStats = $this->getMenuStats($historyQuery->clone(), $dateFrom, $dateTo, $outletId ?? $user->outlet_id);
        $opStats = $this->getOperationalStats($historyQuery->clone(), $dateFrom, $dateTo, $outletId ?? $user->outlet_id);
        $cashierStats = $this->getCashierStats($historyQuery->clone(), $dateFrom, $dateTo, $outletId ?? $user->outlet_id);

        // Charts data (common)
        $revenueChart = $this->getRevenueChart($historyQuery->clone(), $dateFrom, $dateTo);
        $paymentChart = $this->getPaymentChart($financial['payments']);
        $hourlyChart = $this->getHourlyChart($historyQuery->clone(), $dateFrom, $dateTo);

        return response()->json([
            'financial' => $financial,
            'menuStats' => $menuStats,
            'opStats' => $opStats,
            'cashierStats' => $cashierStats,
            'revenueChartData' => $revenueChart,
            'paymentChartData' => $paymentChart,
            'hourlyChartData' => $hourlyChart,
            'filters' => [
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ]
        ]);
    }

    private function getFinancialStats($historyQuery, $dateFrom, $dateTo, $outletId)
    {
        $paidStats = $historyQuery->selectRaw('SUM(total_price) as gross_sales, SUM(discount_amount) as total_discount')
            ->first();

        // Void/lost from cancelled orders
        $voidStats = Order::where('status', Order::STATUS_CANCELLED)
            ->where('outlet_id', $outletId)
            ->whereBetween('created_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->selectRaw('SUM(total_price) as total_void')
            ->first();

        $grossSales = (int) ($paidStats->gross_sales ?? 0);
        $totalDiscount = (int) ($paidStats->total_discount ?? 0);
        $totalVoid = (int) ($voidStats->total_void ?? 0);
        $netSales = $grossSales - $totalDiscount - $totalVoid;

        // Gross profit: revenue - cost of goods sold (COGS)
        $cogs = DB::table('order_items')
            ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('history_transactions.status', 'paid')
            ->where('history_transactions.outlet_id', $outletId)
            ->whereBetween('history_transactions.paid_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->sum(DB::raw('order_items.qty * products.cost_price'));

        $grossProfit = $netSales - (int) $cogs;

        // Payment methods
        $payments = $historyQuery->selectRaw('payment_method, SUM(paid_amount) as amount')
            ->groupBy('payment_method')
            ->havingRaw('amount > 0')
            ->orderByDesc('amount')
            ->get()
            ->map(fn($p) => [
                'method' => ucwords(str_replace('_', ' ', $p->payment_method ?? 'unknown')),
                'amount' => (int) $p->amount,
                'percentage' => round((($p->amount / $grossSales) * 100), 0) . '%'
            ])->toArray();

        return [
            'gross_sales' => $grossSales,
            'total_discount' => $totalDiscount,
            'total_void' => $totalVoid,
            'net_sales' => max(0, $netSales),
            'gross_profit' => max(0, $grossProfit),
            'payments' => $payments,
        ];
    }

    private function getMenuStats($historyQuery, $dateFrom, $dateTo, $outletId)
    {
        // Top revenue products
        $topProducts = DB::table('order_items')
            ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('history_transactions.status', 'paid')
            ->where('history_transactions.outlet_id', $outletId)
            ->whereBetween('history_transactions.paid_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->selectRaw('products.name, SUM(order_items.qty) as qty, SUM(order_items.total_price) as revenue')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn($p) => [
                'name' => $p->name,
                'qty' => (int) $p->qty,
                'revenue' => (int) $p->revenue,
            ])->toArray();

        // Slow moving: low qty, but revenue >0
        $slowProducts = DB::table('order_items')
            ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('history_transactions.status', 'paid')
            ->where('history_transactions.outlet_id', $outletId)
            ->whereBetween('history_transactions.paid_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->selectRaw('products.name, SUM(order_items.qty) as qty')
            ->groupBy('products.id', 'products.name')
            ->havingRaw('qty <= 5')  // Threshold low qty
            ->orderBy('qty')
            ->limit(10)
            ->get()
            ->map(fn($p) => [
                'name' => $p->name,
                'qty' => (int) $p->qty,
            ])->toArray();

        // Margins: high profit per item (revenue - cost), top 5
        $margins = DB::table('order_items')
            ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('history_transactions.status', 'paid')
            ->where('history_transactions.outlet_id', $outletId)
            ->whereBetween('history_transactions.paid_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->selectRaw('
                products.name,
                SUM(order_items.qty * products.cost_price) as total_cost,
                SUM(order_items.total_price) as total_revenue,
                AVG((order_items.price - products.cost_price)) as avg_profit_per_unit
            ')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('avg_profit_per_unit')
            ->limit(5)
            ->get()
            ->map(fn($p) => [
                'name' => $p->name,
                'cost' => (int) $p->total_cost,
                'profit' => (int) ($p->total_revenue - $p->total_cost),
            ])->toArray();

        // Categories performance
        $categories = DB::table('order_items')
            ->join('history_transactions', 'order_items.order_id', '=', 'history_transactions.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->where('history_transactions.status', 'paid')
            ->where('history_transactions.outlet_id', $outletId)
            ->whereBetween('history_transactions.paid_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->selectRaw('categories.name, SUM(order_items.total_price) as revenue')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn($c) => [
                'name' => $c->name,
                'revenue' => (int) $c->revenue,
                'percentage' => '0%',  // Calculate total revenue first or approx
            ])->toArray();

        // TODO: Calculate actual % from total

        return [
            'top' => $topProducts,
            'slow' => $slowProducts,
            'margins' => $margins,
            'categories' => $categories,
        ];
    }

    private function getOperationalStats($historyQuery, $dateFrom, $dateTo, $outletId)
    {
        $totalTrx = $historyQuery->count();

        $aov = $totalTrx > 0 ? (int) ($historyQuery->sum('total_price') / $totalTrx) : 0;

        // Dine-in vs Takeaway: assume table_id present = dine-in
        $dineIn = Order::whereHas('historyTransaction', fn($q) => $q->whereBetween('paid_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]))
            ->whereNotNull('table_id')
            ->where('outlet_id', $outletId)
            ->count();

        $takeaway = Order::whereHas('historyTransaction', fn($q) => $q->whereBetween('paid_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]))
            ->whereNull('table_id')
            ->where('outlet_id', $outletId)
            ->count();

        $totalOrders = $dineIn + $takeaway;
        $dineInPct = $totalOrders > 0 ? round(($dineIn / $totalOrders) * 100) : 0;
        $takeawayPct = 100 - $dineInPct;

        // Avg processing time: paid_at - created_at avg minutes (approx)
        $avgTime = DB::table('orders')
            ->join('history_transactions', 'orders.id', '=', 'history_transactions.order_id')
            ->where('history_transactions.status', 'paid')
            ->where('orders.outlet_id', $outletId)
            ->whereBetween('history_transactions.paid_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, orders.created_at, history_transactions.paid_at)) as avg_min')
            ->value('avg_min') ?? 0;

        // Void reasons: parse from order logs (if reason in logs)
        $voidReasons = Order::where('status', Order::STATUS_CANCELLED)
            ->where('outlet_id', $outletId)
            ->whereBetween('created_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->get()
            ->map(fn($o) => $o->logs ? data_get($o->logs, '*.reason', 'Unknown') : 'Unknown')
            ->groupBy(fn($r) => $r)
            ->map(fn($group, $reason) => [
                'reason' => $reason,
                'count' => $group->count(),
                'pct' => 0,  // calc %
                'lost' => 0, // total_price
            ])->values()->toArray();

        return [
            'total_trx' => $totalTrx,
            'aov' => $aov,
            'avg_time' => round($avgTime, 0),
            'dine_in' => $dineInPct,
            'takeaway' => $takeawayPct,
            'void_reasons' => $voidReasons,
        ];
    }

    private function getCashierStats($historyQuery, $dateFrom, $dateTo, $outletId)
    {
        return $historyQuery->selectRaw('
                u.name,
                COUNT(*) as trx,
                SUM(h.total_price) as processed
            ')
            ->join('users as u', 'h.cashier_id', '=', 'u.id')
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('processed')
            ->get()
            ->map(fn($c) => [
                'name' => $c->name,
                'trx' => (int) $c->trx,
                'processed' => (int) $c->processed,
            ])->toArray();
    }

    private function getRevenueChart($historyQuery, $dateFrom, $dateTo)
    {
        // Daily revenue last 7 days
        $daily = $historyQuery->clone()
            ->selectRaw('DATE(paid_at) as date, SUM(total_price) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $labels = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];  // Adjust based on data
        // Map daily data to week labels
        $today = Carbon::today();
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $dateStr = $today->copy()->subDays($i)->format('Y-m-d');
            $dayData = $daily->firstWhere('date', $dateStr);
            $data[] = $dayData ? (int) $dayData->revenue : 0;
        }

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Net Sales',
                'data' => $data,
                'borderColor' => '#2E7DD6',
                'backgroundColor' => 'rgba(46, 125, 214, 0.1)',
                'borderWidth' => 3,
                'fill' => true,
                'tension' => 0.4,
            ]]
        ];
    }

    private function getPaymentChart(array $payments)
    {
        return [
            'labels' => array_column($payments, 'method'),
            'datasets' => [[
                'data' => array_column($payments, 'amount'),
                'backgroundColor' => ['#1B4F8A', '#2A7A4B', '#F59E0B', '#8AAFCC'],
            ]]
        ];
    }

    private function getHourlyChart($historyQuery, $dateFrom, $dateTo)
    {
        $hourly = $historyQuery->selectRaw('
                HOUR(paid_at) as hour,
                COUNT(*) as trx
            ')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $labels = [];
        $data = [];
        for ($h = 10; $h <= 22; $h += 2) {
            $labels[] = sprintf('%02d:00', $h);
            $data[] = $hourly->where('hour', $h)->first()?->trx ?? 0;
        }

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Jumlah Transaksi',
                'data' => $data,
                'backgroundColor' => '#8AAFCC',
            ]]
        ];
    }
}
?>

