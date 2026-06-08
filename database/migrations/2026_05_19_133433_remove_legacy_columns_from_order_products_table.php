<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $columns = [
        'form_values',
        'price_config',
        'is_included',
        'is_custom',
        'price_params',
        'comment',
    ];

    public function up(): void
    {
        $columns = collect($this->columns)
            ->filter(fn (string $col): bool => Schema::hasColumn('order_products', $col))
            ->values()
            ->all();

        if ($columns === []) {
            return;
        }

        Schema::table('order_products', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_products', 'form_values')) {
                $table->json('form_values')->nullable();
            }
            if (! Schema::hasColumn('order_products', 'price_config')) {
                $table->json('price_config')->nullable();
            }
            if (! Schema::hasColumn('order_products', 'is_included')) {
                $table->boolean('is_included')->default(false);
            }
            if (! Schema::hasColumn('order_products', 'is_custom')) {
                $table->boolean('is_custom')->default(false);
            }
            if (! Schema::hasColumn('order_products', 'price_params')) {
                $table->json('price_params')->nullable();
            }
            if (! Schema::hasColumn('order_products', 'comment')) {
                $table->text('comment')->nullable();
            }
        });
    }
};
