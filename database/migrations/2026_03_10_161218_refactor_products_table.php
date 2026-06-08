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
            $table->dropForeign('products_price_table_id_foreign');
            $table->dropForeign('products_product_attribute_group_id_foreign');
            $table->dropForeign('products_brand_id_foreign');

            $table->dropColumn([
                'price_type',
                'is_individually_visible',
                'is_group_product',
                'is_bundle_parent',
                'is_bundle_child',
                'is_group_child',
                'is_visible_portal',
                'is_visible_admin',
                'price_table_id',
                'min_width',
                'max_width',
                'min_height',
                'max_height',
                'droma_product_id',
                'product_attribute_group_id',
                'bymichel_product_id',
                'custom_upsell',
                'item_options',
                'supplier_params',
                'brand_id',
                'is_upsell',
                'usp_1',
                'usp_2',
                'usp_3',
                'sort_oud',
                'video_url',
            ]);

            $table->string('description')->nullable()->after('name')->change();
            $table->string('type')->nullable()->after('description');
            $table->string('unit')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
