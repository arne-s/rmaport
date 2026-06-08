<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('serial_numbers', 'order_sub_type')) {
            Schema::table('serial_numbers', function (Blueprint $table): void {
                $table->string('order_sub_type', 32)
                    ->default('unit')
                    ->after('serial_number')
                    ->index();
            });
        }

        DB::table('serial_numbers')->update(['order_sub_type' => 'unit']);

        foreach (['serial_numbers_serial_number_unique', 'units_serial_number_unique'] as $indexName) {
            $exists = collect(DB::select('SHOW INDEX FROM serial_numbers WHERE Key_name = ?', [$indexName]))->isNotEmpty();
            if ($exists) {
                DB::statement("ALTER TABLE serial_numbers DROP INDEX {$indexName}");
            }
        }

        if (! collect(DB::select('SHOW INDEX FROM serial_numbers WHERE Key_name = ?', ['serial_numbers_main_id_unique']))->isNotEmpty()) {
            Schema::table('serial_numbers', function (Blueprint $table): void {
                $table->unique('main_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('serial_numbers', function (Blueprint $table): void {
            $table->dropUnique(['main_id']);
        });

        Schema::table('serial_numbers', function (Blueprint $table): void {
            $table->unique('serial_number');
        });

        Schema::table('serial_numbers', function (Blueprint $table): void {
            $table->dropIndex(['order_sub_type']);
            $table->dropColumn('order_sub_type');
        });
    }
};
