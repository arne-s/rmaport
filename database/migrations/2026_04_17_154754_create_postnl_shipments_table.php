<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postnl_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('barcode');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_company')->nullable();
            $table->string('recipient_street')->nullable();
            $table->string('recipient_house_nr')->nullable();
            $table->string('recipient_house_nr_addition')->nullable();
            $table->string('recipient_postcode')->nullable();
            $table->string('recipient_city')->nullable();
            $table->string('recipient_country', 2)->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postnl_shipments');
    }
};
