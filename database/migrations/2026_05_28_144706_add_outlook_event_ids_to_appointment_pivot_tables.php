<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_advisor', function (Blueprint $table): void {
            $table->json('outlook_event_ids')->nullable()->after('outlook_event_id');
        });

        Schema::table('appointment_mechanic', function (Blueprint $table): void {
            $table->json('outlook_event_ids')->nullable()->after('outlook_event_id');
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->json('outlook_event_ids')->nullable()->after('outlook_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_advisor', function (Blueprint $table): void {
            $table->dropColumn('outlook_event_ids');
        });

        Schema::table('appointment_mechanic', function (Blueprint $table): void {
            $table->dropColumn('outlook_event_ids');
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('outlook_event_ids');
        });
    }
};
