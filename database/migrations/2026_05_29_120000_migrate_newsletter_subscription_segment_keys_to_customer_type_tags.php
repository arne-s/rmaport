<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remap legacy newsletter segment keys to customer-type-specific tags.
     */
    public function up(): void
    {
        $billingMap = [
            'dealer' => 'dealer_billing',
            'b2b' => 'customer_b2b_billing',
            'uniek_sporten' => 'uniek_sporten_billing',
        ];

        $shippingMap = [
            'dealer' => 'dealer_shipping',
            'b2b' => 'customer_b2b_shipping',
            'uniek_sporten' => 'uniek_sporten_shipping',
        ];

        $legacyBillingKeys = ['dealer_billing', 'customer_b2b'];
        $legacyShippingKeys = ['dealer_location', 'dealer_shipping'];

        foreach ($legacyBillingKeys as $legacyKey) {
            $this->remapSegmentKey($legacyKey, $billingMap);
        }

        foreach ($legacyShippingKeys as $legacyKey) {
            $this->remapSegmentKey($legacyKey, $shippingMap);
        }

        $this->syncBusinessBillingEmailFromCustomer();
    }

    /**
     * @param  array<string, string>  $typeToNewKey
     */
    private function remapSegmentKey(string $legacyKey, array $typeToNewKey): void
    {
        $rows = DB::table('newsletter_subscriptions as ns')
            ->join('customers as c', function ($join): void {
                $join->on('c.id', '=', 'ns.subscribable_id')
                    ->where('ns.subscribable_type', '=', Customer::class);
            })
            ->where('ns.segment_key', $legacyKey)
            ->select([
                'ns.id',
                'ns.subscribable_type',
                'ns.subscribable_id',
                'c.type as customer_type',
            ])
            ->orderBy('ns.id')
            ->get();

        foreach ($rows as $row) {
            $customerType = is_string($row->customer_type) ? $row->customer_type : null;
            $newKey = $customerType !== null ? ($typeToNewKey[$customerType] ?? null) : null;

            if ($newKey === null) {
                DB::table('newsletter_subscriptions')
                    ->where('id', $row->id)
                    ->update([
                        'subscribed' => false,
                        'needs_sync' => true,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            $duplicateExists = DB::table('newsletter_subscriptions')
                ->where('subscribable_type', $row->subscribable_type)
                ->where('subscribable_id', $row->subscribable_id)
                ->where('segment_key', $newKey)
                ->where('id', '!=', $row->id)
                ->exists();

            if ($duplicateExists) {
                DB::table('newsletter_subscriptions')->where('id', $row->id)->delete();

                continue;
            }

            DB::table('newsletter_subscriptions')
                ->where('id', $row->id)
                ->update([
                    'segment_key' => $newKey,
                    'needs_sync' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    private function syncBusinessBillingEmailFromCustomer(): void
    {
        DB::table('customers')
            ->whereIn('type', ['b2b', 'dealer', 'uniek_sporten'])
            ->whereNotNull('billing_address_id')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($customers): void {
                foreach ($customers as $customer) {
                    DB::table('addresses')
                        ->where('id', $customer->billing_address_id)
                        ->where(function ($query): void {
                            $query->whereNull('email')->orWhere('email', '=', '');
                        })
                        ->update([
                            'email' => $customer->email,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible: legacy segment keys are not restored.
    }
};
