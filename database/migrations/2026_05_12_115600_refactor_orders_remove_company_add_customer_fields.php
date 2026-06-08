<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('invoice_customer_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('customers')
                ->nullOnDelete();

            $table->foreignId('shipping_customer_id')
                ->nullable()
                ->after('invoice_customer_id')
                ->constrained('customers')
                ->nullOnDelete();
        });

        $avCustomerId = DB::table('customers')
            ->where('type', 'rd')
            ->value('id');

        $orders = DB::table('orders')
            ->select(['id', 'customer_id', 'company_id', 'additional'])
            ->get();

        foreach ($orders as $order) {
            $additional = $order->additional ? json_decode($order->additional, true) : [];
            $billingKey = $additional['billing_address_type_key'] ?? null;
            $shippingKey = $additional['shipping_address_type_key'] ?? null;

            $invoiceCustomerId = $this->resolveCustomerId(
                $billingKey,
                $order->customer_id,
                $order->company_id,
                $avCustomerId
            );

            $shippingCustomerId = match (true) {
                $shippingKey === 'custom'            => null,
                $shippingKey === 'rd'                => $avCustomerId,
                str_starts_with((string) $shippingKey, 'company-') => $this->dealerCustomerIdFromKey($shippingKey),
                default                              => $order->customer_id,
            };

            DB::table('orders')
                ->where('id', $order->id)
                ->update([
                    'invoice_customer_id'  => $invoiceCustomerId,
                    'shipping_customer_id' => $shippingCustomerId,
                ]);
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['fitting_address_id']);
            $table->dropForeign(['billing_address_id']);
            $table->dropForeign(['shipping_address_id']);
            $table->dropForeign(['company_id']);

            $table->dropColumn([
                'company_id',
                'fitting_at',
                'fitting_duration_minutes',
                'order_created_at',
                'fitting_address_id',
                'fitting_location_type',
                'billing_address_id',
                'billing_address_type',
                'shipping_address_id',
                'shipping_address_type',
                'delivery_at',
                'service_at',
                'note_customer_internal',
                'note_company_internal',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['invoice_customer_id']);
            $table->dropForeign(['shipping_customer_id']);
            $table->dropColumn(['invoice_customer_id', 'shipping_customer_id']);

            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('fitting_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->foreignId('billing_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->foreignId('shipping_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->string('billing_address_type')->nullable();
            $table->string('shipping_address_type')->nullable();
            $table->string('fitting_location_type')->nullable();
            $table->timestamp('fitting_at')->nullable();
            $table->integer('fitting_duration_minutes')->nullable();
            $table->timestamp('order_created_at')->nullable();
            $table->timestamp('delivery_at')->nullable();
            $table->timestamp('service_at')->nullable();
            $table->text('note_customer_internal')->nullable();
            $table->text('note_company_internal')->nullable();
        });
    }

    private function resolveCustomerId(
        ?string $billingKey,
        ?int $customerId,
        ?int $companyId,
        ?int $avCustomerId
    ): ?int {
        if ($billingKey === 'rd') {
            return $avCustomerId;
        }

        if (str_starts_with((string) $billingKey, 'company-')) {
            return $this->dealerCustomerIdFromKey($billingKey);
        }

        if ($companyId !== null) {
            return DB::table('customers')
                ->where('company_legacy_id', $companyId)
                ->value('id');
        }

        return $customerId;
    }

    private function dealerCustomerIdFromKey(?string $key): ?int
    {
        if ($key === null) {
            return null;
        }

        $companyId = (int) str_replace('company-', '', $key);

        return DB::table('customers')
            ->where('company_legacy_id', $companyId)
            ->value('id');
    }
};
