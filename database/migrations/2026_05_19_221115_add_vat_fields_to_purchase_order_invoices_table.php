<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_invoices', function (Blueprint $table): void {
            $table->decimal('vat_amount', 12, 2)->nullable()->after('amount');
            $table->decimal('total_amount_inc_vat', 12, 2)->nullable()->after('vat_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_invoices', function (Blueprint $table): void {
            $table->dropColumn(['vat_amount', 'total_amount_inc_vat']);
        });
    }
};
