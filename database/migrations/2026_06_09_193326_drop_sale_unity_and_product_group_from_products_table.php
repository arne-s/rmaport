<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $columns = array_filter([
                Schema::hasColumn('products', 'sale_unity') ? 'sale_unity' : null,
                Schema::hasColumn('products', 'product_group') ? 'product_group' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'product_group')) {
                $table->string('product_group')->nullable()->after('brand');
            }

            if (! Schema::hasColumn('products', 'sale_unity')) {
                $table->string('sale_unity')->nullable()->after('unit');
            }
        });
    }
};
