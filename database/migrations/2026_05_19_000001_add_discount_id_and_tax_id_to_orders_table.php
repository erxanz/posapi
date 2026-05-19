<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // DISCOUNT relation
            $table->foreignId('discount_id')
                ->nullable()
                ->after('manual_discount_value')
                ->constrained('discounts')
                ->nullOnDelete();

            // TAX relation
            $table->foreignId('tax_id')
                ->nullable()
                ->after('discount_id')
                ->constrained('taxes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['discount_id']);
            $table->dropColumn('discount_id');

            $table->dropForeign(['tax_id']);
            $table->dropColumn('tax_id');
        });
    }
};

