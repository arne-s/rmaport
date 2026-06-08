<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('location_customer_id')
                ->nullable()
                ->after('order_id')
                ->constrained('customers')
                ->nullOnDelete();

            $table->string('location_type')->nullable()->after('location_customer_id');

            $table->text('location_custom')->nullable()->after('location_type');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['location_customer_id']);
            $table->dropColumn(['location_customer_id', 'location_type', 'location_custom']);
        });
    }
};
