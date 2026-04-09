<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * PUBLIC MENU (QR) - OPTIMIZED
     */
    public function publicMenu($outletId, $tableId)
    {
        $table = Table::query()
            ->select(['id', 'name', 'outlet_id', 'is_active'])
            ->where('id', $tableId)
            ->where('outlet_id', $outletId)
            ->firstOrFail();

        $products = Cache::remember(
            "menu_outlet_{$outletId}",
            60,
            fn () => Outlet::query()
                ->findOrFail($outletId)
                ->products()
                ->select([
                    'products.id',
                    'products.category_id',
                    'products.station_id',
                    'products.name',
                    'products.description',
                    'products.image',
                ])
                ->wherePivot('is_active', true)
                ->wherePivot('stock', '>', 0)
                ->with([
                    'category:id,name'
                ])
                ->orderBy('name')
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'category_id' => $product->category_id,
                        'station_id' => $product->station_id,
                        'name' => $product->name,
                        'description' => $product->description,
                        'image' => $product->image,
                        'is_active' => (bool) $product->pivot->is_active,
                        'price' => (int) $product->pivot->price,
                        'stock' => (int) $product->pivot->stock,
                        'category' => $product->category,
                    ];
                })
        );

        return response()->json([
            'table' => $table,
            'products' => $products
        ], 200);
    }

    /**
     * LIST PRODUCT (NO N+1)
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $limit = min($request->integer('limit', 10), 100);
        $ownerId = $this->resolveOwnerId($user);

        if ($user->role === 'karyawan') {
            if (!$user->outlet_id) {
                return response()->json(['message' => 'User belum punya outlet'], 400);
            }

            $query = Outlet::query()
                ->findOrFail($user->outlet_id)
                ->products()
                ->select([
                    'products.id',
                    'products.category_id',
                    'products.station_id',
                    'products.name',
                    'products.description',
                    'products.cost_price',
                    'products.image',
                ])
                ->wherePivot('is_active', true)
                ->with(['category:id,name'])
                ->orderByDesc('products.created_at');

            if ($request->filled('category_id')) {
                $query->where('products.category_id', $request->category_id);
            }

            if ($request->filled('search')) {
                $keywords = array_filter(explode(' ', trim($request->search)));

                $query->where(function ($q) use ($keywords) {
                    foreach ($keywords as $word) {
                        $q->where('products.name', 'like', "%{$word}%");
                    }
                });
            }

            $paginated = $query->paginate($limit);
            $paginated->getCollection()->transform(function ($product) {
                $product->price = (int) $product->pivot->price;
                $product->stock = (int) $product->pivot->stock;
                $product->is_active = (bool) $product->pivot->is_active;
                unset($product->pivot);

                return $product;
            });

            return response()->json([
                'data' => $paginated
            ]);
        }

        if (!$ownerId) {
            return response()->json(['message' => 'Owner tidak ditemukan'], 400);
        }

        $query = Product::query()
            ->where('owner_id', $ownerId)
            ->select([
                'id',
                'owner_id',
                'category_id',
                'station_id',
                'name',
                'description',
                'cost_price',
                'image',
            ])
            ->with([
                'category:id,name',
                'outlets:id,name'
            ])
            ->latest();

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('search')) {
            $keywords = array_filter(explode(' ', trim($request->search)));

            $query->where(function ($q) use ($keywords) {
                foreach ($keywords as $word) {
                    $q->where('name', 'like', "%{$word}%");
                }
            });
        }

        $paginated = $query->paginate($limit);
        $paginated->getCollection()->transform(function ($product) {
            $product->outlets->transform(function ($outlet) {
                $outlet->price = (int) $outlet->pivot->price;
                $outlet->stock = (int) $outlet->pivot->stock;
                $outlet->is_active = (bool) $outlet->pivot->is_active;
                unset($outlet->pivot);

                return $outlet;
            });

            return $product;
        });

        return response()->json([
            'data' => $paginated
        ]);
    }

    /**
     * STORE PRODUCT
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        $ownerId = $this->resolveOwnerId($user);

        if ($user->role === 'karyawan') {
            return response()->json(['message' => 'Karyawan tidak diizinkan membuat produk'], 403);
        }

        if (!$ownerId) {
            return response()->json(['message' => 'Owner tidak ditemukan'], 400);
        }

        $request->validate([
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')->where(function ($q) use ($ownerId) {
                    $q->where('owner_id', $ownerId);
                })
            ],
            'station_id' => [
                'nullable',
                Rule::exists('stations', 'id')->where(function ($q) use ($ownerId) {
                    $q->where('owner_id', $ownerId);
                })
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'cost_price' => 'required|integer|min:0',
            'image' => 'nullable|image|max:2048',
            'outlets' => 'required|array|min:1',
            'outlets.*.outlet_id' => [
                'required',
                Rule::exists('outlets', 'id')->where(function ($q) use ($ownerId) {
                    $q->where('owner_id', $ownerId);
                })
            ],
            'outlets.*.price' => 'required|integer|min:0',
            'outlets.*.stock' => 'required|integer|min:0',
            'outlets.*.is_active' => 'required|boolean',
        ]);

        $data = $request->only([
            'category_id',
            'station_id',
            'name',
            'description',
            'cost_price',
        ]);

        // UPLOAD IMAGE
        if ($request->hasFile('image')) {
            $data['image'] = $this->uploadImage($request->file('image'), $request->name);
        }

        $data['owner_id'] = $ownerId;

        $product = Product::create($data);

        $syncData = collect($request->input('outlets', []))
            ->keyBy('outlet_id')
            ->map(fn ($item) => [
                'price' => (int) $item['price'],
                'stock' => (int) $item['stock'],
                'is_active' => (bool) $item['is_active'],
            ])
            ->toArray();

        $product->outlets()->sync($syncData);
        $this->forgetMenuCache(array_keys($syncData));

        return response()->json([
            'data' => $product->load(['category:id,name', 'outlets:id,name'])
        ], 201);
    }

    /**
     * SHOW PRODUCT
     */
    public function show(Product $product)
    {
        $this->authorizeProduct($product);

        return response()->json([
            'data' => $product->load(['category:id,name', 'outlets:id,name'])
        ], 200);
    }

    /**
     * UPDATE PRODUCT
     */
    public function update(Request $request, Product $product)
    {
        $user = auth()->user();
        $ownerId = $this->resolveOwnerId($user);

        $this->authorizeProduct($product);

        $request->validate([
            'category_id' => [
                'sometimes',
                Rule::exists('categories', 'id')->where(function ($q) use ($ownerId) {
                    $q->where('owner_id', $ownerId);
                })
            ],
            'station_id' => [
                'nullable',
                Rule::exists('stations', 'id')->where(function ($q) use ($ownerId) {
                    $q->where('owner_id', $ownerId);
                })
            ],
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'cost_price' => 'sometimes|required|integer|min:0',
            'image' => 'nullable|image|max:2048',
            'outlets' => 'sometimes|array|min:1',
            'outlets.*.outlet_id' => [
                'required_with:outlets',
                Rule::exists('outlets', 'id')->where(function ($q) use ($ownerId) {
                    $q->where('owner_id', $ownerId);
                })
            ],
            'outlets.*.price' => 'required_with:outlets|integer|min:0',
            'outlets.*.stock' => 'required_with:outlets|integer|min:0',
            'outlets.*.is_active' => 'required_with:outlets|boolean',
        ]);

        $data = $request->only([
            'category_id',
            'station_id',
            'name',
            'description',
            'cost_price',
        ]);

        // UPDATE IMAGE
        if ($request->hasFile('image')) {

            if ($product->image && Storage::disk('public')->exists($product->image)) {
                Storage::disk('public')->delete($product->image);
            }

            $data['image'] = $this->uploadImage($request->file('image'), $request->name);
        }

        $product->update($data);

        $outletIds = [];

        if ($request->filled('outlets')) {
            $syncData = collect($request->input('outlets', []))
                ->keyBy('outlet_id')
                ->map(fn ($item) => [
                    'price' => (int) $item['price'],
                    'stock' => (int) $item['stock'],
                    'is_active' => (bool) $item['is_active'],
                ])
                ->toArray();

            $product->outlets()->sync($syncData);
            $outletIds = array_keys($syncData);
        } else {
            $outletIds = $product->outlets()->pluck('outlets.id')->all();
        }

        $this->forgetMenuCache($outletIds);

        return response()->json([
            'data' => $product->load(['category:id,name', 'outlets:id,name'])
        ], 200);
    }

    /**
     * DELETE PRODUCT
     */
    public function destroy(Product $product)
    {
        $this->authorizeProduct($product);

        $outletIds = $product->outlets()->pluck('outlets.id')->all();

        if ($product->image && Storage::disk('public')->exists($product->image)) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        $this->forgetMenuCache($outletIds);

        return response()->json([
            'message' => 'Product deleted successfully'
        ], 200);
    }

    /**
     * HELPER AUTH
     */
    private function authorizeProduct(Product $product): void
    {
        $ownerId = $this->resolveOwnerId(auth()->user());

        if (!$ownerId || (int) $product->owner_id !== (int) $ownerId) {
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
            return Outlet::query()->whereKey($user->outlet_id)->value('owner_id');
        }

        return null;
    }

    private function forgetMenuCache(array $outletIds): void
    {
        foreach (array_unique($outletIds) as $outletId) {
            Cache::forget("menu_outlet_{$outletId}");
        }
    }

    /**
     * HELPER UPLOAD IMAGE
     */
    private function uploadImage($file, $name): string
    {
        $filename = Str::slug($name) . '-' . Str::random(6) . '.' . $file->getClientOriginalExtension();

        return $file->storeAs('products', $filename, 'public');
    }
}
