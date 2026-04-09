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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('station_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('cost_price')->default(0);
            $table->string('image')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'category_id']);
        });

        Schema::create('outlet_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('price')->default(0);
            $table->integer('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'product_id']);
            $table->index(['outlet_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outlet_product');
        Schema::dropIfExists('products');
    }
};
