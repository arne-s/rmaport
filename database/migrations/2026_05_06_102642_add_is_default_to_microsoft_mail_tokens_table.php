<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('microsoft_mail_tokens', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('microsoft_email');
        });
    }

    public function down(): void
    {
        Schema::table('microsoft_mail_tokens', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
