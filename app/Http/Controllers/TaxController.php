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
                // PERBAIKAN: Gunakan 'owner_id', BUKAN 'manager_id'
                $query->whereHas('outlet', function ($q) use ($user) {
                    $q->where('owner_id', $user->id);
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
        $user = auth()->user();

        // Mengatur pajak/biaya adalah kewenangan manajerial.
        if ($user->role === 'karyawan') {
            return response()->json(['message' => 'Karyawan tidak diizinkan mengatur pajak/biaya'], 403);
        }

        $this->authorizeOutletId($request->outlet_id);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                // PERBAIKAN: Validasi agar nama tidak duplikat di satu cabang
                Rule::unique('taxes')->where(function ($query) use ($request) {
                    return $query->where('outlet_id', $request->outlet_id);
                })
            ],
            // PERBAIKAN: Hapus max:100 agar bisa memasukkan nominal seperti Rp 5000
            'rate' => ['required', 'numeric', 'min:0'],
            'type' => ['required', 'in:percentage,fixed'],
            'outlet_id' => ['required', 'exists:outlets,id'],
            'active' => ['boolean'],
        ], [
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
        $this->authorizeOutletId($tax->outlet_id);

        $tax->load('outlet');
        return response()->json($tax);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Tax $tax)
    {
        $user = auth()->user();

        if ($user->role === 'karyawan') {
            return response()->json(['message' => 'Karyawan tidak diizinkan mengatur pajak/biaya'], 403);
        }

        // Cek otorisasi outlet saat ini DAN outlet tujuan (kalau diubah),
        // mencegah pajak "dipindah" ke/dari outlet yang bukan miliknya.
        $this->authorizeOutletId($tax->outlet_id);
        if ($request->filled('outlet_id')) {
            $this->authorizeOutletId($request->outlet_id);
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                // PERBAIKAN: Abaikan validasi unique jika nama tidak diubah
                Rule::unique('taxes')->where(function ($query) use ($request, $tax) {
                    return $query->where('outlet_id', $request->outlet_id ?? $tax->outlet_id);
                })->ignore($tax->id)
            ],
            'rate' => ['sometimes', 'numeric', 'min:0'],
            'type' => ['sometimes', 'in:percentage,fixed'],
            'outlet_id' => ['sometimes', 'required', 'exists:outlets,id'],
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
        $user = auth()->user();

        if ($user->role === 'karyawan') {
            return response()->json(['message' => 'Karyawan tidak diizinkan mengatur pajak/biaya'], 403);
        }

        $this->authorizeOutletId($tax->outlet_id);

        $tax->delete();
        return response()->json(null, 204);
    }

    /**
     * Pastikan user yang login berhak mengakses outlet ini (developer =
     * semua, manager = outlet miliknya via owner_id, karyawan = outlet
     * tempatnya bekerja). Mengikuti pola yang sama dengan controller lain
     * (OutletController::authorizeOutlet, TableController::authorizeOutletId).
     */
    private function authorizeOutletId(?int $outletId): void
    {
        $user = auth()->user();

        if ($user->role === 'developer') {
            return;
        }

        if ($user->role === 'karyawan') {
            if ((int) $user->outlet_id !== (int) $outletId) {
                abort(403, 'Forbidden');
            }
            return;
        }

        if ($user->role === 'manager') {
            $ownsOutlet = \App\Models\Outlet::where('id', $outletId)->where('owner_id', $user->id)->exists();
            if (!$ownsOutlet) {
                abort(403, 'Forbidden');
            }
            return;
        }

        abort(403, 'Forbidden');
    }
}
