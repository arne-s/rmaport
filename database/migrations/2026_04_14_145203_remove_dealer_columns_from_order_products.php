<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $opColumns = collect(['dealer_sales_price_base', 'dealer_sales_price_additional', 'dealer_sales_price_subtotal', 'dealer_sales_price_total', 'dealer_margin', 'is_linked'])
            ->filter(fn (string $col): bool => Schema::hasColumn('order_products', $col))
            ->values()
            ->all();

        if ($opColumns) {
            Schema::table('order_products', function (Blueprint $table) use ($opColumns) {
                $table->dropColumn($opColumns);
            });
        }

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
            END
        ');

        $ordersColumns = collect(['dealer_sales_price_base', 'dealer_sales_price_custom', 'dealer_sales_price_subtotal', 'dealer_sales_price_discount', 'dealer_sales_price_total'])
            ->filter(fn (string $col): bool => Schema::hasColumn('orders', $col))
            ->values()
            ->all();

        if ($ordersColumns) {
            Schema::table('orders', function (Blueprint $table) use ($ordersColumns) {
                $table->dropColumn($ordersColumns);
            });
        }

        if (Schema::hasColumn('products', 'dealer_sales_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('dealer_sales_price');
            });
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('company_carousels');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_attribute_options');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('product_attribute_groups');
        Schema::dropIfExists('product_categories');
//        Schema::dropIfExists('sync_jobs');
        Schema::dropIfExists('sync_requests');
        Schema::dropIfExists('transactional_actions');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('dealer_sales_price', 12, 4)->nullable()->after('company_sales_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('dealer_sales_price_base', 12, 4)->default(0)->after('company_sales_price_total');
            $table->decimal('dealer_sales_price_custom', 12, 4)->default(0)->after('dealer_sales_price_base');
            $table->decimal('dealer_sales_price_subtotal', 12, 4)->default(0)->after('dealer_sales_price_custom');
            $table->decimal('dealer_sales_price_discount', 12, 4)->default(0)->after('dealer_sales_price_subtotal');
            $table->decimal('dealer_sales_price_total', 12, 4)->default(0)->after('dealer_sales_price_discount');
        });

        Schema::table('order_products', function (Blueprint $table) {
            $table->decimal('dealer_sales_price_base', 12, 4)->default(0)->after('company_sales_price_credited');
            $table->decimal('dealer_sales_price_additional', 12, 4)->default(0)->after('dealer_sales_price_base');
            $table->decimal('dealer_sales_price_subtotal', 12, 4)->default(0)->after('dealer_sales_price_additional');
            $table->decimal('dealer_sales_price_total', 12, 4)->default(0)->after('dealer_sales_price_subtotal');
            $table->decimal('dealer_margin', 5, 2)->nullable()->after('dealer_sales_price_total');
            $table->boolean('is_linked')->default(false)->after('is_custom');
            $table->unsignedBigInteger('sync_request_id')->nullable();
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
};
