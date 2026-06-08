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
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'search_code')) {
                $table->string('search_code')->nullable();
            }

            if (! Schema::hasColumn('products', 'is_fraction_allowed_item')) {
                $table->boolean('is_fraction_allowed_item')->default(false);
            }
            if (! Schema::hasColumn('products', 'is_purchase_item')) {
                $table->boolean('is_purchase_item')->default(false);
            }
            if (! Schema::hasColumn('products', 'is_sales_item')) {
                $table->boolean('is_sales_item')->default(false);
            }
            if (! Schema::hasColumn('products', 'is_on_demand_item')) {
                $table->boolean('is_on_demand_item')->default(false);
            }
            if (! Schema::hasColumn('products', 'is_webshop_item')) {
                $table->boolean('is_webshop_item')->default(false);
            }

            if (! Schema::hasColumn('products', 'exact_sales_vat_code_id')) {
                $table->foreignId('exact_sales_vat_code_id')
                    ->nullable()
                    ->constrained('exact_vat_codes')
                    ->nullOnDelete();
            }

            if (Schema::hasColumn('products', 'is_unavailable')) {
                $table->dropColumn('is_unavailable');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'exact_sales_vat_code_id')) {
                $table->dropForeign(['exact_sales_vat_code_id']);
                $table->dropColumn('exact_sales_vat_code_id');
            }

            foreach ([
                'is_webshop_item',
                'is_on_demand_item',
                'is_sales_item',
                'is_purchase_item',
                'is_fraction_allowed_item',
                'search_code',
            ] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (! Schema::hasColumn('products', 'is_unavailable')) {
                $table->unsignedTinyInteger('is_unavailable')->default(0)->after('is_stock_enabled');
            }
        });
    }
};
