<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Table;

class TableController extends Controller
{
    /**
     * List semua meja (per outlet)
     */
    public function index()
    {
        return response()->json(
            Table::where('outlet_id', auth()->user()->outlet_id)
                ->latest()
                ->get()
        );
    }

    /**
     * Create meja baru
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100'
        ]);

        $user = auth()->user();

        $table = Table::create([
            'name' => $request->name,
            'outlet_id' => $user->outlet_id,
        ]);

        // 🔥 Generate QR otomatis
        $table->update([
            'qr_code' => url("/menu/{$table->outlet_id}/{$table->id}")
        ]);

        return response()->json($table, 201);
    }

    /**
     * Detail meja
     */
    public function show($id)
    {
        $table = Table::where('id', $id)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        return response()->json($table);
    }

    /**
     * Update meja
     */
    public function update(Request $request, $id)
    {
        $table = Table::where('id', $id)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        $request->validate([
            'name' => 'required|string|max:100'
        ]);

        $table->update([
            'name' => $request->name
        ]);

        return response()->json($table);
    }

    /**
     * Delete meja
     */
    public function destroy($id)
    {
        $table = Table::where('id', $id)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        $table->delete();

        return response()->json([
            'message' => 'Meja berhasil dihapus'
        ]);
    }
}
