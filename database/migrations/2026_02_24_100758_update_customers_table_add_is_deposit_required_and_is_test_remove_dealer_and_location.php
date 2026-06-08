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
            // Add new columns
            $table->boolean('is_deposit_required')->default(false)->after('comment');
            $table->string('status')->default('active')->after('is_deposit_required');

            // Remove old columns
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');

            $table->dropForeign(['company_location_id']);
            $table->dropColumn('company_location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Remove new columns
            $table->dropColumn(['is_deposit_required', 'status']);

            // Restore old columns
            $table->foreignId('company_id')
                ->nullable()
                ->after('comment')
                ->constrained('companies')
                ->nullOnDelete();

            $table->foreignId('company_location_id')
                ->nullable()
                ->after('company_id')
                ->constrained('company_locations')
                ->nullOnDelete();
        });
    }
};

