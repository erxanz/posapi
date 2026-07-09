<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Models\StockHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function adjust(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:in,out,opname',
            'quantity' => 'required|integer|min:0',
            'reference' => 'nullable|string|max:255'
        ]);

        $outlet = Outlet::findOrFail($validated['outlet_id']);

        $user = auth()->user();
        if ($user->role === 'karyawan' && $user->outlet_id !== $outlet->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($user->role === 'manager') {
            $isMine = Outlet::where('id', $outlet->id)->where('owner_id', $user->id)->exists();
            if (!$isMine) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        DB::beginTransaction();
        try {
            // Kunci baris produk SEBELUM membaca stok, supaya 2 request
            // penyesuaian stok bersamaan untuk produk yang sama tidak saling
            // menimpa berdasarkan nilai stok yang sama-sama sudah usang.
            $product = $outlet->products()->where('products.id', $validated['product_id'])->lockForUpdate()->firstOrFail();

            $currentStock = (int) $product->pivot->stock;
            $newStock = $currentStock;
            $qtyChange = (int) $validated['quantity'];

            if ($validated['type'] === 'in') {
                $newStock += $qtyChange;
            } elseif ($validated['type'] === 'out') {
                if ($currentStock < $qtyChange) {
                    DB::rollBack();
                    return response()->json(['message' => 'Stok tidak cukup untuk dikeluarkan'], 400);
                }
                $newStock -= $qtyChange;
                $qtyChange = -$qtyChange; // Jadikan minus untuk pencatatan
            } elseif ($validated['type'] === 'opname') {
                $newStock = $qtyChange; // Jika opname, quantity = stok fisik sebenarnya
                $qtyChange = $newStock - $currentStock; // Selisihnya
            }

            // Update tabel pivot
            $outlet->products()->updateExistingPivot($product->id, ['stock' => $newStock]);

            // Catat riwayat
            StockHistory::create([
                'outlet_id' => $outlet->id,
                'product_id' => $product->id,
                'user_id' => auth()->id(),
                'type' => $validated['type'],
                'quantity' => $qtyChange,
                'final_stock' => $newStock,
                'reference' => $validated['reference'] ?? 'Penyesuaian manual'
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Stok berhasil diperbarui',
                'current_stock' => $newStock
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
