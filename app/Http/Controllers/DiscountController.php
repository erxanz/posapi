<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index() {
        return response()->json(Discount::latest()->get());
    }

    /**
     * Store a newly created resource in storage.
     */
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
        return Discount::create($data);
    }

    /**
     * Display the specified resource.
     */
    public function show(Discount $discount)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Discount $discount) {
        $discount->update($request->all());
        return $discount;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Discount $discount) {
        $discount->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
