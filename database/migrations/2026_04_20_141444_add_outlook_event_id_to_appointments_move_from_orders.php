<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('outlook_event_id')->nullable()->after('is_active');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('outlook_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('outlook_event_id')->nullable();
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('outlook_event_id');
        });
    }
};
