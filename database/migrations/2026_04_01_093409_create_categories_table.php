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
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['name', 'owner_id']); // nama category unik per owner
            $table->index('name'); // untuk search by name cepat
            $table->index('owner_id'); // untuk filter by owner cepat
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
