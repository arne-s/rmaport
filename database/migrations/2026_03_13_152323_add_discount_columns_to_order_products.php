<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $table->decimal('company_sales_price_discount_percentage', 5, 2)
                ->default(0)
                ->after('company_sales_price_additional');
            $table->decimal('company_sales_price_discount', 12, 4)
                ->default(0)
                ->after('company_sales_price_discount_percentage');
        });

        DB::unprepared('DROP TRIGGER IF EXISTS trg_order_products_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_order_products_before_update');

        DB::unprepared('
            CREATE TRIGGER trg_order_products_before_insert
            BEFORE INSERT ON order_products
            FOR EACH ROW
            BEGIN
                SET NEW.company_purchase_price_subtotal = NEW.company_purchase_price_base + NEW.company_purchase_price_additional;
                SET NEW.company_purchase_price_total = NEW.company_purchase_price_subtotal * COALESCE(NEW.qty, 1);

                SET NEW.company_sales_price_discount_percentage = COALESCE(NEW.company_sales_price_discount_percentage, 0);
                SET NEW.company_sales_price_discount = (NEW.company_sales_price_base + NEW.company_sales_price_additional)
                    * (NEW.company_sales_price_discount_percentage / 100);
                SET NEW.company_sales_price_subtotal = (NEW.company_sales_price_base + NEW.company_sales_price_additional)
                    - NEW.company_sales_price_discount;
                SET NEW.company_sales_price_total = NEW.company_sales_price_subtotal * COALESCE(NEW.qty, 1);

                SET NEW.dealer_sales_price_subtotal = NEW.dealer_sales_price_base + NEW.dealer_sales_price_additional;
                SET NEW.dealer_sales_price_total = NEW.dealer_sales_price_subtotal * COALESCE(NEW.qty, 1);
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_order_products_before_update
            BEFORE UPDATE ON order_products
            FOR EACH ROW
            BEGIN
                SET NEW.company_purchase_price_subtotal = NEW.company_purchase_price_base + NEW.company_purchase_price_additional;
                SET NEW.company_purchase_price_total = NEW.company_purchase_price_subtotal * COALESCE(NEW.qty, 1);

                SET NEW.company_sales_price_discount_percentage = COALESCE(NEW.company_sales_price_discount_percentage, 0);
                SET NEW.company_sales_price_discount = (NEW.company_sales_price_base + NEW.company_sales_price_additional)
                    * (NEW.company_sales_price_discount_percentage / 100);
                SET NEW.company_sales_price_subtotal = (NEW.company_sales_price_base + NEW.company_sales_price_additional)
                    - NEW.company_sales_price_discount;
                SET NEW.company_sales_price_total = NEW.company_sales_price_subtotal * COALESCE(NEW.qty, 1);

                SET NEW.dealer_sales_price_subtotal = NEW.dealer_sales_price_base + NEW.dealer_sales_price_additional;
                SET NEW.dealer_sales_price_total = NEW.dealer_sales_price_subtotal * COALESCE(NEW.qty, 1);
            END
        ');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_order_products_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_order_products_before_update');

        DB::unprepared('
            CREATE TRIGGER trg_order_products_before_insert
            BEFORE INSERT ON order_products
            FOR EACH ROW
            BEGIN
                SET NEW.company_purchase_price_subtotal = NEW.company_purchase_price_base + NEW.company_purchase_price_additional;
                SET NEW.company_purchase_price_total = NEW.company_purchase_price_subtotal * COALESCE(NEW.qty, 1);

                SET NEW.company_sales_price_subtotal = NEW.company_sales_price_base + NEW.company_sales_price_additional;
                SET NEW.company_sales_price_total = NEW.company_sales_price_subtotal * COALESCE(NEW.qty, 1);

                SET NEW.dealer_sales_price_subtotal = NEW.dealer_sales_price_base + NEW.dealer_sales_price_additional;
                SET NEW.dealer_sales_price_total = NEW.dealer_sales_price_subtotal * COALESCE(NEW.qty, 1);
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_order_products_before_update
            BEFORE UPDATE ON order_products
            FOR EACH ROW
            BEGIN
                SET NEW.company_purchase_price_subtotal = NEW.company_purchase_price_base + NEW.company_purchase_price_additional;
                SET NEW.company_purchase_price_total = NEW.company_purchase_price_subtotal * COALESCE(NEW.qty, 1);

                SET NEW.company_sales_price_subtotal = NEW.company_sales_price_base + NEW.company_sales_price_additional;
                SET NEW.company_sales_price_total = NEW.company_sales_price_subtotal * COALESCE(NEW.qty, 1);

                SET NEW.dealer_sales_price_subtotal = NEW.dealer_sales_price_base + NEW.dealer_sales_price_additional;
                SET NEW.dealer_sales_price_total = NEW.dealer_sales_price_subtotal * COALESCE(NEW.qty, 1);
            END
        ');

        Schema::table('order_products', function (Blueprint $table) {
            $table->dropColumn(['company_sales_price_discount_percentage', 'company_sales_price_discount']);
        });
    }
};
