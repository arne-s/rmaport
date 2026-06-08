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
        Schema::create('main_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->string('customer_name')->nullable();
            $table->string('dealer_name')->nullable();
            $table->string('order_uid')->nullable();
            $table->string('chair_type')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('advisor_name')->nullable();

            $table->decimal('sale_price_total', 12, 2)->nullable();
            $table->decimal('purchase_price_frame', 12, 2)->nullable();
            $table->decimal('purchase_price_parts', 12, 2)->nullable();
            $table->decimal('margin_price', 12, 2)->nullable();

            $table->string('invoice_user')->nullable();

            $table->date('frame_purchase_order_at')->nullable();
            $table->unsignedTinyInteger('frame_purchase_order_month')->nullable();
            $table->smallInteger('frame_purchase_order_year')->nullable();
            $table->string('frame_purchase_order_month_year', 16)->nullable();

            $table->dateTime('fitting_appointment_at')->nullable();
            $table->dateTime('quote_sent_at')->nullable();
            $table->dateTime('quote_approved_at')->nullable();
            $table->dateTime('order_sent_at')->nullable();
            $table->dateTime('ready_for_pickup_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('invoice_sent_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('main_reports');
    }
};
