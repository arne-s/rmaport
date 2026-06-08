<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (Schema::hasColumn('companies', 'delivery_company_id')) {
                $table->dropConstrainedForeignId('delivery_company_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'delivery_company_id')) {
                $table->foreignId('delivery_company_id')
                    ->nullable()
                    ->after('shipping_company_id')
                    ->constrained('companies')
                    ->nullOnDelete();
            }
        });
    }
};
