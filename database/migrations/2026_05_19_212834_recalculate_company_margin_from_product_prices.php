<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Recalculate company_margin using gross margin on sales (Exact definition).
     * Historical imports stored markup % in company_margin before the calculator was fixed.
     */
    public function up(): void
    {
        DB::table('products')
            ->where('company_purchase_price', '>', 0)
            ->where('company_sales_price', '>', 0)
            ->update([
                'company_margin' => DB::raw(
                    'ROUND(((company_sales_price - company_purchase_price) / company_sales_price) * 100, 2)'
                ),
            ]);
    }

    /**
     * Cannot restore previous incorrect margin values.
     */
    public function down(): void
    {
        //
    }
};
