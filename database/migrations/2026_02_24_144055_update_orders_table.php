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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('type')->nullable()->change();
            $table->string('serial_number')->nullable()->after('rev');

            $table->dropForeign('orders_subsite_id_foreign');
            $table->dropForeign('orders_order_company_id_foreign');
            $table->dropForeign('orders_quote_company_id_foreign');
            $table->dropForeign('orders_quote_id_foreign');

            $table->dropColumn('is_admin_generated');
            $table->dropColumn('delivery_week');
            $table->dropColumn('subsite_id');
            $table->dropColumn('subsite_order');
            $table->dropColumn('session_id');
            $table->dropColumn('quote_id');
            $table->dropColumn('quote_company_id');
            $table->dropColumn('order_company_id');
            $table->dropColumn('public_access_token'); // Move to documents
            $table->dropColumn('doc');
            $table->dropColumn('doc_id');
            $table->dropColumn('doc_path');
            $table->dropColumn('dealer_invoice');
            $table->dropColumn('subsite_direct_quote');

            $table->string('subtype')->nullable()->after('serial_number');

            $table->foreignId('customer_id')
                ->nullable()
                ->after('order_comment')
                ->constrained('customers')
                ->nullOnDelete();

            $table->foreignId('advisor_id')
                ->nullable()
                ->after('company_location_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('fitting_address_id')
                ->nullable()
                ->after('advisor_id')
                ->constrained('addresses')
                ->nullOnDelete();

            $table->foreignId('billing_address_id')
                ->nullable()
                ->after('fitting_address_id')
                ->constrained('addresses')
                ->nullOnDelete();

            $table->foreignId('shipping_address_id')
                ->nullable()
                ->after('billing_address_id')
                ->constrained('addresses')
                ->nullOnDelete();

            $table->foreignId('delivery_advisor_id')
                ->nullable()
                ->after('shipping_address_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('fitting_at')->nullable()->after('updated_at');
            $table->timestamp('quote_created_at')->nullable()->after('fitting_at');
            $table->timestamp('order_created_at')->nullable()->after('quote_created_at');
            $table->timestamp('delivery_at')->nullable()->after('order_created_at');


            $table->dropForeign('orders_payment_id_foreign');
            $table->dropColumn('payment_id');
            $table->dropColumn('deposit_invoice_id');

            $table->foreignId('payment_id')
                ->nullable()
                ->after('deposit_amount')
                ->constrained('payments')
                ->nullOnDelete();

            $table->foreignId('deposit_invoice_id')
                ->nullable()
                ->after('order_id')
                ->constrained('orders')
                ->nullOnDelete();


            $table->string('status')->nullable()->after('type')->change();
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
