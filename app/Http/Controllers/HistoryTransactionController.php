<?php

namespace App\Http\Controllers;

use App\Models\HistoryTransaction;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class HistoryTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        // TAMBAHKAN RELASI 'order.items.product' dan 'order.logs' DI SINI
        $query = HistoryTransaction::query()
            ->with([
                'outlet:id,name',
                'cashier:id,name',
                'payment:id,order_id,method,amount_paid,change_amount,paid_at',
                'order:id,table_id,customer_name',
                'order.table:id,name',
                'order.items.product', // Memastikan item terbawa di tampilan list
                'order.logs'           // Memastikan logs terbawa di tampilan list
            ])
            ->latest('paid_at');

        if ($user->isKaryawan()) {
            $query->where('outlet_id', $user->outlet_id);
        } elseif ($user->isManager()) {
            $outletIds = Outlet::query()
                ->where('owner_id', $user->id)
                ->pluck('id');
            $query->whereIn('outlet_id', $outletIds);

            if ($request->filled('outlet_id')) {
                $query->where('outlet_id', $request->integer('outlet_id'));
            }
        } elseif ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->integer('outlet_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('invoice_number')) {
            $query->where('invoice_number', 'like', '%' . $request->string('invoice_number') . '%');
        }

        if ($request->filled('start_date')) {
            $query->whereDate('paid_at', '>=', $request->date('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('paid_at', '<=', $request->date('end_date'));
        }

        // Cari customer_name di HistoryTransaction DAN di Order
        if ($request->filled('customer_name')) {
            $customerName = $request->string('customer_name')->toString();
            $query->where(function ($q) use ($customerName) {
                $q->where('customer_name', 'like', '%' . $customerName . '%')
                  ->orWhereHas('order', function ($orderQuery) use ($customerName) {
                      $orderQuery->where('customer_name', 'like', '%' . $customerName . '%');
                  });
            });
        }

        $limit = max(1, min(100, (int) $request->input('limit', 15)));
        $paginator = $query->paginate($limit);

        // Normalisasi agar Flutter & Vue lebih mudah membaca datanya
        $paginator->getCollection()->transform(function (HistoryTransaction $trx) {
            if (!$trx->customer_name && $trx->relationLoaded('order')) {
                $trx->customer_name = $trx->order?->customer_name;
            }

            // Decode JSON agar Flutter dan Vue menerima format Array
            if (is_string($trx->order_items_summary)) {
                $trx->order_items_summary = json_decode($trx->order_items_summary, true);
            }

            return $trx;
        });

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'History transaction dibuat otomatis saat order lunas',
        ], 405);
    }

    public function show(HistoryTransaction $historyTransaction): JsonResponse
    {
        $user = auth()->user();

        // Cek Izin Manager ke Outlet miliknya
        if ($user->isKaryawan()) {
            if ((int) $historyTransaction->outlet_id !== (int) $user->outlet_id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        } elseif ($user->isManager()) {
            $ownsOutlet = Outlet::where('id', $historyTransaction->outlet_id)
                                ->where('owner_id', $user->id)
                                ->exists();
            if (!$ownsOutlet) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $historyTransaction->load([
            'order.items.product',
            'order.logs',
            'order.table',
            'payment',
            'cashier',
            'outlet'
        ]);

        if (!$historyTransaction->customer_name && $historyTransaction->relationLoaded('order')) {
            $historyTransaction->customer_name = $historyTransaction->order?->customer_name;
        }

        if (is_string($historyTransaction->order_items_summary)) {
            $historyTransaction->order_items_summary = json_decode($historyTransaction->order_items_summary, true);
        }

        return response()->json($historyTransaction);
    }

    public function update(Request $request, HistoryTransaction $historyTransaction): JsonResponse
    {
        return response()->json([
            'message' => 'History transaction tidak bisa diubah',
        ], 405);
    }

    public function destroy(HistoryTransaction $historyTransaction): JsonResponse
    {
        return response()->json([
            'message' => 'History transaction tidak bisa dihapus',
        ], 405);
    }
}
