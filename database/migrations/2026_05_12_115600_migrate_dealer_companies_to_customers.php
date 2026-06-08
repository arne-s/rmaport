<?php

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_legacy_id')->nullable()->after('id');
        });

        $companies = DB::table('companies')
            ->where('type', 'invoice')
            ->get();

        foreach ($companies as $company) {
            $shippingAddressId = $company->address_id;

            if ($company->shipping_company_id) {
                $shippingCompany = DB::table('companies')
                    ->where('id', $company->shipping_company_id)
                    ->first();
                if ($shippingCompany?->address_id) {
                    $shippingAddressId = $shippingCompany->address_id;
                }
            }

            DB::table('customers')->insert([
                'company_legacy_id'       => $company->id,
                'status'                  => CustomerStatus::Active->value,
                'type'                    => CustomerType::Dealer->value,
                'company_name'            => $company->name,
                'first_name'              => $company->first_name,
                'middle_name'             => $company->middle_name,
                'last_name'               => $company->last_name,
                'email'                   => $company->email,
                'phone_number'            => $company->phone_number,
                'vat'                     => $company->vat,
                'kvk'                     => $company->kvk,
                'iban'                    => $company->iban,
                'bic'                     => $company->bic ?? null,
                'debtor_number'           => $company->debtor_number,
                'payment_terms'           => $company->payment_terms,
                'exact_id'                => $company->exact_id,
                'exact_payment_condition' => $company->exact_payment_condition,
                'exact_vat_code'          => $company->exact_vat_code,
                'exact_synced_at'         => $company->exact_synced_at,
                'discount_percentage'     => $company->company_sales_price_discount_percentage,
                'billing_address_id'      => $company->address_id,
                'shipping_address_id'     => $shippingAddressId,
                'created_at'              => $company->created_at,
                'updated_at'              => now(),
            ]);
        }
    }

    public function down(): void
    {
        $legacyIds = DB::table('customers')
            ->whereNotNull('company_legacy_id')
            ->pluck('company_legacy_id');

        DB::table('customers')
            ->whereNotNull('company_legacy_id')
            ->delete();

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('company_legacy_id');
        });
    }
};
