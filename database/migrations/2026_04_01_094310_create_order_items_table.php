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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('qty'); // jumlah produk yang dibeli
            $table->integer('cancelled_qty')->default(0); // jumlah produk yang dibatalkan
            $table->integer('price'); // harga saat transaksi
            $table->integer('total_price')->default(0); // price * qty
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
