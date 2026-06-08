<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('billing_address_id')->nullable()->constrained('addresses');
            $table->foreignId('shipping_address_id')->nullable()->constrained('addresses');
            $table->string('delivery_address_type')->default('contact');
        });

        // Seed billing_address_id from existing address_id
        DB::table('customers')->update([
            'billing_address_id' => DB::raw('address_id'),
        ]);

        // Create an empty shipping address row for each customer
        foreach (DB::table('customers')->cursor() as $customer) {
            $addressId = DB::table('addresses')->insertGetId([
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('customers')->where('id', $customer->id)
                ->update(['shipping_address_id' => $addressId]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['billing_address_id']);
            $table->dropForeign(['shipping_address_id']);
            $table->dropColumn(['billing_address_id', 'shipping_address_id', 'delivery_address_type']);
        });
    }
};
