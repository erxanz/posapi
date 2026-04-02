<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * List categories (by outlet)
     */
    public function index(Request $request)
    {
        $query = Category::query()
            ->withCount('products') // anti N+1 (count saja)
            ->latest();

        // WAJIB: filter outlet (multi tenant)
        $query->where('outlet_id', auth()->user()->outlet_id);

        // optional search
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        return response()->json(
            $query->paginate($request->limit ?? 10)
        );
    }

    /**
     * Store category
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user->outlet_id) {
            return response()->json(['message' => 'User belum punya outlet'], 400);
        }

        $request->validate([
            'name' => 'required|string|max:50'
        ]);

        $category = Category::create([
            'name' => $request->name,
            'outlet_id' => $user->outlet_id
        ]);

        return response()->json($category, 201);
    }

    /**
     * Show category
     */
    public function show(Category $category)
    {
        $this->authorizeCategory($category);

        // load count saja (hemat)
        $category->loadCount('products');

        return response()->json($category);
    }

    /**
     * Update category
     */
    public function update(Request $request, Category $category)
    {
        $this->authorizeCategory($category);

        $request->validate([
            'name' => 'required|string|max:255'
        ]);

        $category->update([
            'name' => $request->name
        ]);

        return response()->json($category);
    }

    /**
     * Delete category
     */
    public function destroy(Category $category)
    {
        $this->authorizeCategory($category);

        // Cegah delete kalau masih dipakai product
        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'Category masih digunakan oleh produk'
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully'
        ]);
    }

    /**
     * Helper authorization (multi-outlet security)
     */
    private function authorizeCategory($category)
    {
        if ($category->outlet_id !== auth()->user()->outlet_id) {
            abort(403, 'Forbidden');
        }
    }
}
