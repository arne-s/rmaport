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
        Schema::table('main_reports', function (Blueprint $table): void {
            $table->string('customer_debtor_number', 64)->nullable()->after('customer_id');
            $table->string('billing_customer_debtor_number', 64)->nullable()->after('billing_customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('main_reports', function (Blueprint $table): void {
            $table->dropColumn(['customer_debtor_number', 'billing_customer_debtor_number']);
        });
    }
};
