<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'serial_number_text')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('serial_number_text');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'serial_number_text')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->string('serial_number_text')->nullable()->after('reference');
            });
        }
    }
};
