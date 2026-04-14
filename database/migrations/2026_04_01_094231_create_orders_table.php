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

            // user_id nullable untuk QR Customer atau Kasir
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('customer_name')->nullable();
            $table->string('notes')->nullable();

            // PERBAIKAN 1: table_id wajib nullable untuk menampung pesanan Takeaway
            $table->foreignId('table_id')->nullable()->constrained('tables');

            $table->string('invoice_number')->unique();

            // total & penyesuaian order
            $table->integer('subtotal_price')->default(0);

            // PERBAIKAN 2: Enum disamakan dengan tabel discounts
            $table->enum('discount_type', ['percentage', 'nominal'])->nullable();

            // PERBAIKAN 3: Ubah decimal jadi integer (Standar mata uang Rupiah)
            $table->integer('discount_value')->nullable();
            $table->integer('discount_amount')->default(0);

            $table->enum('tax_type', ['percentage', 'nominal'])->nullable();
            $table->integer('tax_value')->nullable();
            $table->integer('tax_amount')->default(0);

            $table->integer('total_price')->default(0);

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
