<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('chair_type', 64)->nullable()->after('additional');
        });

        DB::statement(
            'UPDATE products SET chair_type = NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(additional, \'$.chair_type\'))), \'\') ' .
            'WHERE additional IS NOT NULL AND JSON_EXTRACT(additional, \'$.chair_type\') IS NOT NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('chair_type');
        });
    }
};
