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
            Schema::create('shift_karyawans', function (Blueprint $table) {
        $table->id();
        $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        // Tambahkan relasi ke master shift
        $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('ended_at')->nullable();
        $table->enum('status', ['draft', 'active', 'closed'])->default('draft');
        $table->timestamps();
        $table->index(['outlet_id', 'user_id', 'shift_id', 'status']);
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_karyawans');
    }
};
