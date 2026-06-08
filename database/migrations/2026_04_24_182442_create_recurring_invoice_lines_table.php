<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 12, 2);
            $table->decimal('company_sales_price_discount_percentage', 8, 2)->default(0);
            $table->text('attribute_summary_basic')->nullable();
            $table->decimal('company_purchase_price_base', 14, 4);
            $table->decimal('company_sales_price_base', 14, 4);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoice_lines');
    }
};
