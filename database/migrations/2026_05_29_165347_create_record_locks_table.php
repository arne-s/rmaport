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
        Schema::create('record_locks', function (Blueprint $table) {
            $table->id();
            $table->morphs('lockable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('locked_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['lockable_type', 'lockable_id']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_locks');
    }
};
