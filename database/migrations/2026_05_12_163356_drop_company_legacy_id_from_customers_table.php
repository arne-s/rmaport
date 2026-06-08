<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const ADDRESS_KEY_FIELDS = [
        'billing_address_type_key',
        'shipping_address_type_key',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'company_legacy_id')) {
            return;
        }

        $legacyToCustomer = DB::table('customers')
            ->whereNotNull('company_legacy_id')
            ->pluck('id', 'company_legacy_id');

        $this->rewriteJsonAddressKeysInTable('orders', $legacyToCustomer);
        $this->rewriteJsonAddressKeysInTable('purchase_orders', $legacyToCustomer);
        $this->rewriteJsonAddressKeysInTable('release_orders', $legacyToCustomer);

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('company_legacy_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'company_legacy_id')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_legacy_id')->nullable()->after('id');
        });
    }

    private function rewriteJsonAddressKeysInTable(string $table, Collection $legacyToCustomer): void
    {
        if (! Schema::hasColumn($table, 'additional')) {
            return;
        }

        foreach (DB::table($table)->whereNotNull('additional')->orderBy('id')->cursor() as $row) {
            $additional = json_decode($row->additional, true);
            if (! is_array($additional)) {
                continue;
            }

            $changed = false;
            foreach (self::ADDRESS_KEY_FIELDS as $field) {
                if (! isset($additional[$field]) || ! is_string($additional[$field])) {
                    continue;
                }
                $normalized = $this->normalizeCompanyPrefixedAddressKey($additional[$field], $legacyToCustomer);
                if ($normalized === null) {
                    continue;
                }
                $additional[$field] = $normalized;
                $changed = true;
            }

            if ($changed) {
                DB::table($table)->where('id', $row->id)->update([
                    'additional' => json_encode($additional),
                ]);
            }
        }
    }

    private function normalizeCompanyPrefixedAddressKey(string $value, Collection $legacyToCustomer): ?string
    {
        if (! str_starts_with($value, 'company-')) {
            return null;
        }

        $suffix = (int) substr($value, strlen('company-'));
        $customerId = (int) ($legacyToCustomer->get($suffix) ?? $suffix);

        return 'customer-' . $customerId;
    }
};
