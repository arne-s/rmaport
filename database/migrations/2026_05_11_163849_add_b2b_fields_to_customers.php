<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('last_name');
            $table->string('vat')->nullable()->after('company_name');
            $table->string('kvk')->nullable()->after('vat');
            $table->decimal('discount_percentage', 5, 2)->default(0)->after('kvk');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'vat', 'kvk', 'discount_percentage']);
        });
    }
};
