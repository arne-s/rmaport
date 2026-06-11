<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_import_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('base_url', 500);
            $table->string('username', 191);
            $table->text('api_token');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('form_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_import_connection_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_form_id');
            $table->string('source_form_title');
            $table->boolean('is_active')->default(true);
            $table->string('uid_source_field_id', 20)->nullable();
            $table->string('uid_fallback_prefix', 10)->default('FI');
            $table->timestamp('last_imported_at')->nullable();
            $table->unsignedInteger('last_imported_count')->default(0);
            $table->timestamps();

            $table->unique(['form_import_connection_id', 'source_form_id'], 'form_imports_connection_form_unique');
        });

        Schema::create('form_import_field_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_import_id')->constrained()->cascadeOnDelete();
            $table->string('source_field_id', 20);
            $table->string('source_field_label')->nullable();
            $table->string('rma_field', 50)->nullable();
            $table->boolean('append_to_notes')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['form_import_id', 'source_field_id'], 'form_import_field_mappings_unique');
        });

        Schema::create('form_import_entry_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_form_id');
            $table->unsignedBigInteger('source_entry_id');
            $table->foreignId('rma_id')->constrained()->cascadeOnDelete();
            $table->timestamp('imported_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['source_form_id', 'source_entry_id'], 'form_import_entry_logs_unique');
        });

        Schema::create('form_import_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_entry_id')->default(0);
            $table->timestamps();

            $table->unique('form_import_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_import_states');
        Schema::dropIfExists('form_import_entry_logs');
        Schema::dropIfExists('form_import_field_mappings');
        Schema::dropIfExists('form_imports');
        Schema::dropIfExists('form_import_connections');
    }
};
