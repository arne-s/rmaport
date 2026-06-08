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
        Schema::table('price_change_logs', function (Blueprint $table) {
            $table->enum('method', ['manual', 'bulk'])
                ->default('manual')
                ->after('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('price_change_logs', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};
