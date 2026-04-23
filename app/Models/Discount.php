<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id', 'name', 'scope', 'product_id', 'type', 'value',
        'max_discount', 'min_purchase', 'start_date', 'end_date', 'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'value' => 'integer',
        'min_purchase' => 'integer',
        'max_discount' => 'integer'
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
