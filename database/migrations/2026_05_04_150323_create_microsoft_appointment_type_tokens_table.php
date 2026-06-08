<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('microsoft_appointment_type_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_type')->unique();
            $table->foreignId('microsoft_token_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microsoft_appointment_type_tokens');
    }
};
