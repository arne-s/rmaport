<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_STATUS_MAP = [
        'shipping' => 'delivery',
        'ready_for_shipping' => 'delivery_planned',
        'shipped' => 'delivered',
    ];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('is_completed')->default(false)->after('order_status');
            $table->index('is_completed');
        });

        foreach (self::LEGACY_STATUS_MAP as $from => $to) {
            DB::table('orders')
                ->where('order_status', $from)
                ->update(['order_status' => $to]);

            DB::table('status_changes')
                ->where('from_status', $from)
                ->update(['from_status' => $to]);

            DB::table('status_changes')
                ->where('to_status', $from)
                ->update(['to_status' => $to]);
        }

        DB::table('orders')
            ->where('subtype', 'service')
            ->where('order_status', 'assembled')
            ->update(['is_completed' => true]);

        DB::table('orders')
            ->where('subtype', 'unit')
            ->where('order_status', 'delivered')
            ->update(['is_completed' => true]);

        DB::table('orders')
            ->where('subtype', 'part')
            ->where('order_status', 'delivered')
            ->update(['is_completed' => true]);

        $partIdsWithLegacyShipped = DB::table('status_changes')
            ->where('to_status', 'delivered')
            ->whereIn('order_id', function ($query): void {
                $query->select('id')
                    ->from('orders')
                    ->where('subtype', 'part');
            })
            ->pluck('order_id')
            ->unique();

        if ($partIdsWithLegacyShipped->isNotEmpty()) {
            DB::table('orders')
                ->whereIn('id', $partIdsWithLegacyShipped)
                ->where('subtype', 'part')
                ->update(['is_completed' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['is_completed']);
            $table->dropColumn('is_completed');
        });

        $reverseMap = array_flip(self::LEGACY_STATUS_MAP);

        foreach ($reverseMap as $from => $to) {
            DB::table('orders')
                ->where('order_status', $from)
                ->where('subtype', 'part')
                ->update(['order_status' => $to]);

            DB::table('status_changes')
                ->where('from_status', $from)
                ->update(['from_status' => $to]);

            DB::table('status_changes')
                ->where('to_status', $from)
                ->update(['to_status' => $to]);
        }
    }
};
