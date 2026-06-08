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
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_showroom_id_foreign');
            $table->dropColumn('showroom_id');

            $table->foreignId('company_location_id')
                ->nullable()
                ->after('session_id')
                ->constrained('showrooms')
                ->nullOnDelete();
        });

        Schema::rename('showrooms', 'company_locations');

        Schema::table('company_locations', function (Blueprint $table) {
            $table->dropColumn('uid');
            $table->dropColumn('active');
            $table->dropColumn('exact_id');

            $table->foreignId('company_id')
                ->after('id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('first_name')->nullable()->after('name');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->string('email')->nullable()->after('last_name');
            $table->string('phone_number')->nullable()->after('email');
            $table->string('mobile_number')->nullable()->after('phone_number');

            $table->foreignId('address_id')
                ->nullable()
                ->after('mobile_number')
                ->constrained('addresses')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_locations', function (Blueprint $table) {
            //
        });
    }
};
