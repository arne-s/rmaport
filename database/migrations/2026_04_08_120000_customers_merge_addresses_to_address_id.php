<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'address_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->foreignId('address_id')
                    ->nullable()
                    ->after('dob')
                    ->constrained('addresses')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('customers', 'billing_address_id') || Schema::hasColumn('customers', 'shipping_address_id')) {
            DB::statement(
                'UPDATE customers SET address_id = COALESCE(address_id, billing_address_id, shipping_address_id)'
            );
        }

        $this->dropCustomerForeignKeyOnColumnIfExists('shipping_address_id');
        $this->dropCustomerForeignKeyOnColumnIfExists('billing_address_id');

        Schema::table('customers', function (Blueprint $table): void {
            $cols = array_filter([
                Schema::hasColumn('customers', 'shipping_address_id') ? 'shipping_address_id' : null,
                Schema::hasColumn('customers', 'billing_address_id') ? 'billing_address_id' : null,
                Schema::hasColumn('customers', 'delivery_address_type') ? 'delivery_address_type' : null,
            ]);
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'shipping_address_id')) {
                $table->foreignId('shipping_address_id')
                    ->nullable()
                    ->after('last_name')
                    ->constrained('addresses')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('customers', 'billing_address_id')) {
                $table->foreignId('billing_address_id')
                    ->nullable()
                    ->after('shipping_address_id')
                    ->constrained('addresses')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('customers', 'delivery_address_type')) {
                $table->string('delivery_address_type')
                    ->default('contact');
            }
        });

        DB::statement(
            'UPDATE customers SET billing_address_id = address_id WHERE billing_address_id IS NULL AND address_id IS NOT NULL'
        );

        if (Schema::hasColumn('customers', 'address_id')) {
            $this->dropCustomerForeignKeyOnColumnIfExists('address_id');
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropColumn('address_id');
            });
        }
    }

    private function dropCustomerForeignKeyOnColumnIfExists(string $column): void
    {
        if (! Schema::hasColumn('customers', $column)) {
            return;
        }

        $database = Schema::getConnection()->getDatabaseName();
        $rows = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, 'customers', $column]
        );

        foreach ($rows as $row) {
            $name = $row->CONSTRAINT_NAME ?? null;
            if (is_string($name) && $name !== '') {
                DB::statement('ALTER TABLE `customers` DROP FOREIGN KEY `' . str_replace('`', '``', $name) . '`');
            }
        }
    }
};
