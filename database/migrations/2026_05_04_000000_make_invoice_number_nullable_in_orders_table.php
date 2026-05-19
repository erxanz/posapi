<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make orders.invoice_number nullable (SQLite-compatible).
     *
     * SQLite doesn't support ALTER TABLE ... MODIFY, so we rebuild the table.
     */
    public function up(): void
    {
        // Only needed if invoice_number isn't nullable yet; rebuild is safe.

        Schema::disableForeignKeyConstraints();

        // 1) Create new table with desired schema.
        Schema::create('orders__tmp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('invoice_number')->unique()->nullable();

            $table->integer('subtotal_price')->default(0);
            $table->integer('discount_amount')->default(0);

            $table->enum('manual_discount_type', ['percentage', 'nominal'])->nullable();
            $table->integer('manual_discount_value')->nullable();

            $table->integer('tax_amount')->default(0);
            $table->json('tax_breakdown')->nullable();

            $table->integer('total_price')->default(0);

            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->json('logs')->nullable();

            $table->timestamps();

            $table->index(['outlet_id', 'invoice_number', 'status']);
        });

        // 2) Copy data.
        // SQLite will ignore type differences; NULL values will be preserved.
        Schema::table('orders__tmp', function (Blueprint $table) {
            // no-op; ensures table exists in blueprint context
        });

        // Copy columns that exist in both schemas.
        $columns = [
            'id',
            'outlet_id',
            'user_id',
            'customer_name',
            'invoice_number',
            'subtotal_price',
            'discount_amount',
            'manual_discount_type',
            'manual_discount_value',
            'tax_amount',
            'tax_breakdown',
            'total_price',
            'status',
            'logs',
            'created_at',
            'updated_at',
        ];

        $colList = implode(',', $columns);

        // Use DB::statement without raw MODIFY.
        \Illuminate\Support\Facades\DB::statement(
            "INSERT INTO orders__tmp ($colList) SELECT $colList FROM orders;"
        );

        // 3) Swap tables.
        Schema::drop('orders');
        Schema::rename('orders__tmp', 'orders');

        Schema::enableForeignKeyConstraints();

        // NOTE: Some SQLite versions don't fully recreate indexes/constraints
        // exactly as expected; Laravel migrations handle it sufficiently for most use-cases.
    }

    /**
     * Reverse: make orders.invoice_number NOT NULL again.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('orders__tmp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();

            // NOT NULL on down.
            $table->string('invoice_number')->unique()->nullable(false);

            $table->integer('subtotal_price')->default(0);
            $table->integer('discount_amount')->default(0);

            $table->enum('manual_discount_type', ['percentage', 'nominal'])->nullable();
            $table->integer('manual_discount_value')->nullable();

            $table->integer('tax_amount')->default(0);
            $table->json('tax_breakdown')->nullable();

            $table->integer('total_price')->default(0);

            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->json('logs')->nullable();

            $table->timestamps();

            $table->index(['outlet_id', 'invoice_number', 'status']);
        });

        $columns = [
            'id',
            'outlet_id',
            'user_id',
            'customer_name',
            'invoice_number',
            'subtotal_price',
            'discount_amount',
            'manual_discount_type',
            'manual_discount_value',
            'tax_amount',
            'tax_breakdown',
            'total_price',
            'status',
            'logs',
            'created_at',
            'updated_at',
        ];

        $colList = implode(',', $columns);

        \Illuminate\Support\Facades\DB::statement(
            "INSERT INTO orders__tmp ($colList) SELECT $colList FROM orders;"
        );

        Schema::drop('orders');
        Schema::rename('orders__tmp', 'orders');

        Schema::enableForeignKeyConstraints();
    }
};

