<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_templates', function (Blueprint $table): void {
            $table->foreignId('source_id')->nullable()->after('description')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_id');
        });
    }
};
