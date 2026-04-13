<?php

namespace App\Http\Controllers;

use App\Models\HistoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class HistoryTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        // TAMBAHKAN 'outlet', 'order.table', dan 'order.items.product' agar terbaca di Frontend
        $query = HistoryTransaction::query()
            ->with(['order.table', 'order.items.product', 'payment', 'cashier', 'outlet'])
            ->latest('paid_at');

        if (!$user->isDeveloper()) {
            $query->where('outlet_id', $user->outlet_id);
        } elseif ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->integer('outlet_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('invoice_number')) {
            $query->where('invoice_number', 'like', '%' . $request->string('invoice_number') . '%');
        }

        // Ambil parameter limit dari frontend, default 15
        $limit = $request->input('limit', 15);

        return response()->json($query->paginate($limit));
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

        return response()->json(
            $historyTransaction->load(['order.items.product', 'payment', 'cashier'])
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
