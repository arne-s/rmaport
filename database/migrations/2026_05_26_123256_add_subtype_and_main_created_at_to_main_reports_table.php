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
        Schema::table('main_reports', function (Blueprint $table): void {
            $table->string('subtype', 32)->nullable()->after('order_uid');
            $table->dateTime('main_created_at')->nullable()->after('subtype');
            $table->index('main_created_at');
            $table->index('subtype');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('main_reports', function (Blueprint $table): void {
            $table->dropIndex(['main_created_at']);
            $table->dropIndex(['subtype']);
            $table->dropColumn(['subtype', 'main_created_at']);
        });
    }
};
