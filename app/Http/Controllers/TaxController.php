<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();

        $taxes = Tax::with('outlet')
            ->when($user->role === 'manager', function ($query) use ($user) {
                // Tampilkan tax hanya dari outlet milik manager tersebut
                $query->whereHas('outlet', function ($q) use ($user) {
                    $q->where('user_id', $user->id); // Sesuaikan dengan kolom foreign key di tabel outlets
                });
            })
            ->when($user->role === 'karyawan', function ($query) use ($user) {
                // Karyawan hanya bisa melihat tax di outlet tempat dia bekerja
                $query->where('outlet_id', $user->outlet_id);
            })
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
            'name' => [
                'required',
                'string',
                'max:255',
                // Validasi agar nama tidak boleh sama di dalam outlet_id yang sama
                Rule::unique('taxes')->where(function ($query) use ($request) {
                    return $query->where('outlet_id', $request->outlet_id);
                })
            ],
            // Hapus max:100 agar bisa menampung tipe fixed nominal besar (misal Rp 5000)
            'rate' => ['required', 'numeric', 'min:0'],
            'type' => ['required', 'in:percentage,fixed'],
            'outlet_id' => ['nullable', 'exists:outlets,id'],
            'active' => ['boolean'],
        ], [
            // Pesan error kustom jika duplikat
            'name.unique' => 'Pajak / Biaya dengan nama ini sudah ada di cabang tersebut.'
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
            'name' => [
                'sometimes',
                'string',
                'max:255',
                // Validasi unique dengan mengecualikan ID tax yang sedang diedit
                Rule::unique('taxes')->where(function ($query) use ($request, $tax) {
                    return $query->where('outlet_id', $request->outlet_id ?? $tax->outlet_id);
                })->ignore($tax->id)
            ],
            'rate' => ['sometimes', 'numeric', 'min:0'],
            'type' => ['sometimes', 'in:percentage,fixed'],
            'outlet_id' => ['sometimes', 'nullable', 'exists:outlets,id'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => 'Pajak / Biaya dengan nama ini sudah ada di cabang tersebut.'
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
