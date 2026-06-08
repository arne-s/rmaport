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
        Schema::create('exact_requests', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 20)->default('outbound');
            $table->string('service', 120)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('endpoint', 255)->nullable();
            $table->text('url')->nullable();
            $table->json('request_headers')->nullable();
            $table->longText('request_body')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('succeeded')->default(false);
            $table->string('error_class', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index('requested_at');
            $table->index('response_status');
            $table->index('service');
            $table->index('endpoint');
            $table->index('succeeded');
            $table->index('correlation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exact_requests');
    }
};
