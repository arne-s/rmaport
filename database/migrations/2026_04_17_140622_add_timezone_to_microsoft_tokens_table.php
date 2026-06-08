<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('microsoft_tokens', function (Blueprint $table) {
            $table->string('timezone')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('microsoft_tokens', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
