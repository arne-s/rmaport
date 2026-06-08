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
        if (Schema::hasColumn('orders', 'company_location_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('company_location_id');
            });
        }

        if (Schema::hasTable('company_locations')) {
            Schema::drop('company_locations');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('company_locations')) {
            Schema::create('company_locations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name')->nullable();
                $table->string('first_name')->nullable();
                $table->string('middle_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone_number')->nullable();
                $table->string('mobile_number')->nullable();
                $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('orders', 'company_location_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('company_location_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('company_locations')
                    ->nullOnDelete();
            });
        }
    }
};
