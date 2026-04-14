<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscountController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Display listing discounts milik owner/manager
     */
    public function index(Request $request) {
        $user = auth()->user();

        $query = Discount::withCount('orders')
            ->byOwner($user->id)
            ->orderBy('start_date', 'desc');

        // Filter opsional
        if ($request->filled('active_only')) {
            $query->active();
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $discounts = $query->paginate($request->get('limit', 15));

        return response()->json($discounts);
    }

    /**
     * Store discount baru (owner_id = auth user)
     */
    public function store(Request $request) {
        $user = auth()->user();

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required|in:percentage,nominal',
            'value' => [
                'required',
                'integer',
                'min:1',
                Rule::when($request->type === 'percentage', 'max:100'),
                Rule::when($request->type === 'nominal', 'max:5000000') // Max Rp5jt
            ],
            'min_purchase' => 'nullable|integer|min:0',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after:start_date',
            'is_active' => 'boolean',
            'max_usage' => 'nullable|integer|min:1|max:10000',
            'used_count' => 'integer|min:0|max:10000|default:0'
        ]);

        $data['owner_id'] = $user->id;
        $data['used_count'] ??= 0;

        $discount = Discount::create($data);

        return response()->json([
            'message' => 'Discount berhasil dibuat',
            'data' => $discount->load('owner')
        ], 201);
    }

    /**
     * Show detail discount + usage stats
     */
    public function show(Discount $discount)
    {
        $this->authorizeDiscountOwnership($discount);

        $discount->load(['owner', 'orders' => fn($q) => $q->latest()->limit(5)]);

        return response()->json($discount);
    }

    /**
     * Update discount (hanya owner)
     */
    public function update(Request $request, Discount $discount) {
        $this->authorizeDiscountOwnership($discount);

        $data = $request->validate([
            'name' => 'string|max:100',
            'type' => 'in:percentage,nominal',
            'value' => [
                'integer',
                'min:1',
                Rule::when($request->type === 'percentage', 'max:100'),
                Rule::when($request->type === 'nominal', 'max:5000000')
            ],
            'min_purchase' => 'nullable|integer|min:0',
            'start_date' => 'nullable|date|after_or_equal:today',
            'end_date' => 'nullable|date|after:start_date',
            'is_active' => 'boolean',
            'max_usage' => 'nullable|integer|min:1|max:10000'
        ]);

        $discount->update($data);

        return response()->json([
            'message' => 'Discount berhasil diupdate',
            'data' => $discount->fresh()->load('owner')
        ]);
    }

    /**
     * Delete discount (hanya owner)
     */
    public function destroy(Discount $discount) {
        $this->authorizeDiscountOwnership($discount);

        $discount->delete();

        return response()->json([
            'message' => 'Discount berhasil dihapus'
        ]);
    }

    /**
     * Helper: Cek ownership discount
     */
    private function authorizeDiscountOwnership(Discount $discount)
    {
        $user = auth()->user();

        if ($discount->owner_id !== $user->id && $user->role !== 'developer') {
            abort(403, 'Anda tidak berhak mengakses discount ini');
        }
    }
}
