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
        Schema::table('quote_approvals', function (Blueprint $table) {
            $table->dropColumn('internal_approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quote_approvals', function (Blueprint $table) {
            $table->timestamp('internal_approved_at')->nullable();
        });
    }
};
