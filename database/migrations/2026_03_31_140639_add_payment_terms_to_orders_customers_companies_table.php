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
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'payment_terms')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->string('payment_terms')->nullable()->after('billing_address_type');
            });
        }

        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'payment_terms')) {
            $hasLegacyDepositColumn = Schema::hasColumn('customers', 'is_deposit_required');

            Schema::table('customers', function (Blueprint $table) use ($hasLegacyDepositColumn): void {
                $column = $table->string('payment_terms')->nullable();
                if ($hasLegacyDepositColumn) {
                    $column->after('is_deposit_required');
                }
            });
        }

        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'payment_terms')) {
            $hasLegacyDepositColumn = Schema::hasColumn('companies', 'is_deposit_required');

            Schema::table('companies', function (Blueprint $table) use ($hasLegacyDepositColumn): void {
                $column = $table->string('payment_terms')->nullable();
                if ($hasLegacyDepositColumn) {
                    $column->after('is_deposit_required');
                }
            });
        }

        if (Schema::hasColumn('customers', 'is_deposit_required') && Schema::hasColumn('customers', 'payment_terms')) {
            DB::table('customers')
                ->whereNull('payment_terms')
                ->update([
                    'payment_terms' => DB::raw("CASE WHEN is_deposit_required = 1 THEN 'split_50_50' ELSE 'postpay' END"),
                ]);
        }

        if (Schema::hasColumn('companies', 'is_deposit_required') && Schema::hasColumn('companies', 'payment_terms')) {
            DB::table('companies')
                ->whereNull('payment_terms')
                ->update([
                    'payment_terms' => DB::raw("CASE WHEN is_deposit_required = 1 THEN 'split_50_50' ELSE 'postpay' END"),
                ]);
        }

        if (Schema::hasColumn('orders', 'payment_terms')) {
            DB::table('orders')
                ->whereNull('payment_terms')
                ->update(['payment_terms' => 'split_50_50']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'payment_terms')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('payment_terms');
            });
        }

        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'payment_terms')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropColumn('payment_terms');
            });
        }

        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'payment_terms')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('payment_terms');
            });
        }
    }
};
