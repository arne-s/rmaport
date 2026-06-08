<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_address_id')
                ->nullable()
                ->after('fitting_location_type')
                ->constrained('addresses')
                ->nullOnDelete();
            $table->string('delivery_location_type')->nullable()->after('delivery_address_id');
        });

        if (Schema::hasColumn('orders', 'fitting_appointment_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['fitting_appointment_id']);
            });

            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('fitting_appointment_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('fitting_appointment_id')
                ->nullable()
                ->after('advisor_id')
                ->constrained('appointments')
                ->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['delivery_address_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_address_id', 'delivery_location_type']);
        });
    }
};
