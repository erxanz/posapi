<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Table;

class TableController extends Controller
{
    /**
     * Helper ambil user + validasi outlet
     */
    private function getUser()
    {
        $user = auth()->user();

        if (!$user->outlet_id) {
            abort(400, 'User belum punya outlet');
        }

        return $user;
    }

    /**
     * Helper find table by outlet (ANTI DATA BOCOR)
     */
    private function findTable($id)
    {
        return Table::query()
            ->where('id', $id)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();
    }

    /**
     * List meja
     */
    public function index(Request $request)
    {
        $user = $this->getUser();

        $query = Table::query()
            ->where('outlet_id', $user->outlet_id)
            ->latest();

        // filter status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // filter active
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        // search
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        return response()->json(
            $query->paginate($request->limit ?? 10)
        );
    }

    /**
     * Create meja
     */
    public function store(Request $request)
    {
        $user = $this->getUser();

        $request->validate([
            'name' => 'required|string|max:100|unique:tables,name,NULL,id,outlet_id,' . $user->outlet_id,
            'code' => 'nullable|string|max:50',
            'capacity' => 'nullable|integer|min:1',
        ]);

        $table = Table::create([
            'name' => $request->name,
            'code' => $request->code,
            'capacity' => $request->capacity ?? 1,
            'status' => 'available',
            'is_active' => true,
            'outlet_id' => $user->outlet_id,
        ]);

        return response()->json($table, 201);
    }

    /**
     * Detail meja
     */
    public function show($id)
    {
        $table = $this->findTable($id);

        return response()->json($table);
    }

    /**
     * Update meja
     */
    public function update(Request $request, $id)
    {
        $table = $this->findTable($id);
        $user = $this->getUser();

        $request->validate([
            'name' => 'required|string|max:100|unique:tables,name,' . $id . ',id,outlet_id,' . $user->outlet_id,
            'code' => 'nullable|string|max:50',
            'capacity' => 'nullable|integer|min:1',
            'status' => 'nullable|in:available,occupied,reserved,maintenance',
            'is_active' => 'nullable|boolean',
        ]);

        $table->update([
            'name' => $request->name,
            'code' => $request->code,
            'capacity' => $request->capacity ?? $table->capacity,
            'status' => $request->status ?? $table->status,
            'is_active' => $request->is_active ?? $table->is_active,
        ]);

        return response()->json($table);
    }

    /**
     * Nonaktifkan meja (soft delete bisnis)
     */
    public function destroy($id)
    {
        $table = $this->findTable($id);

        // best practice: kalau masih occupied, jangan boleh disable
        if ($table->status === 'occupied') {
            return response()->json([
                'message' => 'Meja sedang digunakan'
            ], 422);
        }

        $table->update([
            'is_active' => false
        ]);

        return response()->json([
            'message' => 'Meja berhasil dinonaktifkan',
        ]);
    }

    /**
     * Update status meja (realtime POS)
     */
    public function updateStatus(Request $request, $id)
    {
        $table = $this->findTable($id);

        $request->validate([
            'status' => 'required|in:available,occupied,reserved,maintenance'
        ]);

        $table->update([
            'status' => $request->status
        ]);

        return response()->json([
            'message' => 'Status meja berhasil diperbarui',
            'data' => $table
        ]);
    }
}
