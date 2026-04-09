<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * List categories (by owner)
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $ownerId = $this->resolveOwnerId($user);

        if (!$ownerId) {
            return response()->json(['message' => 'Owner tidak ditemukan'], 400);
        }

        $limit = min($request->limit ?? 10, 100);

        $query = Category::query()
            ->select(['id', 'name', 'owner_id', 'created_at'])
            ->where('owner_id', $ownerId)
            ->withCount('products')
            ->latest('id');

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
        $ownerId = $this->resolveOwnerId($user);

        if (!$ownerId) {
            return response()->json(['message' => 'Owner tidak ditemukan'], 400);
        }

        $request->validate([
            'name' => 'required|string|max:50|unique:categories,name,NULL,id,owner_id,' . $ownerId
        ]);

        $category = Category::create([
            'name' => strtolower(trim($request->name)),
            'owner_id' => $ownerId
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

        $ownerId = $this->resolveOwnerId(auth()->user());

        $request->validate([
            'name' => 'required|string|max:50|unique:categories,name,' . $category->id . ',id,owner_id,' . $ownerId
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
     * Helper authorization (owner-based security)
     */
    private function authorizeCategory(Category $category): void
    {
        $ownerId = $this->resolveOwnerId(auth()->user());

        if (!$ownerId || (int) $category->owner_id !== (int) $ownerId) {
            abort(403, 'Forbidden');
        }
    }

    private function resolveOwnerId($user): ?int
    {
        if ($user->role === 'developer') {
            return request()->integer('owner_id') ?: $user->id;
        }

        if ($user->role === 'manager') {
            return $user->id;
        }

        if ($user->outlet_id) {
            return \App\Models\Outlet::query()->whereKey($user->outlet_id)->value('owner_id');
        }

        return null;
    }
}
