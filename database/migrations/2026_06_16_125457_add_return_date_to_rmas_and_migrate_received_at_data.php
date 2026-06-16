<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rmas', function (Blueprint $table): void {
            $table->date('return_date')->nullable()->after('packing_slip_number');
        });

        DB::table('import_rows')
            ->whereNotNull('received_at')
            ->whereNull('return_date')
            ->update([
                'return_date' => DB::raw('DATE(received_at)'),
            ]);

        DB::table('rmas')
            ->whereNotNull('received_at')
            ->whereNull('return_date')
            ->update([
                'return_date' => DB::raw('DATE(received_at)'),
            ]);

        DB::table('rmas')
            ->where('status', '!=', 'received')
            ->update(['received_at' => null]);

        Schema::table('import_rows', function (Blueprint $table): void {
            $table->dropColumn('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table): void {
            $table->dateTime('received_at')->nullable()->after('return_reason');
        });

        DB::table('import_rows')
            ->whereNotNull('return_date')
            ->update([
                'received_at' => DB::raw('return_date'),
            ]);

        DB::table('rmas')
            ->whereNotNull('return_date')
            ->whereNull('received_at')
            ->update([
                'received_at' => DB::raw('return_date'),
            ]);

        Schema::table('rmas', function (Blueprint $table): void {
            $table->dropColumn('return_date');
        });
    }
};
