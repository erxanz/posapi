<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckOutletAccess
{
    /**
     * Handle an incoming request.
     *
     * MIDDLEWARE: CheckOutletAccess
     *
     * Verifies that authenticated user dapat akses outlet yang diminta
     *
     * Logika:
     * - Developer: akses semua outlet
     * - Manager: hanya akses outlet dengan owner_id = user.id
     * - Karyawan: hanya akses outlet sesuai outlet_id miliknya
     * - Jika tidak sesuai → return 403 Forbidden
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        // Get outlet_id dari request
        // Priority: outlet parameter → body → query
        $outletId = $request->route('outlet')
            ?? $request->route('outletId')
            ?? $request->input('outlet_id')
            ?? $request->query('outlet_id');

        // Jika outlet_id tidak ditemukan, lanjutkan (beberapa endpoint tidak butuh outlet_id)
        if (!$outletId) {
            return $next($request);
        }

        // SECURITY: Check akses outlet
        if (!$user->canAccessOutlet($outletId)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Anda tidak memiliki akses ke outlet ini',
                'user_role' => $user->role,
                'user_outlet_id' => $user->outlet_id,
                'requested_outlet_id' => $outletId,
            ], 403);
        }

        return $next($request);
    }
}
