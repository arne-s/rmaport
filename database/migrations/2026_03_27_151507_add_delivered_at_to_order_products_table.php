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
        Schema::table('order_products', function (Blueprint $table) {
            $table->dateTime('delivered_at')
                ->nullable()
                ->after('status');

            $table->index(['product_id', 'delivered_at'], 'order_products_product_id_delivered_at_idx');
            $table->index(['purchase_order_id', 'delivered_at'], 'order_products_purchase_order_id_delivered_at_idx');
            $table->index(['order_id', 'delivered_at'], 'order_products_order_id_delivered_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropIndex('order_products_product_id_delivered_at_idx');
            $table->dropIndex('order_products_purchase_order_id_delivered_at_idx');
            $table->dropIndex('order_products_order_id_delivered_at_idx');
            $table->dropColumn('delivered_at');
        });
    }
};
