<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('type', 32)->nullable()->after('id');
        });

        $this->backfillAddressTypes();
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }

    private function backfillAddressTypes(): void
    {
        if (Schema::hasColumn('customers', 'billing_address_id')) {
            DB::table('customers')
                ->whereNotNull('billing_address_id')
                ->orderBy('id')
                ->chunkById(200, function ($customers): void {
                    foreach ($customers as $customer) {
                        $addressId = (int) $customer->billing_address_id;
                        if ($addressId === 0) {
                            continue;
                        }
                        DB::table('addresses')->where('id', $addressId)->update(['type' => 'billing']);
                    }
                });
        }

        if (Schema::hasColumn('customers', 'shipping_address_id')) {
            DB::table('customers')
                ->whereNotNull('shipping_address_id')
                ->orderBy('id')
                ->chunkById(200, function ($customers): void {
                    foreach ($customers as $customer) {
                        $addressId = (int) $customer->shipping_address_id;
                        if ($addressId === 0) {
                            continue;
                        }
                        DB::table('addresses')
                            ->where('id', $addressId)
                            ->where(function ($q): void {
                                $q->whereNull('type')->orWhere('type', '');
                            })
                            ->update(['type' => 'shipping']);
                    }
                });
        }

        if (Schema::hasColumn('customers', 'address_id')) {
            DB::table('customers')
                ->whereNotNull('address_id')
                ->orderBy('id')
                ->chunkById(200, function ($customers): void {
                    foreach ($customers as $customer) {
                        $addressId = (int) $customer->address_id;
                        if ($addressId === 0) {
                            continue;
                        }
                        DB::table('addresses')
                            ->where('id', $addressId)
                            ->where(function ($q): void {
                                $q->whereNull('type')->orWhere('type', '');
                            })
                            ->update(['type' => 'billing']);
                    }
                });
        }
    }
};
