<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rmas', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->after('status');
            $table->index('is_draft');
        });
    }

    public function down(): void
    {
        Schema::table('rmas', function (Blueprint $table) {
            $table->dropIndex(['is_draft']);
            $table->dropColumn('is_draft');
        });
    }
};
