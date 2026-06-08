<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_numbers', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('note');
            $table->string('customer_name')->nullable()->after('name');
            $table->string('customer_debtor_number')->nullable()->after('customer_name');
            $table->dateTime('order_date')->nullable()->after('customer_debtor_number');
            $table->string('order_number')->nullable()->after('order_date');
            $table->decimal('total_price_inc', 10, 2)->nullable()->after('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('serial_numbers', function (Blueprint $table): void {
            $table->dropColumn(['name', 'customer_name', 'customer_debtor_number', 'order_date', 'order_number', 'total_price_inc']);
        });
    }
};
