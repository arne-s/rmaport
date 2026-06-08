<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('microsoft_tokens', function (Blueprint $table): void {
            $table->string('general_category_name')
                ->nullable()
                ->after('calendar_display_name');
        });
    }

    public function down(): void
    {
        Schema::table('microsoft_tokens', function (Blueprint $table): void {
            $table->dropColumn('general_category_name');
        });
    }
};
