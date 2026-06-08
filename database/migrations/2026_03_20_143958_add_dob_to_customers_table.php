<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'dob')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->date('dob')->nullable()->after('last_name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('customers', 'dob')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropColumn('dob');
            });
        }
    }
};
