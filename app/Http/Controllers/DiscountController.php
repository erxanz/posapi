<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function index()
    {
        $today = now();
        $user = auth()->user();

        // Base query (ambil kolom penting saja biar ringan)
        $query = Discount::query()
            ->select([
                'id',
                'name',
                'owner_id',
                'scope',
                'type',
                'value',
                'max_discount',
                'min_purchase',
                'start_date',
                'end_date',
                'is_active',
                'created_at'
            ])
            ->latest();

        // 1. DEVELOPER
        if ($user->role === 'developer') {
            return response()->json(
                $query->limit(50)->get()
            );
        }

        // 2. KARYAWAN / KASIR
        if ($user->role === 'karyawan') {

            // Gunakan relasi (hindari query manual Outlet::find)
            $ownerId = optional($user->outlet)->owner_id;

            if (!$ownerId) {
                return response()->json([]);
            }

            $discounts = $query
                ->where('owner_id', $ownerId)
                ->where('is_active', true)
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->limit(50)
                ->get();

            return response()->json($discounts);
        }

        // 3. MANAGER
        return response()->json(
            $query
                ->where('owner_id', $user->id)
                ->limit(50)
                ->get()
        );
    }

    public function store(Request $request)
    {
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
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'required|boolean'
        ]);

        $data['owner_id'] = auth()->id();

        // Bersihkan data sesuai scope
        $data['product_ids'] = $data['scope'] === 'products' ? $data['product_ids'] : null;
        $data['category_ids'] = $data['scope'] === 'categories' ? $data['category_ids'] : null;

        $discount = Discount::create($data);

        return response()->json([
            'message' => 'Promo berhasil dibuat',
            'data' => $discount
        ], 201);
    }

    public function update(Request $request, Discount $discount)
    {
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
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'required|boolean'
        ]);

        // Bersihkan data sesuai scope
        $data['product_ids'] = $data['scope'] === 'products' ? $data['product_ids'] : null;
        $data['category_ids'] = $data['scope'] === 'categories' ? $data['category_ids'] : null;

        $discount->update($data);

        return response()->json([
            'message' => 'Promo berhasil diupdate',
            'data' => $discount
        ]);
    }

    public function destroy(Discount $discount)
    {
        $discount->delete();

        return response()->json([
            'message' => 'Promo berhasil dihapus'
        ]);
    }
}
