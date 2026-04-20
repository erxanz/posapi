<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('history_transactions', function (Blueprint $table) {
            $table->json('order_items_summary')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('history_transactions', function (Blueprint $table) {
            $table->dropColumn('order_items_summary');
        });
    }
};

