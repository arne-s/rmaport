<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('filename');
            $table->string('class');
            $table->string('type', 20)->default('file');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_templates');
    }
};
