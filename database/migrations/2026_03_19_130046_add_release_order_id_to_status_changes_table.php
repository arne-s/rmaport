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
        Schema::table('status_changes', function (Blueprint $table) {
            $table->foreignId('release_order_id')
                ->nullable()
                ->after('purchase_order_id')
                ->constrained('release_orders')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('status_changes', function (Blueprint $table) {
            $table->dropForeign(['release_order_id']);
        });
    }
};
