<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_import_field_mappings', function (Blueprint $table) {
            $table->text('fixed_value')->nullable()->after('source_field_label');
        });
    }

    public function down(): void
    {
        Schema::table('form_import_field_mappings', function (Blueprint $table) {
            $table->dropColumn('fixed_value');
        });
    }
};
