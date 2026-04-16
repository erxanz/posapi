<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'name',
        'start_time',
        'end_time'
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}
