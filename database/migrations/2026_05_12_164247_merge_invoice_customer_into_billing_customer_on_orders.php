<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (Schema::hasColumn('orders', 'invoice_customer_id') && Schema::hasColumn('orders', 'billing_customer_id')) {
            DB::table('orders')
                ->whereNull('billing_customer_id')
                ->whereNotNull('invoice_customer_id')
                ->update(['billing_customer_id' => DB::raw('invoice_customer_id')]);
        }

        if (! Schema::hasColumn('orders', 'invoice_customer_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['invoice_customer_id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('invoice_customer_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (Schema::hasColumn('orders', 'invoice_customer_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('invoice_customer_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('customers')
                ->nullOnDelete();
        });

        DB::table('orders')
            ->whereNotNull('billing_customer_id')
            ->update(['invoice_customer_id' => DB::raw('billing_customer_id')]);
    }
};
