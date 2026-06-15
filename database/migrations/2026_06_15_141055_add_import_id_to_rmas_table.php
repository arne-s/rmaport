<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rmas', function (Blueprint $table): void {
            $table->foreignId('import_id')->nullable()->after('customer_id')->constrained('import_rows')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rmas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('import_id');
        });
    }
};
