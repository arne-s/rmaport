<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_description')->nullable();
            $table->string('customer_nr')->nullable();
            $table->string('customer_order_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('assignment_nr')->nullable();
            $table->string('ean_nr', 20)->nullable();
            $table->boolean('is_doa')->default(false);
            $table->date('purchase_date')->nullable();
            $table->date('return_date')->nullable();
            $table->text('return_reason')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->string('accessories')->nullable();
            $table->timestamps();

            $table->index('reference');
            $table->index('customer_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
