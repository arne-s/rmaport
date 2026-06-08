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
        \Illuminate\Support\Facades\DB::table('order_products')
            ->whereNull('status')
            ->update(['status' => 'initial']);

        Schema::table('order_products', function (Blueprint $table): void {
            $table->string('status')->default('initial')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table): void {
            $table->string('status')->default(null)->nullable()->change();
        });
    }
};
