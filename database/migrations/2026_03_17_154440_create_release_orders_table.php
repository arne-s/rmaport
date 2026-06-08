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
        Schema::create('release_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('main_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('dealer_id')->constrained('companies')->cascadeOnDelete();
            $table->string('status')->default('initial');
            $table->timestamp('sent_at')->nullable();
            $table->boolean('is_cancelled')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('release_orders');
    }
};
