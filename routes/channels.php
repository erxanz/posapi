<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Order;
use App\Models\Outlet;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Otentikasi channel orders per outlet
Broadcast::channel('orders.outlet.{outletId}', function ($user, $outletId) {
    if ($user->role === 'developer') {
        return true;
    }

    if ($user->role === 'karyawan') {
        return (int) $user->outlet_id === (int) $outletId;
    }

    // PERBAIKAN: sebelumnya SEMUA manager (tenant manapun) diizinkan
    // subscribe channel outlet manapun. Sekarang harus outlet miliknya
    // sendiri (dicek via owner_id), konsisten dengan pola otorisasi di
    // REST API (mis. OutletController::authorizeOutlet).
    if ($user->role === 'manager') {
        return Outlet::where('id', $outletId)->where('owner_id', $user->id)->exists();
    }

    return false;
});

Broadcast::channel('customer-order.{orderId}', function ($user, $orderId) {
    $order = Order::query()->select(['id', 'outlet_id'])->find($orderId);

    if (! $order) {
        return false;
    }

    if ($user->role === 'developer') {
        return true;
    }

    if ($user->role === 'karyawan') {
        return (int) $user->outlet_id === (int) $order->outlet_id;
    }

    // Lihat catatan yang sama di channel orders.outlet di atas.
    if ($user->role === 'manager') {
        return Outlet::where('id', $order->outlet_id)->where('owner_id', $user->id)->exists();
    }

    return false;
});
