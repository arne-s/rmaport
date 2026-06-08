<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('price_change_logs')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM `price_change_logs` WHERE Field = 'type'");

        if ($column === null || str_contains((string) $column->Type, 'company_markup')) {
            return;
        }

        DB::statement("ALTER TABLE `price_change_logs` MODIFY `type` ENUM('company_purchase_price','company_sales_price','company_margin','company_markup') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('price_change_logs')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE `price_change_logs` MODIFY `type` ENUM('company_purchase_price','company_sales_price','company_margin') NOT NULL");
    }
};
