<?php

use App\Models\ExactVATCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $default = ExactVATCode::DEFAULT_SALES_VAT_CODE;

        DB::table('customers')
            ->where(function ($q): void {
                $q->whereNull('exact_vat_code')
                    ->orWhere('exact_vat_code', '');
            })
            ->update(['exact_vat_code' => $default]);
    }

    public function down(): void
    {
        // One-way data fix; cannot know prior nulls vs explicit '1'.
    }
};
