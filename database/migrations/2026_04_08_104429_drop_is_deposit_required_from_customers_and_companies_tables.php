<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customers', 'is_deposit_required')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropColumn('is_deposit_required');
            });
        }

        if (Schema::hasColumn('companies', 'is_deposit_required')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('is_deposit_required');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('customers', 'is_deposit_required')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->boolean('is_deposit_required')->default(false)->after('comment');
            });
        }

        if (! Schema::hasColumn('companies', 'is_deposit_required')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->boolean('is_deposit_required')->default(false);
            });
        }
    }
};
