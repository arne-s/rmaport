<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $table): void {
            $table->string('product_name')->nullable()->after('ean_nr');
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table): void {
            $table->dropColumn('product_name');
        });
    }
};
