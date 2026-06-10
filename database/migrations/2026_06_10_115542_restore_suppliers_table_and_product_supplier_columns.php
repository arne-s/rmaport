<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('email_supplier')->nullable();
                $table->string('contact_email')->nullable();
                $table->string('first_name')->nullable();
                $table->string('middle_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('phone_number')->nullable();
                $table->string('mobile_number')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('class')->nullable();
                $table->json('admin_fields')->nullable();
                $table->string('reference')->nullable();
                $table->boolean('sync_with_exact')->default(false);
                $table->string('exact_code')->nullable();
                $table->string('exact_id')->nullable();
                $table->string('kvk_number')->nullable();
                $table->string('vat_number')->nullable();
                $table->foreignId('exact_payment_condition_id')
                    ->nullable()
                    ->constrained('exact_payment_conditions')
                    ->nullOnDelete();
                $table->foreignId('exact_gl_account_id')
                    ->nullable()
                    ->constrained('exact_gl_accounts')
                    ->nullOnDelete();
                $table->foreignId('exact_vat_code_id')
                    ->nullable()
                    ->constrained('exact_vat_codes')
                    ->nullOnDelete();
                $table->string('street')->nullable();
                $table->string('house_number')->nullable();
                $table->string('postcode')->nullable();
                $table->string('city')->nullable();
                $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'supplier_id')) {
                $table->foreignId('supplier_id')
                    ->nullable()
                    ->after('category_id')
                    ->constrained('suppliers')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('products', 'supplier_product_uid')) {
                $table->string('supplier_product_uid')->nullable()->after('supplier_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'supplier_product_uid')) {
                $table->dropColumn('supplier_product_uid');
            }

            if (Schema::hasColumn('products', 'supplier_id')) {
                $table->dropForeign(['supplier_id']);
                $table->dropColumn('supplier_id');
            }
        });

        Schema::dropIfExists('suppliers');
    }
};
