<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\Outlet;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function index() {
        // Auto-delete promo kedaluwarsa secara realtime
        $today = now()->format('Y-m-d');
        Discount::whereDate('end_date', '<', $today)->delete();

        $user = auth()->user();

        // 1. DEVELOPER
        if ($user->role === 'developer') {
            return response()->json(Discount::latest()->get());
        }

        // 2. KARYAWAN / KASIR FLUTTER
        if ($user->role === 'karyawan') {
            $outlet = Outlet::find($user->outlet_id);

            if (!$outlet) {
                return response()->json([]);
            }

            $discounts = Discount::where('owner_id', $outlet->owner_id)
                ->where('is_active', true)
                ->whereDate('start_date', '<=', $today)
                ->latest()
                ->get();

            return response()->json($discounts);
        }

        // 3. MANAGER
        return response()->json(Discount::where('owner_id', $user->id)->latest()->get());
    }

    public function store(Request $request) {
        $data = $request->validate([
            'name' => 'required|string',
            'scope' => 'required|in:global,products,categories',
            'product_ids' => 'nullable|array',    // <-- Mengizinkan array masuk
            'category_ids' => 'nullable|array',   // <-- Mengizinkan array masuk
            'type' => 'required|in:percentage,nominal',
            'value' => 'required|integer',
            'max_discount' => 'nullable|integer',
            'min_purchase' => 'nullable|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'is_active' => 'required|boolean'
        ]);

        $data['owner_id'] = auth()->id();

        // Bersihkan array jika scope tidak sesuai (agar DB bersih)
        if ($data['scope'] !== 'products') {
            $data['product_ids'] = null;
        }
        if ($data['scope'] !== 'categories') {
            $data['category_ids'] = null;
        }

        $discount = Discount::create($data);
        return response()->json(['message' => 'Promo berhasil dibuat', 'data' => $discount], 201);
    }

    public function update(Request $request, Discount $discount) {
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
        return response()->json(['message' => 'Promo berhasil diupdate', 'data' => $discount]);
    }

    public function destroy(Discount $discount) {
        $discount->delete();
        return response()->json(['message' => 'Promo berhasil dihapus']);
    }
}
