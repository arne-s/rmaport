<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('shipping_address_id')->nullable()->after('last_name')->constrained('addresses')->nullOnDelete();
            $table->foreignId('billing_address_id')->nullable()->after('shipping_address_id')->constrained('addresses')->nullOnDelete();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['region_id']);
            $table->dropForeign(['country_id']);
            $table->dropForeign(['order_id']);
            $table->dropForeign(['company_id']);
            $table->dropColumn([
                'street',
                'house_number',
                'house_number_addition',
                'city',
                'postcode',
                'region_id',
                'country_id',
                'order_id',
                'company_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['shipping_address_id']);
            $table->dropForeign(['billing_address_id']);
            $table->dropColumn(['shipping_address_id', 'billing_address_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->after('last_name');
            $table->unsignedBigInteger('company_id')->after('order_id');
            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->string('street')->nullable()->after('last_name');
            $table->string('house_number')->nullable()->after('street');
            $table->string('house_number_addition')->nullable()->after('house_number');
            $table->string('city')->nullable()->after('house_number_addition');
            $table->string('postcode')->nullable()->after('city');
            $table->unsignedBigInteger('region_id')->nullable()->after('postcode');
            $table->unsignedBigInteger('country_id')->nullable()->after('region_id');
            $table->foreign('region_id')->references('id')->on('regions');
            $table->foreign('country_id')->references('id')->on('countries');
        });
    }
};
