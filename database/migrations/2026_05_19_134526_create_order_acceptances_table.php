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
        // This migration is superseded by 2026_05_19_134540_create_order_acceptances_table.php
        // It is intentionally a no-op to avoid "table already exists" errors when running migrations.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally keep this as a safe drop to support rollbacks.
        // Only drops the table if it exists.
        if (Schema::hasTable('order_acceptances')) {
            Schema::dropIfExists('order_acceptances');
        }
    }
};
