<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('microsoft_tokens', function (Blueprint $table) {
            $table->string('calendar_id')->nullable()->after('microsoft_email');
            $table->string('calendar_name')->nullable()->after('calendar_id');
        });
    }

    public function down(): void
    {
        Schema::table('microsoft_tokens', function (Blueprint $table) {
            $table->dropColumn(['calendar_id', 'calendar_name']);
        });
    }
};
