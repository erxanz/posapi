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
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['percentage', 'nominal']); // Persen atau Potongan Langsung
            $table->integer('value'); // Angka diskon (misal: 10 untuk 10% atau 15000 untuk Rp 15rb)
            $table->integer('min_purchase')->default(0);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Tambahkan relasi ke tabel orders agar laporan lebih rapi
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
