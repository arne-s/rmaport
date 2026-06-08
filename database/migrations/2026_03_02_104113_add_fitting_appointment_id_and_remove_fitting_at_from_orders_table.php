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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('fitting_appointment_id')->nullable()->after('fitting_id')->constrained('appointments')->nullOnDelete();
            $table->dropColumn('fitting_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['fitting_appointment_id']);
            $table->dropColumn('fitting_appointment_id');
            $table->timestamp('fitting_at')->nullable()->after('updated_at');
        });
    }
};
