<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('addresses')) {
            Schema::table('addresses', function (Blueprint $table): void {
                if (! Schema::hasColumn('addresses', 'name')) {
                    $table->string('name')->nullable()->after('postcode');
                }

                if (! Schema::hasColumn('addresses', 'email')) {
                    $table->string('email')->nullable()->after('name');
                }
            });
        }

        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table): void {
                if (! Schema::hasColumn('companies', 'address_id')) {
                    $table->foreignId('address_id')
                        ->nullable()
                        ->after('status')
                        ->constrained('addresses')
                        ->nullOnDelete();
                }
            });

            if (Schema::hasColumn('companies', 'billing_address_id')) {
                DB::table('companies')
                    ->whereNotNull('billing_address_id')
                    ->update([
                        'address_id' => DB::raw('billing_address_id'),
                    ]);

                Schema::table('companies', function (Blueprint $table): void {
                    $table->dropConstrainedForeignId('billing_address_id');
                });
            }

            Schema::table('companies', function (Blueprint $table): void {
                if (Schema::hasColumn('companies', 'billing_name')) {
                    $table->dropColumn('billing_name');
                }

                if (Schema::hasColumn('companies', 'billing_email')) {
                    $table->dropColumn('billing_email');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table): void {
                if (! Schema::hasColumn('companies', 'billing_name')) {
                    $table->string('billing_name')->nullable()->after('status');
                }

                if (! Schema::hasColumn('companies', 'billing_email')) {
                    $table->string('billing_email')->nullable()->after('billing_name');
                }
            });

            Schema::table('companies', function (Blueprint $table): void {
                if (! Schema::hasColumn('companies', 'billing_address_id')) {
                    $table->foreignId('billing_address_id')
                        ->nullable()
                        ->after('billing_email')
                        ->constrained('addresses')
                        ->nullOnDelete();
                }
            });

            if (Schema::hasColumn('companies', 'address_id')) {
                DB::table('companies')
                    ->whereNotNull('address_id')
                    ->update([
                        'billing_address_id' => DB::raw('address_id'),
                    ]);

                Schema::table('companies', function (Blueprint $table): void {
                    $table->dropConstrainedForeignId('address_id');
                });
            }
        }

        if (Schema::hasTable('addresses')) {
            Schema::table('addresses', function (Blueprint $table): void {
                if (Schema::hasColumn('addresses', 'email')) {
                    $table->dropColumn('email');
                }

                if (Schema::hasColumn('addresses', 'name')) {
                    $table->dropColumn('name');
                }
            });
        }
    }
};
