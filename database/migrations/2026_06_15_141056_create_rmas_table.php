<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rmas')) {
            Schema::create('rmas', function (Blueprint $table): void {
                $this->defineRmasTable($table);
            });

            return;
        }

        $this->dropLegacyColumns();
        $this->ensureRelationColumns();
    }

    public function down(): void
    {
        Schema::dropIfExists('rmas');
    }

    private function defineRmasTable(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('import_row_id')->nullable()->constrained('import_rows')->nullOnDelete();
        $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

        $table->string('uid', 20)->unique();
        $table->unsignedSmallInteger('quantity')->default(1);
        $table->text('accessories')->nullable();

        $table->text('return_reason')->nullable();
        $table->string('packing_slip_number', 100)->nullable();
        $table->string('payment_method', 50)->nullable();
        $table->text('complaint')->nullable();
        $table->string('assessment', 30)->nullable();
        $table->text('service')->nullable();
        $table->text('notes')->nullable();
        $table->string('status', 30)->default('open');
        $table->boolean('is_draft')->default(false);

        $table->boolean('reminder')->default(false);
        $table->boolean('is_warranty')->default(false);
        $table->boolean('is_processed')->default(false);
        $table->boolean('is_refurbish')->default(false);
        $table->boolean('is_invoiced')->default(false);

        $table->dateTime('received_at')->nullable();
        $table->dateTime('reminded_at')->nullable();
        $table->dateTime('processed_at')->nullable();
        $table->timestamps();

        $table->index(['status', 'created_at']);
        $table->index('is_draft');
        $table->index('customer_id');
        $table->index('import_row_id');
        $table->index('product_id');
    }

    private function dropLegacyColumns(): void
    {
        if ($this->indexExists('rmas', 'rmas_order_nr_index')) {
            Schema::table('rmas', function (Blueprint $table): void {
                $table->dropIndex('rmas_order_nr_index');
            });
        }

        if ($this->indexExists('rmas', 'rmas_barcode_index')) {
            Schema::table('rmas', function (Blueprint $table): void {
                $table->dropIndex('rmas_barcode_index');
            });
        }

        if (Schema::hasColumn('rmas', 'import_id')) {
            Schema::table('rmas', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('import_id');
            });
        }

        $columns = array_values(array_filter([
            'order_nr',
            'barcode',
            'defect_id',
            'global_id',
            'ean',
            'article_number',
            'brand',
            'product_group',
            'product_name',
            'serial_number',
            'imei',
            'product_condition',
            'graded_type',
            'return_sub_reason',
            'location_name',
            'location_code',
            'external_location_id',
            'language',
            'purchased_at',
            'returned_at',
            'reference',
            'is_doa',
        ], fn (string $column): bool => Schema::hasColumn('rmas', $column)));

        if ($columns === []) {
            return;
        }

        Schema::table('rmas', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    private function ensureRelationColumns(): void
    {
        Schema::table('rmas', function (Blueprint $table): void {
            if (! Schema::hasColumn('rmas', 'import_row_id')) {
                $table->foreignId('import_row_id')
                    ->nullable()
                    ->after('customer_id')
                    ->constrained('import_rows')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('rmas', 'product_id')) {
                $table->foreignId('product_id')
                    ->nullable()
                    ->after(Schema::hasColumn('rmas', 'import_row_id') ? 'import_row_id' : 'customer_id')
                    ->constrained()
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('rmas', 'assessment')) {
                $table->string('assessment', 30)->nullable()->after('complaint');
            }
        });

        if (Schema::hasColumn('rmas', 'import_row_id') && ! $this->indexExists('rmas', 'rmas_import_row_id_index')) {
            Schema::table('rmas', function (Blueprint $table): void {
                $table->index('import_row_id');
            });
        }

        if (Schema::hasColumn('rmas', 'product_id') && ! $this->indexExists('rmas', 'rmas_product_id_index')) {
            Schema::table('rmas', function (Blueprint $table): void {
                $table->index('product_id');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'select 1 from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
            [$database, $table, $index],
        );

        return $result !== [];
    }
};
