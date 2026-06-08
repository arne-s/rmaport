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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('status')->default('active')->after('id')->change();

            $table->string('type')->nullable()->after('status');
            $table->string('reason_inactive')->nullable()->after('is_deposit_required');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('status')->default('active')->after('is_deposit_required')->change();

            $table->dropColumn('type');
            $table->dropColumn('reason_inactive');
        });
    }
};
