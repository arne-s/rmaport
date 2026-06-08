<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop FKs in other tables that reference companies
        Schema::table('notes', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
        });

        Schema::table('recurring_invoices', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
        });

        Schema::table('main_reports', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
        });

        // Drop self-referential FK on companies itself, then the table
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropForeign(['shipping_company_id']);
        });

        Schema::dropIfExists('companies');
    }

    public function down(): void
    {
        // Re-create a minimal companies table stub for rollback purposes.
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }
};
