<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_id')->nullable();
            $table->string('mode')->nullable();
            $table->string('description')->nullable();
            $table->text('link')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('payment_link_id')
                ->nullable()
                ->after('payment_method')
                ->constrained('payment_links')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_link_id');
        });

        Schema::dropIfExists('payment_links');
    }
};
