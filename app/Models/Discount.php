<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;

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
        'max_usage',
        'used_count',
        'start_date',
        'end_date',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'value' => 'integer',
        'min_purchase' => 'integer',
        'max_discount' => 'integer',
        'max_usage' => 'integer',
        'used_count' => 'integer',
        'product_ids' => 'array',
        'category_ids' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
