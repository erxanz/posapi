<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'used_count',
        'max_usage',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'product_ids' => 'array',
        'category_ids' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }
}

