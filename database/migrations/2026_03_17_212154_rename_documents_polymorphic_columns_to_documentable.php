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
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['model_type', 'model_id']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->renameColumn('model_type', 'documentable_type');
            $table->renameColumn('model_id', 'documentable_id');
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->index(['documentable_type', 'documentable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['documentable_type', 'documentable_id']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->renameColumn('documentable_type', 'model_type');
            $table->renameColumn('documentable_id', 'model_id');
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->index(['model_type', 'model_id']);
        });
    }
};
