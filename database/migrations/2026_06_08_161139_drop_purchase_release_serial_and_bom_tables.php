<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropForeignColumn('order_products', 'purchase_order_id');
        $this->dropForeignColumn('order_products', 'release_order_id');

        if (Schema::hasTable('order_products') && Schema::hasColumn('order_products', 'purchased_at')) {
            Schema::table('order_products', function (Blueprint $table): void {
                $table->dropColumn('purchased_at');
            });
        }

        $this->dropForeignColumn('status_changes', 'purchase_order_id');
        $this->dropForeignColumn('status_changes', 'release_order_id');

        $this->dropForeignColumn('products', 'supplier_id');

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'supplier_product_uid')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('supplier_product_uid');
            });
        }

        $this->dropForeignColumn('orders', 'supplier_id');
        $this->dropForeignColumn('orders', 'serial_number_id');

        $this->dropForeignColumn('product_attribute_groups', 'supplier_id');
        $this->dropForeignColumn('sync_requests', 'supplier_id');

        if (Schema::hasTable('main_reports') && Schema::hasColumn('main_reports', 'serial_number')) {
            Schema::table('main_reports', function (Blueprint $table): void {
                $table->dropColumn('serial_number');
            });
        }

        Schema::dropIfExists('bill_of_material_product');
        Schema::dropIfExists('bill_of_materials');
        Schema::dropIfExists('serial_number_events');
        Schema::dropIfExists('serial_numbers');
        Schema::dropIfExists('release_orders');
        Schema::dropIfExists('purchase_order_invoices');
        Schema::dropIfExists('purchase_order_confirmations');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }

    public function down(): void
    {
        //
    }

    private function dropForeignColumn(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $foreignKeys = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [DB::getDatabaseName(), $table, $column],
        );

        foreach ($foreignKeys as $foreignKey) {
            Schema::table($table, function (Blueprint $blueprint) use ($foreignKey): void {
                $blueprint->dropForeign($foreignKey->CONSTRAINT_NAME);
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropColumn($column);
        });
    }
};
