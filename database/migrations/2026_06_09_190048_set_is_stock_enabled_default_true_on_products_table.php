<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->update(['is_stock_enabled' => 1]);

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedTinyInteger('is_stock_enabled')->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedTinyInteger('is_stock_enabled')->default(0)->change();
        });
    }
};
