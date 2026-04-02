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
        $user = auth()->user();

        $limit = min($request->limit ?? 10, 100);

        $query = Category::query()
            ->select(['id', 'name', 'outlet_id', 'created_at']) // hemat column
            ->where('outlet_id', $user->outlet_id)
            ->withCount('products')
            ->latest('id'); // default sort by latest id

        if ($request->filled('search')) {
            $query->where('name', 'like', trim($request->search) . '%'); // pakai index
        }

        return response()->json([
            'data' => $query->paginate($limit)
        ]);
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
            'name' => 'required|string|max:50|unique:categories,name,NULL,id,outlet_id,' . $user->outlet_id
        ]);

        $category = Category::create([
            'name' => strtolower(trim($request->name)),
            'outlet_id' => $user->outlet_id
        ]);

        return response()->json([
            'data' => $category
        ], 201);
    }

    /**
     * Show category
     */
    public function show(Category $category)
    {
        $this->authorizeCategory($category);

        // load count saja (hemat)
        $category->loadCount('products');

        return response()->json([
            'data' => $category
        ]);
    }

    /**
     * Update category
     */
    public function update(Request $request, Category $category)
    {
        $this->authorizeCategory($category);

        $request->validate([
            'name' => 'required|string|max:50|unique:categories,name,' . $category->id . ',id,outlet_id,' . auth()->user()->outlet_id
        ]);

        $category->update([
            'name' => strtolower(trim($request->name))
        ]);

        return response()->json([
            'data' => $category
        ]);
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
    private function authorizeCategory(Category $category): void
    {
        if ($category->outlet_id !== auth()->user()->outlet_id) {
            abort(403, 'Forbidden');
        }
    }
}
