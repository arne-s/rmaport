<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            if (! Schema::hasColumn('imports', 'import_date')) {
                $table->date('import_date')->nullable()->after('reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            if (Schema::hasColumn('imports', 'import_date')) {
                $table->dropColumn('import_date');
            }
        });
    }
};
