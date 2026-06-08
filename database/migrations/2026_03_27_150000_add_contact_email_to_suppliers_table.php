<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('contact_email')->nullable()->after('email_supplier');
        });

        DB::table('suppliers')
            ->whereNull('contact_email')
            ->whereNotNull('email_supplier')
            ->update(['contact_email' => DB::raw('email_supplier')]);
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn('contact_email');
        });
    }
};
