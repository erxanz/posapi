<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    protected $fillable = [
        'outlet_id',
        'name',
        'qr_code',
        'is_active',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function isAvailable()
    {
        // Cek apakah meja sedang digunakan dalam order yang belum selesai
        return !$this->orders()->where('status', '!=', 'selesai')->exists();
    }
}
