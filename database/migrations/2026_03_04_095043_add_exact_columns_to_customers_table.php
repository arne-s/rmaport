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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('exact_payment_condition')->nullable()->after('status');
            $table->string('exact_vat_code')->nullable()->after('exact_payment_condition');
            $table->timestamp('exact_synced_at')->nullable()->after('exact_vat_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['exact_payment_condition', 'exact_vat_code', 'exact_synced_at']);
        });
    }
};
