<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;

    // PERBAIKAN: owner_id wajib dimasukkan
    protected $fillable = [
        'owner_id', 'name', 'type', 'value', 'min_purchase', 'start_date', 'end_date', 'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'value' => 'integer',
        'min_purchase' => 'integer'
    ];
}
