<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dateTime('customer_datetime_start')->nullable()->after('notify_advisor');
            $table->integer('customer_duration')->nullable()->after('customer_datetime_start');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn(['customer_datetime_start', 'customer_duration']);
        });
    }
};
