<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function index() {
        $today = now()->format('Y-m-d');

        // Sebaiknya pindahkan ke scheduler (biar tidak berat)
        Discount::whereDate('end_date', '<', $today)->delete();

        // EAGER LOADING outlet (hindari query tambahan)
        $user = auth()->user()->load('outlet');

        // 1. DEVELOPER
        if ($user->role === 'developer') {
            return response()->json(
                Discount::with([]) // siap kalau nanti ada relasi
                    ->latest()
                    ->get()
            );
        }

        // 2. KARYAWAN / KASIR FLUTTER
        if ($user->role === 'karyawan') {

            // tidak perlu query Outlet lagi
            $outlet = $user->outlet;

            if (!$outlet) {
                return response()->json([]);
            }

            $discounts = Discount::with([]) // bisa tambah relasi nanti
                ->where('owner_id', $outlet->owner_id)
                ->where('is_active', true)
                ->whereDate('start_date', '<=', $today)
                ->latest()
                ->get();

            return response()->json($discounts);
        }

        // 3. MANAGER
        return response()->json(
            Discount::with([])
                ->where('owner_id', $user->id)
                ->latest()
                ->get()
        );
    }

    public function store(Request $request) {
        $user = auth()->user();

        // Membuat promo adalah kewenangan manajerial - karyawan/kasir tidak
        // boleh membuat promo sendiri, konsisten dengan ProductController::store.
        if ($user->role === 'karyawan') {
            return response()->json(['message' => 'Karyawan tidak diizinkan membuat promo'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string',
            'scope' => 'required|in:global,products,categories',
            'product_ids' => 'nullable|array',
            'category_ids' => 'nullable|array',
            'type' => 'required|in:percentage,nominal',
            'value' => 'required|integer',
            'max_discount' => 'nullable|integer',
            'min_purchase' => 'nullable|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'is_active' => 'required|boolean'
        ]);

        // Developer membuat promo atas nama dirinya sendiri kecuali ditentukan
        // lain (mengikuti pola resolveOwnerId di controller lain bila suatu
        // saat developer perlu membuat promo atas nama owner tertentu).
        $data['owner_id'] = $user->id;

        if ($data['scope'] !== 'products') {
            $data['product_ids'] = null;
        }
        if ($data['scope'] !== 'categories') {
            $data['category_ids'] = null;
        }

        $discount = Discount::create($data);

        // lazy loading (kalau nanti ada relasi)
        $discount->load([]);

        return response()->json([
            'message' => 'Promo berhasil dibuat',
            'data' => $discount
        ], 201);
    }

    public function update(Request $request, Discount $discount) {
        $this->authorizeDiscount($discount);

        $data = $request->validate([
            'name' => 'required|string',
            'scope' => 'required|in:global,products,categories',
            'product_ids' => 'nullable|array',
            'category_ids' => 'nullable|array',
            'type' => 'required|in:percentage,nominal',
            'value' => 'required|integer',
            'max_discount' => 'nullable|integer',
            'min_purchase' => 'nullable|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'is_active' => 'required|boolean'
        ]);

        if ($data['scope'] !== 'products') {
            $data['product_ids'] = null;
        }
        if ($data['scope'] !== 'categories') {
            $data['category_ids'] = null;
        }

        $discount->update($data);

        // lazy loading setelah update
        $discount->load([]);

        return response()->json([
            'message' => 'Promo berhasil diupdate',
            'data' => $discount
        ]);
    }

    public function destroy(Discount $discount) {
        $this->authorizeDiscount($discount);

        $discount->delete();

        return response()->json([
            'message' => 'Promo berhasil dihapus'
        ]);
    }

    /**
     * Pastikan promo yang diakses memang milik owner yang sesuai dengan
     * user yang login. Tanpa ini siapa pun yang authenticated bisa
     * update/hapus promo milik tenant manapun hanya dengan menebak ID.
     */
    private function authorizeDiscount(Discount $discount): void
    {
        $user = auth()->user();

        if ($user->role === 'developer') {
            return;
        }

        $ownerId = $user->role === 'manager'
            ? $user->id
            : ($user->outlet_id ? \App\Models\Outlet::query()->whereKey($user->outlet_id)->value('owner_id') : null);

        if (!$ownerId || (int) $discount->owner_id !== (int) $ownerId) {
            abort(403, 'Forbidden');
        }
    }
}
