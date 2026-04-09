<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('category_id')->constrained('users')->cascadeOnDelete();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('name')->constrained('users')->cascadeOnDelete();
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
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

        DB::statement('UPDATE categories c INNER JOIN outlets o ON o.id = c.outlet_id SET c.owner_id = o.owner_id');
        DB::statement('UPDATE stations s INNER JOIN outlets o ON o.id = s.outlet_id SET s.owner_id = o.owner_id');
        DB::statement('UPDATE products p INNER JOIN outlets o ON o.id = p.outlet_id SET p.owner_id = o.owner_id');

        DB::statement('INSERT INTO outlet_product (outlet_id, product_id, price, stock, is_active, created_at, updated_at)
            SELECT p.outlet_id, p.id, p.price, p.stock, p.is_active, NOW(), NOW()
            FROM products p
            WHERE p.outlet_id IS NOT NULL');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['name', 'outlet_id']);
            $table->dropIndex(['outlet_id']);
            $table->dropConstrainedForeignId('outlet_id');
            $table->unique(['name', 'owner_id']);
            $table->index('owner_id');
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->dropIndex(['outlet_id', 'name']);
            $table->dropConstrainedForeignId('outlet_id');
            $table->index(['owner_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['outlet_id', 'category_id']);
            $table->dropConstrainedForeignId('outlet_id');
            $table->dropColumn(['price', 'stock', 'is_active']);
            $table->index(['owner_id', 'category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('category_id')->constrained()->nullOnDelete();
            $table->integer('price')->default(0)->after('description');
            $table->integer('stock')->default(0)->after('cost_price');
            $table->boolean('is_active')->default(true)->after('image');
        });

        DB::statement('UPDATE products p
            LEFT JOIN (
                SELECT product_id, MIN(outlet_id) AS outlet_id
                FROM outlet_product
                GROUP BY product_id
            ) op ON op.product_id = p.id
            LEFT JOIN outlet_product op2 ON op2.product_id = p.id AND op2.outlet_id = op.outlet_id
            SET p.outlet_id = op.outlet_id,
                p.price = COALESCE(op2.price, 0),
                p.stock = COALESCE(op2.stock, 0),
                p.is_active = COALESCE(op2.is_active, 1)');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['owner_id', 'category_id']);
            $table->dropConstrainedForeignId('owner_id');
            $table->index(['outlet_id', 'category_id']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        DB::statement('UPDATE categories c
            LEFT JOIN outlets o ON o.owner_id = c.owner_id
            SET c.outlet_id = o.id
            WHERE c.outlet_id IS NULL');

        DB::statement('UPDATE stations s
            LEFT JOIN outlets o ON o.owner_id = s.owner_id
            SET s.outlet_id = o.id
            WHERE s.outlet_id IS NULL');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['name', 'owner_id']);
            $table->dropIndex(['owner_id']);
            $table->dropConstrainedForeignId('owner_id');
            $table->unique(['name', 'outlet_id']);
            $table->index('outlet_id');
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->dropIndex(['owner_id', 'name']);
            $table->dropConstrainedForeignId('owner_id');
            $table->index(['outlet_id', 'name']);
        });

        Schema::dropIfExists('outlet_product');
    }
};
