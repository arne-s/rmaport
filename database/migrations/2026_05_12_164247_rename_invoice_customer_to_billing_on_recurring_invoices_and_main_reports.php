<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recurring_invoices') && Schema::hasColumn('recurring_invoices', 'invoice_customer_id')) {
            Schema::table('recurring_invoices', function (Blueprint $table): void {
                $table->dropForeign(['invoice_customer_id']);
            });
            Schema::table('recurring_invoices', function (Blueprint $table): void {
                $table->renameColumn('invoice_customer_id', 'billing_customer_id');
            });
            Schema::table('recurring_invoices', function (Blueprint $table): void {
                $table->foreign('billing_customer_id')
                    ->references('id')
                    ->on('customers')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('main_reports') && Schema::hasColumn('main_reports', 'invoice_customer_id')) {
            Schema::table('main_reports', function (Blueprint $table): void {
                $table->dropForeign(['invoice_customer_id']);
            });
            Schema::table('main_reports', function (Blueprint $table): void {
                $table->renameColumn('invoice_customer_id', 'billing_customer_id');
            });
            Schema::table('main_reports', function (Blueprint $table): void {
                $table->foreign('billing_customer_id')
                    ->references('id')
                    ->on('customers')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('main_reports') && Schema::hasColumn('main_reports', 'billing_customer_id') && ! Schema::hasColumn('main_reports', 'invoice_customer_id')) {
            Schema::table('main_reports', function (Blueprint $table): void {
                $table->dropForeign(['billing_customer_id']);
            });
            Schema::table('main_reports', function (Blueprint $table): void {
                $table->renameColumn('billing_customer_id', 'invoice_customer_id');
            });
            Schema::table('main_reports', function (Blueprint $table): void {
                $table->foreign('invoice_customer_id')
                    ->references('id')
                    ->on('customers')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('recurring_invoices') && Schema::hasColumn('recurring_invoices', 'billing_customer_id') && ! Schema::hasColumn('recurring_invoices', 'invoice_customer_id')) {
            Schema::table('recurring_invoices', function (Blueprint $table): void {
                $table->dropForeign(['billing_customer_id']);
            });
            Schema::table('recurring_invoices', function (Blueprint $table): void {
                $table->renameColumn('billing_customer_id', 'invoice_customer_id');
            });
            Schema::table('recurring_invoices', function (Blueprint $table): void {
                $table->foreign('invoice_customer_id')
                    ->references('id')
                    ->on('customers')
                    ->nullOnDelete();
            });
        }
    }
};
