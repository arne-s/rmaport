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
            $table->foreignId('customer_id')
                ->nullable()
                ->after('id')
                ->constrained('customers')
                ->nullOnDelete();
        });

        $this->backfillCustomerIds();
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }

    private function backfillCustomerIds(): void
    {
        $columns = array_filter([
            Schema::hasColumn('customers', 'billing_address_id') ? 'billing_address_id' : null,
            Schema::hasColumn('customers', 'shipping_address_id') ? 'shipping_address_id' : null,
            Schema::hasColumn('customers', 'address_id') ? 'address_id' : null,
        ]);

        if ($columns === []) {
            return;
        }

        DB::table('customers')
            ->orderBy('id')
            ->chunkById(200, function ($customers) use ($columns): void {
                foreach ($customers as $customer) {
                    foreach ($columns as $column) {
                        $addressId = $customer->{$column} ?? null;
                        if ($addressId === null || (int) $addressId === 0) {
                            continue;
                        }
                        DB::table('addresses')
                            ->where('id', (int) $addressId)
                            ->update(['customer_id' => (int) $customer->id]);
                    }
                }
            });
    }
};
