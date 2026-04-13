<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftKaryawan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'outlet_id',
        'waktu_mulai',
        'waktu_selesai',
        'status',
        'opening_balance',
        'closing_balance_system',
        'closing_balance_actual',
        'difference',
        'notes'
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
