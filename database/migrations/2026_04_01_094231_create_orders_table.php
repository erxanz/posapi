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

            // user bisa null (QR / kasir)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('customer_name')->nullable();
            $table->string('notes')->nullable();

            // FIX relasi table
            $table->foreignId('table_id')->nullable()->constrained()->nullOnDelete();

            $table->string('invoice_number')->unique();

            // PRICE
            $table->integer('subtotal_price')->default(0);

            // DISCOUNT
            $table->foreignId('discount_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('discount_amount')->default(0);

            // optional (diskon manual)
            $table->enum('manual_discount_type', ['percentage', 'nominal'])->nullable();
            $table->integer('manual_discount_value')->nullable();

            // TAX (FIXED)
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('tax_amount')->default(0);

            // OPTIONAL untuk multi tax (PPN + service charge, dll)
            $table->json('tax_breakdown')->nullable();

            // TOTAL
            $table->integer('total_price')->default(0);

            // STATUS
            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');

            $table->json('logs')->nullable();

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
