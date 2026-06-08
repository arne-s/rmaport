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
            $table->boolean('newsletter_subscribed')->default(true)->after('email');
        });

        $businessTypes = [
            'b2b',
            'dealer',
            'uniek_sporten',
        ];

        DB::table('customers')
            ->whereIn('type', $businessTypes)
            ->orderBy('id')
            ->chunkById(200, function ($customers): void {
                foreach ($customers as $customer) {
                    $subscribed = (bool) ($customer->newsletter_subscribed ?? false);
                    if ($customer->billing_address_id !== null) {
                        DB::table('addresses')
                            ->where('id', $customer->billing_address_id)
                            ->update(['newsletter_subscribed' => $subscribed]);
                    }
                    if ($customer->shipping_address_id !== null) {
                        DB::table('addresses')
                            ->where('id', $customer->shipping_address_id)
                            ->update(['newsletter_subscribed' => $subscribed]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropColumn('newsletter_subscribed');
        });
    }
};
