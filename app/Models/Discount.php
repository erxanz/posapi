<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;

    // WAJIB: Daftarkan semua nama kolom di sini agar diizinkan masuk ke database
    protected $fillable = [
        'owner_id',
        'name',
        'scope',
        'product_ids',
        'category_ids',
        'type',
        'value',
        'max_discount',
        'min_purchase',
        'start_date',
        'end_date',
        'is_active'
    ];

    // WAJIB: Beritahu Laravel kalau data ini bentuknya Array/JSON
    protected $casts = [
        'is_active' => 'boolean',
        'value' => 'integer',
        'min_purchase' => 'integer',
        'max_discount' => 'integer',
        'product_ids' => 'array',
        'category_ids' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // public function orders()
    // {
    //     return $this->hasMany(Order::class);
    // }
}
