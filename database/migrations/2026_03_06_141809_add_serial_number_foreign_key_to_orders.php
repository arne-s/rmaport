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
        Schema::table('units', function (Blueprint $table) {
            $table->string('serial_number')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('serial_number');
            $table->foreignId('unit_id')->nullable()->after('rev')->constrained('units', 'id')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
            $table->string('serial_number')->nullable()->after('rev');
        });


        Schema::table('units', function (Blueprint $table) {
            $table->string('serial_number')->change();
        });
    }
};
