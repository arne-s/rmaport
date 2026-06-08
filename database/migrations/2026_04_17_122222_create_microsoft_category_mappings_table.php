<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('microsoft_category_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('microsoft_token_id')->constrained()->cascadeOnDelete();
            $table->string('category_name');
            $table->string('category_color')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['microsoft_token_id', 'category_name'], 'ms_cat_token_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microsoft_category_mappings');
    }
};
