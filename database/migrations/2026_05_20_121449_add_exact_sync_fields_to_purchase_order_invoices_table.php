<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasExactIdUniqueIndex()) {
            Schema::table('purchase_order_invoices', function (Blueprint $table): void {
                $table->dropUnique(['exact_id']);
            });
        }

        Schema::table('purchase_order_invoices', function (Blueprint $table): void {
            $table->string('exact_id')->nullable()->change();
        });

        DB::table('purchase_order_invoices')
            ->where('exact_id', 'like', 'manual-%')
            ->update(['exact_id' => null]);

        if (! $this->hasExactIdUniqueIndex()) {
            Schema::table('purchase_order_invoices', function (Blueprint $table): void {
                $table->unique('exact_id');
            });
        }

        Schema::table('purchase_order_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_order_invoices', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('exact_id');
            }
            if (! Schema::hasColumn('purchase_order_invoices', 'exact_synced_at')) {
                $table->timestamp('exact_synced_at')->nullable()->after('paid_at');
            }
            if (! Schema::hasColumn('purchase_order_invoices', 'exact_error_at')) {
                $table->timestamp('exact_error_at')->nullable()->after('exact_synced_at');
            }
            if (! Schema::hasColumn('purchase_order_invoices', 'exact_error_message')) {
                $table->text('exact_error_message')->nullable()->after('exact_error_at');
            }
        });
    }

    public function down(): void
    {
        if ($this->hasExactIdUniqueIndex()) {
            Schema::table('purchase_order_invoices', function (Blueprint $table): void {
                $table->dropUnique(['exact_id']);
            });
        }

        Schema::table('purchase_order_invoices', function (Blueprint $table): void {
            $columns = array_filter([
                Schema::hasColumn('purchase_order_invoices', 'paid_at') ? 'paid_at' : null,
                Schema::hasColumn('purchase_order_invoices', 'exact_synced_at') ? 'exact_synced_at' : null,
                Schema::hasColumn('purchase_order_invoices', 'exact_error_at') ? 'exact_error_at' : null,
                Schema::hasColumn('purchase_order_invoices', 'exact_error_message') ? 'exact_error_message' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('purchase_order_invoices', function (Blueprint $table): void {
            $table->string('exact_id')->nullable(false)->unique()->change();
        });
    }

    private function hasExactIdUniqueIndex(): bool
    {
        foreach (Schema::getIndexes('purchase_order_invoices') as $index) {
            if (($index['unique'] ?? false) && in_array('exact_id', $index['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
};
