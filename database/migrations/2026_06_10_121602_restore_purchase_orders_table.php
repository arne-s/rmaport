<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_orders')) {
            return;
        }

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('reference_number');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('main_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('initial');
            $table->boolean('is_cancelled')->nullable();
            $table->json('additional')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
