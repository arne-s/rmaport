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
            $table->dropIndex(['quote_id', 'superseded_at']);
            $table->dropColumn('superseded_at');
        });

        Schema::table('quote_approvals', function (Blueprint $table) {
            $table->unique('quote_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quote_approvals', function (Blueprint $table) {
            $table->dropUnique(['quote_id']);
        });

        Schema::table('quote_approvals', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable();
            $table->index(['quote_id', 'superseded_at']);
        });
    }
};
