<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rmas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('uid', 20)->unique();
            $table->string('reference', 100)->nullable();
            $table->string('order_nr', 50)->nullable();
            $table->string('barcode', 50)->nullable();
            $table->string('defect_id', 50)->nullable();
            $table->string('global_id', 50)->nullable();

            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('ean', 20)->nullable();
            $table->string('article_number', 50)->nullable();
            $table->string('brand', 50)->nullable();
            $table->string('product_group', 100)->nullable();
            $table->text('product_name')->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('imei', 50)->nullable();
            $table->text('product_condition')->nullable();
            $table->string('graded_type', 50)->nullable();
            $table->text('accessories')->nullable();

            $table->string('return_reason', 100)->nullable();
            $table->string('return_sub_reason', 100)->nullable();

            $table->string('location_name', 200)->nullable();
            $table->string('location_code', 50)->nullable();
            $table->string('external_location_id', 20)->nullable();

            $table->string('language', 30)->nullable();
            $table->date('purchased_at')->nullable();
            $table->string('packing_slip_number', 100)->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->text('complaint')->nullable();
            $table->text('service')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('open');
            $table->boolean('is_draft')->default(false);

            $table->boolean('reminder')->default(false);
            $table->boolean('is_warranty')->default(false);
            $table->boolean('is_processed')->default(false);
            $table->boolean('is_refurbish')->default(false);
            $table->boolean('is_doa')->default(false);
            $table->boolean('is_invoiced')->default(false);

            $table->dateTime('received_at')->nullable();
            $table->dateTime('reminded_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('is_draft');
            $table->index('customer_id');
            $table->index('order_nr');
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rmas');
    }
};
