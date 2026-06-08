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
        Schema::create('model_has_notes', function (Blueprint $table) {
            $table->foreignId('note_id')->constrained('notes')->cascadeOnDelete();
            $table->morphs('model');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['note_id', 'model_id', 'model_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_has_notes');
    }
};
