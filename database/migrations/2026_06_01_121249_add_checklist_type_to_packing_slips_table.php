<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packing_slips', function (Blueprint $table): void {
            $table->string('checklist_type')->nullable()->after('checklist');
        });
    }

    public function down(): void
    {
        Schema::table('packing_slips', function (Blueprint $table): void {
            $table->dropColumn('checklist_type');
        });
    }
};
