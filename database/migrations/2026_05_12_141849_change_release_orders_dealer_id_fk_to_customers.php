<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point release_orders.dealer_id at customers. Drops the companies FK first — same InnoDB
     * constraint name otherwise causes MariaDB errno 121 on add.
     */
    public function up(): void
    {
        Schema::table('release_orders', function (Blueprint $table): void {
            $table->dropForeign(['dealer_id']);
        });

        Schema::table('release_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('dealer_id')->nullable()->change();
        });

        DB::table('release_orders as r')
            ->leftJoin('customers as c', 'r.dealer_id', '=', 'c.id')
            ->whereNull('c.id')
            ->update(['r.dealer_id' => null]);

        Schema::table('release_orders', function (Blueprint $table): void {
            $table->foreign('dealer_id')
                ->references('id')
                ->on('customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('release_orders', function (Blueprint $table): void {
            $table->dropForeign(['dealer_id']);
        });

        Schema::table('release_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('dealer_id')->nullable(false)->change();
        });

        Schema::table('release_orders', function (Blueprint $table): void {
            $table->foreign('dealer_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
        });
    }
};
