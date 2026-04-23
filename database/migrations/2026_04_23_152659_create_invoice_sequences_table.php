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
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->integer('outlet_id');
            $table->string('date_key'); // Format: 20260423
            $table->integer('last_number')->default(0);
            $table->unique(['outlet_id', 'date_key']); // Pastikan unik
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
    }
};
