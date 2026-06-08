<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedInteger('fitting_duration_minutes')->nullable()->after('fitting_at');
            $table->string('outlook_event_id')->nullable()->after('fitting_duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['fitting_duration_minutes', 'outlook_event_id']);
        });
    }
};
