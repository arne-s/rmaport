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
        Schema::table('appointments', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->boolean('notify_customer')->default(true)->after('description');
            $table->dropColumn('travel_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['description', 'notify_customer']);
            $table->string('travel_time')->nullable()->after('title');
        });
    }
};
