<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function index() {
        $user = auth()->user();

        // Developer bisa melihat semua
        if ($user->role === 'developer') {
            return response()->json(Discount::latest()->get());
        }

        // Manager hanya melihat promo milik cabangnya sendiri
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

        // PERBAIKAN: Otomatis assign owner_id (Manager yang sedang login)
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
