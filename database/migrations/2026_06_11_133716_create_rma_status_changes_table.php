<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rma_status_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rma_id')->index();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->unsignedBigInteger('changed_by')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('rma_id')
                ->references('id')
                ->on('rmas')
                ->cascadeOnDelete();

            $table->foreign('changed_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rma_status_changes');
    }
};
