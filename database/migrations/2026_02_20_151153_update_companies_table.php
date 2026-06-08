<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign('companies_delivery_country_id_foreign');
            $table->dropForeign('companies_open_quote_id_foreign');
            $table->dropForeign('companies_showroom_id_foreign');
            $table->dropForeign('companies_user_id_foreign');
            $table->dropForeign('companies_country_id_foreign');

            $table->dropColumn('is_deposit_required');
            $table->dropColumn('showroom_id');
            $table->dropColumn('open_quote_id');
            $table->dropColumn('design');
            $table->dropColumn('user_id');
            $table->dropColumn('street');
            $table->dropColumn('house_number');
            $table->dropColumn('house_number_addition');
            $table->dropColumn('postcode');
            $table->dropColumn('city');
            $table->dropColumn('country_id');
            $table->dropColumn('use_alternate_delivery_address');
            $table->dropColumn('delivery_name');
            $table->dropColumn('delivery_phone_number');
            $table->dropColumn('delivery_postcode');
            $table->dropColumn('delivery_house_number');
            $table->dropColumn('delivery_house_number_addition');
            $table->dropColumn('delivery_country_id');
            $table->dropColumn('delivery_street');
            $table->dropColumn('delivery_city');
            $table->dropColumn('delivery_comment');
            $table->dropColumn('has_agreed_terms');
            $table->dropColumn('invoice_platform');

            $table->string('billing_first_name')->nullable()->after('mobile_number');
            $table->string('billing_middle_name')->nullable()->after('billing_first_name');
            $table->string('billing_last_name')->nullable()->after('billing_middle_name');
            $table->string('billing_email')->nullable()->after('billing_last_name');

            $table->foreignId('billing_address_id')
                ->nullable()
                ->after('billing_email')
                ->constrained('addresses')
                ->nullOnDelete();

            $table->decimal('company_sales_price_discount_percentage', 12, 4)->default(0)->comment('Verkoop | Kortingspercentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
