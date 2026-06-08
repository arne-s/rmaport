<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('travel_time_before', 5)->default('00:00')->after('customer_duration');
            $table->string('travel_time_after', 5)->default('00:00')->after('travel_time_before');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn(['travel_time_before', 'travel_time_after']);
        });
    }
};
