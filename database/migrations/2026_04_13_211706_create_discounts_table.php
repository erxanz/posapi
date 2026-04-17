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

            // PERBAIKAN 4: Tambahkan owner_id agar diskon hanya berlaku di cabang milik manager pembuatnya
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete()->index();

            $table->string('name');
            $table->enum('type', ['percentage', 'nominal']); // Persen atau Potongan Langsung
            $table->integer('value'); // Angka diskon (misal: 10 untuk 10% atau 15000 untuk Rp 15rb)
            $table->integer('min_purchase')->default(0);
            $table->integer('used_count')->default(0);
            $table->integer('max_usage')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Composite index untuk query cepat: owner + active + date
            $table->index(['owner_id', 'is_active', 'start_date', 'end_date']);
            $table->index('type');
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
