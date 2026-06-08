<?php

use App\Enums\OrderType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill {@see orders.deposit_invoice_id} on main rows from the latest deposit invoice row sharing {@see orders.main_id}.
     */
    public function up(): void
    {
        $main = OrderType::Main->value;
        $depositInvoice = OrderType::DepositInvoice->value;

        DB::statement(
            'UPDATE orders AS m
            INNER JOIN (
                SELECT main_id, MAX(id) AS deposit_invoice_row_id
                FROM orders
                WHERE type = ? AND main_id IS NOT NULL
                GROUP BY main_id
            ) AS d ON d.main_id = m.id
            SET m.deposit_invoice_id = d.deposit_invoice_row_id
            WHERE m.type = ?
              AND m.deposit_invoice_id IS NULL',
            [$depositInvoice, $main],
        );
    }

    /**
     * No safe rollback: null values cannot be distinguished from never-filled rows.
     */
    public function down(): void
    {
    }
};
