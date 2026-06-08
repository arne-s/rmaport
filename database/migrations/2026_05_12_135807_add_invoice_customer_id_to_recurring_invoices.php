<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoices', function (Blueprint $table): void {
            $table->foreignId('invoice_customer_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('customers')
                ->nullOnDelete();
        });

        // Populate invoice_customer_id from company_id via company_legacy_id mapping
        DB::table('recurring_invoices')
            ->whereNotNull('company_id')
            ->lazyById()
            ->each(function (object $row): void {
                $customer = DB::table('customers')
                    ->where('company_legacy_id', $row->company_id)
                    ->value('id');

                if ($customer !== null) {
                    DB::table('recurring_invoices')
                        ->where('id', $row->id)
                        ->update(['invoice_customer_id' => $customer]);
                }
            });

        // For rows without company_id, fall back to customer_id
        DB::table('recurring_invoices')
            ->whereNull('invoice_customer_id')
            ->whereNotNull('customer_id')
            ->update(['invoice_customer_id' => DB::raw('customer_id')]);
    }

    public function down(): void
    {
        Schema::table('recurring_invoices', function (Blueprint $table): void {
            $table->dropForeign(['invoice_customer_id']);
            $table->dropColumn('invoice_customer_id');
        });
    }
};
