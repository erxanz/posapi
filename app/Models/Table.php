<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Models\Outlet;
use App\Models\Order;

class Table extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'outlet_id',
        'name',
        'code',
        'capacity',
        'qr_code',
        'qr_token',
        'status',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capacity' => 'integer',
    ];

    /**
     * Model Events
     */
    protected static function booted()
    {
        // Saat pertama kali create
        static::creating(function ($table) {
            // default status
            if (!$table->status) {
                $table->status = 'available';
            }

            // generate qr_token jika belum ada
            if (!$table->qr_token) {
                $table->qr_token = Str::uuid();
            }

            // default capacity
            if (!$table->capacity) {
                $table->capacity = 1;
            }
        });

        // Setelah berhasil dibuat (sudah ada ID)
        static::created(function ($table) {
            // generate qr_code (butuh token + url)
            $table->updateQuietly([
                'qr_code' => url("/menu/{$table->qr_token}")
            ]);
        });
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // ===== SECURITY & UTILITY =====

    /**
     * SECURITY: Check apakah user dapat akses table ini
     */
    public function canBeAccessedBy(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // Manager hanya akses table di outlet miliknya
        if ($user->isManager()) {
            return $this->outlet->owner_id === $user->id;
        }

        // Karyawan hanya akses table di outlet miliknya
        if ($user->isKaryawan()) {
            return $this->outlet_id === $user->outlet_id;
        }

        return false;
    }

    /**
     * Check apakah table available
     */
    public function isAvailable(): bool
    {
        return $this->status === 'available' && $this->is_active;
    }
}
