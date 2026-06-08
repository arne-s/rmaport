<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('main_reports')) {
            return;
        }

        if (Schema::hasColumn('main_reports', 'company_id') && ! Schema::hasColumn('main_reports', 'invoice_customer_id')) {
            Schema::table('main_reports', function (Blueprint $table): void {
                $table->renameColumn('company_id', 'invoice_customer_id');
            });
        }

        if (! Schema::hasColumn('main_reports', 'invoice_customer_id')) {
            return;
        }

        $this->remapLegacyCompanyIdsToCustomerIds();

        DB::table('main_reports')
            ->whereNotNull('invoice_customer_id')
            ->whereNotIn('invoice_customer_id', DB::table('customers')->select('id'))
            ->update(['invoice_customer_id' => null]);

        Schema::table('main_reports', function (Blueprint $table): void {
            $table->foreign('invoice_customer_id')
                ->references('id')
                ->on('customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('main_reports')) {
            return;
        }

        if (! Schema::hasColumn('main_reports', 'invoice_customer_id')) {
            return;
        }

        Schema::table('main_reports', function (Blueprint $table): void {
            $table->dropForeign(['invoice_customer_id']);
        });

        if (! Schema::hasColumn('main_reports', 'company_id')) {
            Schema::table('main_reports', function (Blueprint $table): void {
                $table->renameColumn('invoice_customer_id', 'company_id');
            });
        }
    }

    private function remapLegacyCompanyIdsToCustomerIds(): void
    {
        $pairs = DB::table('customers')
            ->whereNotNull('company_legacy_id')
            ->pluck('id', 'company_legacy_id');

        foreach ($pairs as $legacyCompanyId => $customerId) {
            DB::table('main_reports')
                ->where('invoice_customer_id', (int) $legacyCompanyId)
                ->whereNotIn('invoice_customer_id', DB::table('customers')->select('id'))
                ->update(['invoice_customer_id' => (int) $customerId]);
        }
    }
};
