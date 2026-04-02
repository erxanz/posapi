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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            // ubah user_id jadi nullable karena bisa buat order tanpa login
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // tambahan untuk QR customer
            $table->string('customer_name')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('table_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number')->unique();
            $table->integer('total_price')->default(0);
            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->timestamps();
            $table->index(['outlet_id', 'invoice_number', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
