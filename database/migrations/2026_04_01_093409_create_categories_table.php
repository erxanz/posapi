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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['name', 'outlet_id']); // nama category unik per outlet
            $table->index('name'); // untuk search by name cepat
            $table->index('outlet_id'); // untuk filter by outlet cepat
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
