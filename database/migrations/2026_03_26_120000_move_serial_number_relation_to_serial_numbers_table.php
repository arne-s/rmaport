<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('serial_numbers', 'order_id')) {
            Schema::table('serial_numbers', function (Blueprint $table) {
                $table->foreignId('order_id')
                    ->nullable()
                    ->after('owner_id')
                    ->constrained('orders', 'id')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
                $table->unique('order_id');
            });
        }

        if (Schema::hasColumn('orders', 'serial_number_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('serial_number_id');
            });
        }

        if (! Schema::hasColumn('orders', 'serial_number_text')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('serial_number_text')->nullable()->after('reference');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'serial_number_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('serial_number_id')
                    ->nullable()
                    ->after('rev')
                    ->constrained('serial_numbers', 'id')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('orders', 'serial_number_text')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('serial_number_text');
            });
        }

        if (Schema::hasColumn('serial_numbers', 'order_id')) {
            Schema::table('serial_numbers', function (Blueprint $table) {
                $table->dropUnique('serial_numbers_order_id_unique');
                $table->dropConstrainedForeignId('order_id');
            });
        }
    }
};

