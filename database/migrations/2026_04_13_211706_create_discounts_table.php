<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete()->index();
            $table->string('name');

            // PERBAIKAN: Scope sekarang punya 3 opsi
            $table->enum('scope', ['global', 'products', 'categories'])->default('global');

            // PERBAIKAN: Gunakan JSON array untuk menampung banyak ID sekaligus
            $table->json('product_ids')->nullable();
            $table->json('category_ids')->nullable();

            $table->enum('type', ['percentage', 'nominal']);
            $table->integer('value');
            $table->integer('max_discount')->nullable();
            $table->integer('min_purchase')->default(0);
            $table->integer('used_count')->default(0);
            $table->integer('max_usage')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['owner_id', 'is_active', 'start_date', 'end_date']);
            $table->index('type');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
