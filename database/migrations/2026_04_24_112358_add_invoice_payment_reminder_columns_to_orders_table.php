<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('first_reminder_sent_at')->nullable()->after('expires_at');
            $table->timestamp('second_reminder_sent_at')->nullable()->after('first_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['first_reminder_sent_at', 'second_reminder_sent_at']);
        });
    }
};
