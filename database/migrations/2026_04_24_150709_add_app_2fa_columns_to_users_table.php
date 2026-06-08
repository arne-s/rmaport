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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('requires_app_2fa')->default(false);
            $table->text('app_authentication_secret')->nullable();
            $table->longText('app_authentication_recovery_codes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'requires_app_2fa',
                'app_authentication_secret',
                'app_authentication_recovery_codes',
            ]);
        });
    }
};
