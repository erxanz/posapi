<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftKaryawan extends Model
{
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'user_id',
        'shift_ke',
        'uang_awal',
        'started_at',
        'ended_at',
        'opening_balance',
        'closing_balance_system',
        'closing_balance_actual',
        'difference',
        'notes',
        'status'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'opening_balance' => 'integer',
        'closing_balance_system' => 'integer',
        'closing_balance_actual' => 'integer',
        'difference' => 'integer',
        'shift_ke' => 'integer',
        'uang_awal' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}
