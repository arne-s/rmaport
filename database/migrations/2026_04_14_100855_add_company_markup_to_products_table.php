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
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'company_markup')) {
                $table->decimal('company_markup', 10, 2)
                    ->default(0)
                    ->after('company_margin');
            }
        });

        DB::table('products')->update([
            'company_markup' => DB::raw('CASE WHEN company_purchase_price IS NULL OR company_purchase_price = 0 THEN 0 ELSE ROUND(((company_sales_price / company_purchase_price) - 1) * 100, 2) END'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'company_markup')) {
                $table->dropColumn('company_markup');
            }
        });
    }
};
