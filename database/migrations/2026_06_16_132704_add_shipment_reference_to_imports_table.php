<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('imports') && ! Schema::hasColumn('imports', 'shipment_reference')) {
            Schema::table('imports', function (Blueprint $table): void {
                $table->string('shipment_reference')->nullable()->after('shipment_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('imports') && Schema::hasColumn('imports', 'shipment_reference')) {
            Schema::table('imports', function (Blueprint $table): void {
                $table->dropColumn('shipment_reference');
            });
        }
    }
};
