<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('appointment_advisor');
        Schema::dropIfExists('appointment_mechanic');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('microsoft_appointment_type_tokens');
        Schema::dropIfExists('microsoft_category_mappings');
        Schema::dropIfExists('microsoft_tokens');

        if (Schema::hasTable('main_reports') && Schema::hasColumn('main_reports', 'fitting_appointment_at')) {
            Schema::table('main_reports', function (Blueprint $table): void {
                $table->dropColumn('fitting_appointment_at');
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table): void {
                foreach ([
                    'fitting_at',
                    'fitting_duration_minutes',
                    'delivery_at',
                    'service_at',
                    'outlook_event_id',
                    'fitting_appointment_id',
                ] as $column) {
                    if (Schema::hasColumn('orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        //
    }
};
