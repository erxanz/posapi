<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Outlet;
use App\Models\Order;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;
    use HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'pin',
        'outlet_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'pin'
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * RELASI: Karyawan punya 1 outlet
     */
    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * RELASI: Manager punya banyak outlet (created/owned)
     */
    public function ownedOutlets()
    {
        return $this->hasMany(Outlet::class, 'owner_id');
    }

    /**
     * RELASI: Orders yang dibuat user
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * RELASI: Karyawan yang managed user (manager only)
     */
    public function karyawans()
    {
        return $this->hasManyThrough(
            User::class,
            Outlet::class,
            'owner_id',      // FK di outlets ke users
            'outlet_id',     // FK di users ke outlets
            'id',            // PK di users
            'id'             // PK di outlets
        );
    }

    // ===== ROLE HELPERS =====

    public function isDeveloper(): bool
    {
        return $this->role === 'developer';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function isKaryawan(): bool
    {
        return $this->role === 'karyawan';
    }

    /**
     * SECURITY: Get accessible outlet IDs untuk user
     * Manager: outlets yang dia own
     * Karyawan: hanya outlet miliknya
     */
    public function getAccessibleOutletIds(): array
    {
        if ($this->isDeveloper()) {
            return [];  // Developer akses semua (via role check di middleware)
        }

        if ($this->isManager()) {
            return $this->ownedOutlets()->pluck('id')->toArray();
        }

        // Karyawan
        return $this->outlet_id ? [$this->outlet_id] : [];
    }

    /**
     * SECURITY: Check if user dapat akses outlet tertentu
     */
    public function canAccessOutlet($outletId): bool
    {
        if ($this->isDeveloper()) {
            return true;
        }

        return in_array($outletId, $this->getAccessibleOutletIds());
    }
}
