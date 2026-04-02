<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Table;

class TableController extends Controller
{
    private function getUser()
    {
        $user = auth()->user();

        if (!$user->outlet_id) {
            abort(400, 'User belum punya outlet');
        }

        return $user;
    }

    private function findTable($id)
    {
        return Table::where('id', $id)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * List meja
     */
    public function index()
    {
        $user = $this->getUser();

        return response()->json(
            Table::where('outlet_id', $user->outlet_id)
                ->latest()
                ->get()
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
            'outlet_id' => $user->outlet_id,
        ]);

        return response()->json($table, 201);
    }

    /**
     * Detail meja
     */
    public function show($id)
    {
        return response()->json($this->findTable($id));
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
        ]);

        $table->update([
            'name' => $request->name,
            'code' => $request->code,
            'capacity' => $request->capacity ?? $table->capacity,
            'status' => $request->status ?? $table->status,
        ]);

        return response()->json($table);
    }

    /**
     * Nonaktifkan meja (soft delete versi bisnis)
     */
    public function destroy($id)
    {
        $table = $this->findTable($id);

        $table->update([
            'is_active' => false
        ]);

        return response()->json([
            'message' => 'Meja berhasil dinonaktifkan',
        ]);
    }

    /**
     * Update status meja (POS realtime)
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
