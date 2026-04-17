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
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('rate', 15, 4);
            $table->enum('type', ['percentage', 'fixed']);
            $table->foreignId('outlet_id')->nullable()->constrained()->onDelete('cascade');
            $table->boolean('active')->default(true);
            $table->unique(['outlet_id', 'name']);
            $table->timestamps();
        });

        // Tambahkan relasi ke tabel orders agar laporan lebih rapi
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
