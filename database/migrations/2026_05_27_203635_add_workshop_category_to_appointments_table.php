<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('microsoft_category_mapping_id')
                ->nullable()
                ->after('travel_time_after')
                ->constrained('microsoft_category_mappings')
                ->nullOnDelete();
            $table->boolean('workshop_category_by_user')->default(false)->after('microsoft_category_mapping_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('microsoft_category_mapping_id');
            $table->dropColumn('workshop_category_by_user');
        });
    }
};
