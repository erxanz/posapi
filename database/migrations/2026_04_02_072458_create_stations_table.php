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
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name'); // nama stasiun (misal: kichen, bar, kasir)
            $table->timestamps();

            $table->index(['owner_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('station_id')->references('id')->on('stations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['station_id']);
        });

        Schema::dropIfExists('stations');
    }
};
