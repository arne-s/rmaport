<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('description2')->nullable()->after('description');
            $table->string('brand')->nullable()->after('description2');
            $table->string('product_group')->nullable()->after('brand');
            $table->string('sub_group')->nullable()->after('product_group');
            $table->string('manufacturer')->nullable()->after('sub_group');
            $table->string('stock_location')->nullable()->after('manufacturer');
            $table->string('mediamarkt_nr_nl')->nullable()->after('stock_location');
            $table->string('mediamarkt_nr_bnl')->nullable()->after('mediamarkt_nr_nl');
            $table->string('ean_1')->nullable()->after('mediamarkt_nr_bnl');
            $table->string('ean_2')->nullable()->after('ean_1');
            $table->string('dl_code')->nullable()->after('ean_2');
            $table->string('hs_code')->nullable()->after('dl_code');
            $table->string('krefel_nr')->nullable()->after('hs_code');
            $table->string('bol_nr')->nullable()->after('krefel_nr');
            $table->string('coolblue_nr')->nullable()->after('bol_nr');
            $table->string('sale_unity')->nullable()->after('unit');
            $table->string('battery')->nullable()->after('sale_unity');
            $table->decimal('pcb', 12, 6)->nullable()->after('battery');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'description2',
                'brand',
                'product_group',
                'sub_group',
                'manufacturer',
                'stock_location',
                'mediamarkt_nr_nl',
                'mediamarkt_nr_bnl',
                'ean_1',
                'ean_2',
                'dl_code',
                'hs_code',
                'krefel_nr',
                'bol_nr',
                'coolblue_nr',
                'sale_unity',
                'battery',
                'pcb',
            ]);
        });
    }
};
