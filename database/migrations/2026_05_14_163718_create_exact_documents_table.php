<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exact_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('exact_id')->unique();
            $table->integer('exact_type')->nullable();
            $table->string('exact_type_description')->nullable();
            $table->string('exact_subject')->nullable();
            $table->string('mapped_type');
            $table->date('document_date')->nullable();
            $table->timestamp('exact_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exact_documents');
    }
};
