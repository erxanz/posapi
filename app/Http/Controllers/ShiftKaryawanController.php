<?php

namespace App\Http\Controllers;

use App\Models\ShiftKaryawan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShiftKaryawanController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(
            ShiftKaryawan::with(['outlet', 'user'])
                ->latest()
                ->paginate(10)
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => ['nullable', 'exists:outlets,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'shift_ke' => ['required', 'integer', Rule::in([1, 2])],
            'uang_awal' => ['required', 'integer', 'min:0'],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'closed'])],
        ]);

        $validated['outlet_id'] = $validated['outlet_id'] ?? $request->user()?->outlet_id;
        $validated['user_id'] = $validated['user_id'] ?? $request->user()?->id;
        $validated['status'] = $validated['status'] ?? 'draft';

        if ($validated['status'] === 'active' && empty($validated['started_at'])) {
            $validated['started_at'] = now();
        }

        $shiftKaryawan = ShiftKaryawan::create($validated);

        return response()->json([
            'message' => 'Shift karyawan berhasil dibuat',
            'data' => $shiftKaryawan->load(['outlet', 'user']),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(ShiftKaryawan $shiftKaryawan)
    {
        return response()->json(
            $shiftKaryawan->load(['outlet', 'user'])
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ShiftKaryawan $shiftKaryawan)
    {
        $validated = $request->validate([
            'outlet_id' => ['nullable', 'exists:outlets,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'shift_ke' => ['sometimes', 'required', 'integer', Rule::in([1, 2])],
            'uang_awal' => ['sometimes', 'required', 'integer', 'min:0'],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'closed'])],
        ]);

        if (array_key_exists('status', $validated) && $validated['status'] === 'active' && empty($validated['started_at'])) {
            $validated['started_at'] = now();
        }

        if (array_key_exists('status', $validated) && $validated['status'] === 'closed' && empty($validated['ended_at'])) {
            $validated['ended_at'] = now();
        }

        $shiftKaryawan->update($validated);

        return response()->json([
            'message' => 'Shift karyawan berhasil diperbarui',
            'data' => $shiftKaryawan->fresh()->load(['outlet', 'user']),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ShiftKaryawan $shiftKaryawan)
    {
        $shiftKaryawan->delete();

        return response()->json([
            'message' => 'Shift karyawan berhasil dihapus',
        ]);
    }
}
