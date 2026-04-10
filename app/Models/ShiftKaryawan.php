<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Outlet;
use App\Models\User;

class ShiftKaryawan extends Model
{
    /** @use HasFactory<\Database\Factories\ShiftKaryawanFactory> */
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'user_id',
        'shift_ke',
        'uang_awal',
        'started_at',
        'ended_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'shift_ke' => 'integer',
            'uang_awal' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeShiftKe($query, int $shiftKe)
    {
        return $query->where('shift_ke', $shiftKe);
    }
}
