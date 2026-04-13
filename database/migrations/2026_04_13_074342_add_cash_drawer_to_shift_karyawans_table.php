<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_karyawans', function (Blueprint $table) {
            $table->integer('opening_balance')->default(0)->after('started_at'); // Modal awal di laci
            $table->integer('closing_balance_system')->nullable()->after('ended_at'); // Ekspektasi sistem (Modal + Penjualan Cash)
            $table->integer('closing_balance_actual')->nullable()->after('closing_balance_system'); // Uang fisik yang dihitung kasir saat tutup
            $table->integer('difference')->nullable()->after('closing_balance_actual'); // Selisih (minus = kurang, plus = lebih)
            $table->text('notes')->nullable()->after('difference'); // Catatan jika ada selisih
        });
    }

    public function down(): void
    {
        Schema::table('shift_karyawans', function (Blueprint $table) {
            $table->dropColumn([
                'opening_balance',
                'closing_balance_system',
                'closing_balance_actual',
                'difference',
                'notes'
            ]);
        });
    }
};
