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

        // Gunakan eager load ringan untuk list agar respons Flutter lebih cepat.
        // Detail item produk tetap di endpoint show.
        $query = HistoryTransaction::query()
            ->with([
                'outlet:id,name',
                'cashier:id,name',
                'payment:id,order_id,method,amount_paid,change_amount,paid_at',
                'order:id,table_id,customer_name',
                'order.table:id,name',
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

        if ($request->filled('customer_name')) {
            $customerName = $request->string('customer_name')->toString();
            $query->whereHas('order', function ($orderQuery) use ($customerName) {
                $orderQuery->where('customer_name', 'like', '%' . $customerName . '%');
            });
        }

        // Batasi limit supaya payload tetap aman untuk mobile.
        $limit = max(1, min(100, (int) $request->input('limit', 15)));

        $paginator = $query->paginate($limit);

        // Normalisasi agar Flutter tidak perlu fallback ke order.customer_name.
        $paginator->getCollection()->transform(function (HistoryTransaction $trx) {
            if (!$trx->customer_name && $trx->relationLoaded('order')) {
                $trx->customer_name = $trx->order?->customer_name;
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

        if (!$user->isDeveloper() && (int) $historyTransaction->outlet_id !== (int) $user->outlet_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

         // PERBAIKAN: Tambahkan 'order.table' dan 'outlet' agar Flutter tidak menerima nilai null
        return response()->json(
            $historyTransaction->load([
                'order.items.product',
                'order.table',
                'payment',
                'cashier',
                'outlet'
            ])
        );
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
