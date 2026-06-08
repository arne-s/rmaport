<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('microsoft_category_mappings', function (Blueprint $table): void {
            $table->string('outlook_category_id')->nullable()->after('category_name');
            $table->index(['microsoft_token_id', 'outlook_category_id'], 'ms_cat_token_outlook_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('microsoft_category_mappings', function (Blueprint $table): void {
            $table->dropIndex('ms_cat_token_outlook_id_index');
            $table->dropColumn('outlook_category_id');
        });
    }
};
