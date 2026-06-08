<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('units', 'serial_numbers');

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('unit_id', 'serial_number_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('serial_number_id', 'unit_id');
        });

        Schema::rename('serial_numbers', 'units');
    }
};
