<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();
        $taxes = Tax::with('outlet')
            ->when($user->role !== 'owner', fn($query) => $query->whereHas('outlet', fn($q) => $q->where('manager_id', $user->id)))
            ->orderBy('active', 'desc')
            ->orderBy('name')
            ->get();
        return response()->json($taxes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'type' => ['required', 'in:percentage,fixed'],
            'outlet_id' => ['nullable', 'exists:outlets,id'],
            'active' => ['boolean'],
        ]);

        $tax = Tax::create($validated);
        $tax->load('outlet');
        return response()->json($tax, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Tax $tax)
    {
        $tax->load('outlet');
        return response()->json($tax);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Tax $tax)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'type' => ['sometimes', 'in:percentage,fixed'],
            'outlet_id' => ['sometimes', 'nullable', 'exists:outlets,id'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $tax->update($validated);
        $tax->load('outlet');
        return response()->json($tax);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tax $tax)
    {
        $tax->delete();
        return response()->json(null, 204);
    }
}
