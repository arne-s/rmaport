<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_invoices', function (Blueprint $table): void {
            $table->string('orderable_type')->nullable()->change();
            $table->unsignedBigInteger('orderable_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_invoices', function (Blueprint $table): void {
            $table->string('orderable_type')->nullable(false)->change();
            $table->unsignedBigInteger('orderable_id')->nullable(false)->change();
        });
    }
};
