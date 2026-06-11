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
        Schema::table('rmas', function (Blueprint $table) {
            $table->string('assessment', 30)->nullable()->after('complaint');
        });
    }

    public function down(): void
    {
        Schema::table('rmas', function (Blueprint $table) {
            $table->dropColumn('assessment');
        });
    }
};
