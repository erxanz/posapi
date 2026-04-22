<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Outlet;
use App\Models\Order;
use App\Models\Payment;

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
        'image',
        'phone_number',
        'role',
        'pin',
        'outlet_id',
        'is_active',
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
            'is_active' => 'boolean',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'paid_by');
    }

    public function historyTransactions()
    {
        return $this->hasMany(HistoryTransaction::class, 'cashier_id');
    }

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

    // Relasi ke jadwal shift (new calendar system)
    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    // DEPRECATED: Old pivot relation
    // public function shifts()
    // {
    //     return $this->belongsToMany(Shift::class, 'shift_user');
    // }

    public function shiftKaryawans()
    {
        return $this->hasMany(ShiftKaryawan::class);
    }
}
