<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->where('type', 'credit_invoice')
            ->whereNotNull('caption')
            ->update(['caption' => null]);
    }

    public function down(): void
    {
        // Caption was copied from the parent invoice; cannot be restored reliably.
    }
};
