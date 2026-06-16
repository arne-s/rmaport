<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('filename');
            $table->string('class');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('exports');

        Schema::create('exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->unique()->constrained('imports')->cascadeOnDelete();
            $table->string('uid')->unique();
            $table->string('file_disk')->default('local');
            $table->string('file_name');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::table('import_templates', function (Blueprint $table): void {
            $table->foreignId('export_template_id')
                ->nullable()
                ->after('source_id')
                ->constrained('export_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('export_template_id');
        });

        Schema::dropIfExists('exports');

        Schema::create('exports', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('completed_at')->nullable();
            $table->string('file_disk');
            $table->string('file_name')->nullable();
            $table->string('exporter');
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('successful_rows')->default(0);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::dropIfExists('export_templates');
    }
};
