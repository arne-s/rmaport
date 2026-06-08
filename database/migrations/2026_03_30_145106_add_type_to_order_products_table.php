<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->string('type', 32)->nullable()->after('product_id');
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                'UPDATE order_products AS op INNER JOIN products AS p ON op.product_id = p.id SET op.type = p.type WHERE op.product_id IS NOT NULL'
            );

            return;
        }

        $pairs = DB::table('order_products')
            ->join('products', 'order_products.product_id', '=', 'products.id')
            ->whereNotNull('order_products.product_id')
            ->select('order_products.id', 'products.type')
            ->get();

        foreach ($pairs as $row) {
            DB::table('order_products')
                ->where('id', $row->id)
                ->update(['type' => $row->type]);
        }
    }

    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
