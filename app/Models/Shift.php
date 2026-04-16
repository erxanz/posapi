<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    /** @use HasFactory<\Database\Factories\ShiftFactory> */
    use HasFactory;

    protected $guarded = [];

    // Relasis ke tabel user karyawan
    public function users()
    {
        return $this->belongsToMany(User::class, 'shift_user');
    }
}
