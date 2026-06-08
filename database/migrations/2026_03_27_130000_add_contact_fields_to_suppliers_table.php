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
            $table->string('email_supplier')->nullable()->after('email');
            $table->string('first_name')->nullable()->after('email_supplier');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone_number')->nullable()->after('last_name');
            $table->string('mobile_number')->nullable()->after('phone_number');
        });

        DB::table('suppliers')
            ->whereNotNull('email')
            ->update(['email_supplier' => DB::raw('email')]);
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn([
                'email_supplier',
                'first_name',
                'last_name',
                'phone_number',
                'mobile_number',
            ]);
        });
    }
};
