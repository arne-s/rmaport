<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->foreignId('from_microsoft_mail_token_id')
                ->nullable()
                ->constrained('microsoft_mail_tokens')
                ->nullOnDelete()
                ->after('audience');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_microsoft_mail_token_id');
        });
    }
};
