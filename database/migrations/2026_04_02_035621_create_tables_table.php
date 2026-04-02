<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();

            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            // DATA UTAMA
            $table->string('name'); // Meja 1, VIP 2
            $table->string('code')->nullable(); // T01, VIP01 (opsional tapi penting di POS)
            $table->integer('capacity')->default(1); // jumlah kursi di meja, default 1

            // QR (lebih aman pakai token)
            $table->string('qr_code')->nullable();
            $table->uuid('qr_token')->unique()->nullable();

            // STATUS (pakai string biar fleksibel)
            $table->string('status')->default('available'); // available | occupied | reserved | maintenance
            // AKTIF / NONAKTIF
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // INDEX & CONSTRAINT
            $table->unique(['outlet_id', 'name']); // nama unik per outlet
            $table->index('outlet_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
