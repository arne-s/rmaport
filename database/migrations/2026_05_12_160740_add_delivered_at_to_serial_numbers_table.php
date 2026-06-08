<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_numbers', function (Blueprint $table): void {
            $table->timestamp('delivered_at')->nullable()->after('order_date');
        });

        $orderType = OrderType::Order->value;
        $mainType = OrderType::Main->value;
        $delivered = OrderStatus::Delivered->value;

        DB::update(
            <<<SQL
            UPDATE serial_numbers AS sn
            INNER JOIN orders AS sales ON sales.id = sn.order_id AND sales.type = ?
            INNER JOIN orders AS mains ON mains.id = sales.main_id AND mains.type = ?
            INNER JOIN (
                SELECT order_id, MIN(created_at) AS first_delivered_at
                FROM status_changes
                WHERE order_product_id IS NULL AND to_status = ?
                GROUP BY order_id
            ) AS sc ON sc.order_id = mains.id
            SET sn.delivered_at = sc.first_delivered_at
            WHERE sn.delivered_at IS NULL
            SQL,
            [$orderType, $mainType, $delivered],
        );
    }

    public function down(): void
    {
        Schema::table('serial_numbers', function (Blueprint $table): void {
            $table->dropColumn('delivered_at');
        });
    }
};
