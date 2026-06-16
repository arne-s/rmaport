<?php

use App\Models\ImportBatch;
use App\Support\ImportBatchNumberSequence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->string('uid')->nullable()->unique()->after('id');
        });

        ImportBatch::query()
            ->orderBy('id')
            ->each(function (ImportBatch $batch): void {
                $batch->update([
                    'uid' => ImportBatchNumberSequence::next(),
                ]);
            });

        Schema::table('imports', function (Blueprint $table): void {
            $table->string('uid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->dropUnique(['uid']);
            $table->dropColumn('uid');
        });
    }
};
