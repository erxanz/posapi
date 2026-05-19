<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Untuk webhook: verifikasi signature pakai key yang benar (key yang dipakai saat create transaksi)
            $table->text('midtrans_server_key_used')->nullable()->after('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('midtrans_server_key_used');
        });
    }
};

