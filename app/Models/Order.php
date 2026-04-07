<?php

namespace App\Models;

use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Table;
use App\Models\User;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'user_id',
        'table_id',
        'customer_name',
        'notes',
        'invoice_number',
        'total_price',
        'status',
    ];

    // ===== RELASI =====

    /**
     * RELASI: Order belongs to Outlet
     */
    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * RELASI: Order belongs to User (cashier/waiter yang membuat order)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * RELASI: Order belongs to Table
     */
    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    /**
     * RELASI: Order has many OrderItem
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    // ===== UTILITY METHODS =====

    /**
     * SECURITY: Check if user dapat akses order ini
     */
    public function canBeAccessedBy(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // Manager dapat akses orders di outlet miliknya
        if ($user->isManager()) {
            return $this->outlet->owner_id === $user->id;
        }

        // Karyawan dapat akses orders di outlet miliknya
        if ($user->isKaryawan()) {
            return $this->outlet_id === $user->outlet_id;
        }

        return false;
    }

    /**
     * Calculate total price dari items
     */
    public function calculateTotal(): int
    {
        return (int) $this->items()->sum('total_price');
    }

    /**
     * Check apakah order masih bisa dimodifikasi (draft/pending)
     */
    public function canBeModified(): bool
    {
        return $this->status === 'pending';
    }
}
