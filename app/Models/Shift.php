<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    // Relasi penting agar tidak error 500 saat ditarik oleh Controller
    public function users()
    {
        return $this->belongsToMany(User::class, 'shift_user');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}
