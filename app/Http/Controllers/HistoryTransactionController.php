<?php

namespace App\Http\Controllers;

use App\Models\HistoryTransaction;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class HistoryTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        // Gunakan relasi lengkap agar kompatibel dengan kontrak response Flutter lama.
        $query = HistoryTransaction::query()
            ->with([
                'outlet:id,name',
                'cashier:id,name',
                'payment:id,order_id,method,amount_paid,change_amount,paid_at',
                // PERBAIKAN: Tambahkan 'logs' ke sini agar data log tidak terpotong (di-select oleh SQL)
                'order:id,table_id,customer_name,logs',
                'order.table:id,name',
                'order.items.product',
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
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('invoice_number')) {
            $invoice = trim((string) $request->input('invoice_number'));
            $query->where('invoice_number', 'like', '%' . $invoice . '%');
        }

        if ($request->filled('start_date')) {
            try {
                $startDate = Carbon::parse((string) $request->input('start_date'))->toDateString();
                $query->whereDate('paid_at', '>=', $startDate);
            } catch (\Throwable $e) {
                // Ignore invalid date input to keep list endpoint stable for mobile clients.
            }
        }

        if ($request->filled('end_date')) {
            try {
                $endDate = Carbon::parse((string) $request->input('end_date'))->toDateString();
                $query->whereDate('paid_at', '<=', $endDate);
            } catch (\Throwable $e) {
                // Ignore invalid date input to keep list endpoint stable for mobile clients.
            }
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
        $forbiddenResponse = $this->authorizeHistoryTransactionAccess($historyTransaction);
        if ($forbiddenResponse instanceof JsonResponse) {
            return $forbiddenResponse;
        }

         // Memuat relasi lengkap termasuk order agar logs juga ikut terbawa
        return response()->json(
            $historyTransaction->load([
                'order', // Memastikan kolom logs pada tabel order termuat utuh
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
        $forbiddenResponse = $this->authorizeHistoryTransactionAccess($historyTransaction);
        if ($forbiddenResponse instanceof JsonResponse) {
            return $forbiddenResponse;
        }

        $validated = $request->validate([
            'invoice_number' => 'sometimes|nullable|string|max:255',
            'customer_name' => 'sometimes|nullable|string|max:255',
            'payment_method' => 'sometimes|nullable|string|max:50',
            'paid_at' => 'sometimes|nullable|date',
            'status' => 'sometimes|required|in:paid,cancelled',
            'subtotal_price' => 'sometimes|required|integer|min:0',
            'discount_amount' => 'sometimes|required|integer|min:0',
            'tax_amount' => 'sometimes|nullable|integer|min:0', // tetap divalidasi (opsional)
            'total_price' => 'sometimes|required|integer|min:0',
            'paid_amount' => 'sometimes|required|integer|min:0',
            'change_amount' => 'sometimes|required|integer|min:0',
            'metadata' => 'sometimes|nullable|array',
            'order_items_summary' => 'sometimes|nullable|array',
        ]);

        // KUNCI UTAMA: HAPUS tax_amount AGAR TIDAK PERNAH DIUPDATE
        unset($validated['tax_amount']);

        $historyTransaction->update($validated);

        return response()->json([
            'message' => 'History transaction berhasil diupdate',
            'data' => $historyTransaction->fresh()->load([
                'order',
                'order.items.product',
                'order.table',
                'payment',
                'cashier',
                'outlet',
            ]),
        ]);
    }

    public function destroy(HistoryTransaction $historyTransaction): JsonResponse
    {
        return response()->json([
            'message' => 'History transaction tidak bisa dihapus',
        ], 405);
    }

    private function authorizeHistoryTransactionAccess(HistoryTransaction $historyTransaction): ?JsonResponse
    {
        $user = auth()->user();

        if ($user->isKaryawan()) {
            if ((int) $historyTransaction->outlet_id !== (int) $user->outlet_id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return null;
        }

        if ($user->isManager()) {
            $allowedOutletIds = Outlet::query()
                ->where('owner_id', $user->id)
                ->pluck('id');

            if (!$allowedOutletIds->contains((int) $historyTransaction->outlet_id)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return null;
        }

        if (!$user->isDeveloper()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }
}
