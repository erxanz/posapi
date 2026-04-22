<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Otentikasi channel orders per outlet
Broadcast::channel('orders.outlet.{outletId}', function ($user, $outletId) {
    // Berikan izin jika user berada di outlet yang sama
    // ATAU jika dia adalah manajer/developer yang berhak memantau
    return (int) $user->outlet_id === (int) $outletId || $user->role === 'manager';
});
