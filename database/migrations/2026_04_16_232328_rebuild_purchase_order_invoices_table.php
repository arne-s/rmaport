<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropColumn('invoice_id');
        });

        Schema::dropIfExists('purchase_order_invoices');

        Schema::create('purchase_order_invoices', function (Blueprint $table) {
            $table->id();
            $table->morphs('orderable');
            $table->unsignedBigInteger('main_id')->nullable()->index();
            $table->string('exact_id')->unique();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('EUR');
            $table->date('entry_date');
            $table->date('due_date')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('document_id')->nullable();
            $table->timestamps();

            $table->foreign('main_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_invoices');
    }
};
