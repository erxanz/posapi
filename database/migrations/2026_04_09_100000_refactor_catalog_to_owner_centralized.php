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

        DB::statement('UPDATE categories
            SET owner_id = (
                SELECT owner_id
                FROM outlets
                WHERE outlets.id = categories.outlet_id
            )
            WHERE outlet_id IS NOT NULL');

        DB::statement('UPDATE stations
            SET owner_id = (
                SELECT owner_id
                FROM outlets
                WHERE outlets.id = stations.outlet_id
            )
            WHERE outlet_id IS NOT NULL');

        DB::statement('UPDATE products
            SET owner_id = (
                SELECT owner_id
                FROM outlets
                WHERE outlets.id = products.outlet_id
            )
            WHERE outlet_id IS NOT NULL');

        DB::statement('INSERT INTO outlet_product (outlet_id, product_id, price, stock, is_active, created_at, updated_at)
            SELECT p.outlet_id, p.id, p.price, p.stock, p.is_active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
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

        DB::statement('UPDATE products
            SET outlet_id = (
                    SELECT MIN(op.outlet_id)
                    FROM outlet_product op
                    WHERE op.product_id = products.id
                ),
                price = COALESCE((
                    SELECT op2.price
                    FROM outlet_product op2
                    WHERE op2.product_id = products.id
                    ORDER BY op2.outlet_id
                    LIMIT 1
                ), 0),
                stock = COALESCE((
                    SELECT op3.stock
                    FROM outlet_product op3
                    WHERE op3.product_id = products.id
                    ORDER BY op3.outlet_id
                    LIMIT 1
                ), 0),
                is_active = COALESCE((
                    SELECT op4.is_active
                    FROM outlet_product op4
                    WHERE op4.product_id = products.id
                    ORDER BY op4.outlet_id
                    LIMIT 1
                ), 1)');

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

        DB::statement('UPDATE categories
            SET outlet_id = (
                SELECT o.id
                FROM outlets o
                WHERE o.owner_id = categories.owner_id
                ORDER BY o.id
                LIMIT 1
            )
            WHERE outlet_id IS NULL');

        DB::statement('UPDATE stations
            SET outlet_id = (
                SELECT o.id
                FROM outlets o
                WHERE o.owner_id = stations.owner_id
                ORDER BY o.id
                LIMIT 1
            )
            WHERE outlet_id IS NULL');

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
