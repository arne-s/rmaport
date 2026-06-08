<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_before_update');

        DB::unprepared('
            CREATE TRIGGER trg_orders_before_insert
            BEFORE INSERT ON orders
            FOR EACH ROW
            BEGIN
                DECLARE cp_base DECIMAL(20,4);
                DECLARE cs_base DECIMAL(20,4);
                DECLARE product_count INT;

                SELECT COUNT(*) INTO product_count FROM order_products WHERE order_id = NEW.id;

                IF product_count > 0 THEN
                    SELECT
                        SUM(
                            CASE
                                WHEN order_products_parent_id IS NULL THEN company_purchase_price_total
                                WHEN is_configurable = 1 THEN company_purchase_price_total
                                ELSE company_purchase_price_total * (SELECT qty FROM order_products p WHERE p.id = order_products.order_products_parent_id)
                            END
                        ),
                        SUM(
                            CASE
                                WHEN order_products_parent_id IS NULL THEN company_sales_price_total
                                WHEN is_configurable = 1 THEN company_sales_price_total
                                ELSE company_sales_price_total * (SELECT qty FROM order_products p WHERE p.id = order_products.order_products_parent_id)
                            END
                        )
                    INTO cp_base, cs_base
                    FROM order_products
                    WHERE order_id = NEW.id;

                    SET NEW.company_purchase_price_base = cp_base;
                    SET NEW.company_purchase_price_total = cp_base + COALESCE(NEW.company_purchase_price_discount, 0);
                    SET NEW.company_sales_price_base = cs_base;
                    SET NEW.company_sales_price_total = cs_base + COALESCE(NEW.company_sales_price_discount, 0);
                ELSE
                    SET NEW.company_purchase_price_base = 0;
                    SET NEW.company_purchase_price_total = 0 + COALESCE(NEW.company_purchase_price_discount, 0);
                    SET NEW.company_sales_price_base = 0;
                    SET NEW.company_sales_price_total = 0 + COALESCE(NEW.company_sales_price_discount, 0);
                END IF;
            END
        ');

        DB::unprepared('
            CREATE TRIGGER trg_orders_before_update
            BEFORE UPDATE ON orders
            FOR EACH ROW
            BEGIN
                DECLARE cp_base DECIMAL(20,4);
                DECLARE cs_base DECIMAL(20,4);
                DECLARE product_count INT;

                SELECT COUNT(*) INTO product_count FROM order_products WHERE order_id = NEW.id;

                IF product_count > 0 THEN
                    SELECT
                        SUM(
                            CASE
                                WHEN order_products_parent_id IS NULL THEN company_purchase_price_total
                                WHEN is_configurable = 1 THEN company_purchase_price_total
                                ELSE company_purchase_price_total * (SELECT qty FROM order_products p WHERE p.id = order_products.order_products_parent_id)
                            END
                        ),
                        SUM(
                            CASE
                                WHEN order_products_parent_id IS NULL THEN company_sales_price_total
                                WHEN is_configurable = 1 THEN company_sales_price_total
                                ELSE company_sales_price_total * (SELECT qty FROM order_products p WHERE p.id = order_products.order_products_parent_id)
                            END
                        )
                    INTO cp_base, cs_base
                    FROM order_products
                    WHERE order_id = NEW.id;

                    SET NEW.company_purchase_price_base = cp_base;
                    SET NEW.company_purchase_price_total = cp_base + COALESCE(NEW.company_purchase_price_discount, 0);
                    SET NEW.company_sales_price_base = cs_base;
                    SET NEW.company_sales_price_total = cs_base + COALESCE(NEW.company_sales_price_discount, 0);
                ELSE
                    SET NEW.company_purchase_price_base = 0;
                    SET NEW.company_purchase_price_total = 0 + COALESCE(NEW.company_purchase_price_discount, 0);
                    SET NEW.company_sales_price_base = 0;
                    SET NEW.company_sales_price_total = 0 + COALESCE(NEW.company_sales_price_discount, 0);
                END IF;
            END
        ');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_orders_before_update');
    }
};
