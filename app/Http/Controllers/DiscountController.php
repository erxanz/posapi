<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\Outlet;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function index() {
        $user = auth()->user();

        // 1. DEVELOPER (Bisa melihat semua diskon)
        if ($user->role === 'developer') {
            return response()->json(Discount::latest()->get());
        }

        // 2. KARYAWAN / KASIR FLUTTER (Hanya melihat diskon yang AKTIF milik Manager-nya)
        if ($user->role === 'karyawan') {
            // Cari outlet tempat karyawan ini bekerja
            $outlet = Outlet::find($user->outlet_id);

            if (!$outlet) {
                return response()->json([]);
            }

            // Ambil diskon buatan manager (owner_id) yang aktif dan tanggalnya valid
            $discounts = Discount::where('owner_id', $outlet->owner_id)
                ->where('is_active', true)
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->latest()
                ->get();

            return response()->json($discounts);
        }

        // 3. MANAGER (Melihat semua diskon yang ia buat, aktif maupun tidak)
        return response()->json(Discount::where('owner_id', $user->id)->latest()->get());
    }

    public function store(Request $request) {
        $data = $request->validate([
            'name' => 'required|string',
            'type' => 'required|in:percentage,nominal',
            'value' => 'required|integer',
            'min_purchase' => 'nullable|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'is_active' => 'required|boolean'
        ]);

        $data['owner_id'] = auth()->id();

        $discount = Discount::create($data);
        return response()->json(['message' => 'Promo berhasil dibuat', 'data' => $discount], 201);
    }

    public function update(Request $request, Discount $discount) {
        $data = $request->validate([
            'name' => 'required|string',
            'type' => 'required|in:percentage,nominal',
            'value' => 'required|integer',
            'min_purchase' => 'nullable|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'is_active' => 'required|boolean'
        ]);

        $discount->update($data);
        return response()->json(['message' => 'Promo berhasil diupdate', 'data' => $discount]);
    }

    public function destroy(Discount $discount) {
        $discount->delete();
        return response()->json(['message' => 'Promo berhasil dihapus']);
    }
}
