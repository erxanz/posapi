<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->dateTime('reserved_until')->nullable()->after('status');
        });

        // (opsional) index untuk query waktu
        Schema::table('tables', function (Blueprint $table) {
            $table->index('reserved_until');
        });
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->dropIndex(['reserved_until']);
            $table->dropColumn('reserved_until');
        });
    }
};

