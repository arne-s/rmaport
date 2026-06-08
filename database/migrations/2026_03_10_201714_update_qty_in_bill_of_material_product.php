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
        Schema::table('bill_of_material_product', function (Blueprint $table) {
            $table->renameColumn('quantity', 'qty');
            $table->decimal('qty', 6, 2)->default(1)->after('product_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bill_of_material_product', function (Blueprint $table) {
            $table->renameColumn('qty', 'quantity');
            $table->integer('quantity')->default(1)->after('product_id')->change();
        });
    }
};
