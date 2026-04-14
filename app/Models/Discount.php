<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    /** @use HasFactory<\Database\Factories\DiscountFactory> */
    use HasFactory;

    protected $fillable = [
        'owner_id', 'name', 'type', 'value', 'min_purchase', 'start_date', 'end_date', 'is_active', 'used_count', 'max_usage'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'value' => 'integer',
        'min_purchase' => 'integer',
        'used_count' => 'integer',
        'max_usage' => 'integer'
    ];

    /**
     * Relasi ke pembuat discount (Manager/Owner)
     */
    public function owner()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relasi ke orders yang menggunakan discount ini
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Scope: Hanya discount milik owner tertentu
     */
    public function scopeByOwner($query, $ownerId)
    {
        return $query->where('owner_id', $ownerId);
    }

    /**
     * Scope: Discount aktif saat ini
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where('start_date', '<=', now())
                     ->where('end_date', '>=', now());
    }

    /**
     * Scope: Valid untuk hari ini (termasuk masa depan yang sudah mulai)
     */
    public function scopeValidForToday($query)
    {
        return $query->where('start_date', '<=', now()->endOfDay())
                     ->where('end_date', '>=', now()->startOfDay());
    }

    /**
     * Accessor: Format tampilan value (10% / Rp15.000)
     */
    public function getFormattedValueAttribute()
    {
        if ($this->type === 'percentage') {
            return $this->value . '%';
        }
        return 'Rp ' . number_format($this->value, 0, ',', '.');
    }

    /**
     * Cek apakah masih ada kuota penggunaan
     */
    public function getHasQuotaAttribute()
    {
        if (!$this->max_usage) return true;
        return $this->used_count < $this->max_usage;
    }
}
