<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $table): void {
            $table->foreignId('customer_id')
                ->nullable()
                ->after('import_id')
                ->constrained()
                ->nullOnDelete();
        });

        DB::table('import_rows')
            ->join('sources', 'sources.id', '=', 'import_rows.source_id')
            ->whereNull('import_rows.customer_id')
            ->whereNotNull('sources.customer_id')
            ->update([
                'import_rows.customer_id' => DB::raw('sources.customer_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
