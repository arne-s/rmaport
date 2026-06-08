<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(true);
            $table->string('frequency', 32);
            $table->unsignedTinyInteger('start_day');
            $table->date('next_run_date');
            $table->timestamp('last_issued_at')->nullable();
            $table->string('reference')->nullable();
            $table->text('comments')->nullable();
            $table->string('payment_terms', 32);
            $table->string('exact_vat_code');
            $table->string('exact_payment_condition');
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('billing_address_type');
            $table->string('shipping_address_type')->nullable();
            $table->unsignedBigInteger('billing_address_id')->nullable();
            $table->unsignedBigInteger('shipping_address_id')->nullable();
            $table->json('additional')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
