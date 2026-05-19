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
        Schema::create('order_acceptances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();

            // for kitchen vs cashier acceptance (optional, tapi berguna)
            $table->string('scope')->default('cashier');

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('printed_at')->nullable();

            $table->timestamps();

            $table->unique(['order_id', 'scope']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_acceptances');
    }
};
