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
        Schema::table('addresses', function (Blueprint $table): void {
            if (! Schema::hasColumn('addresses', 'phone_number')) {
                if (Schema::hasColumn('addresses', 'email')) {
                    $table->string('phone_number')->nullable()->after('email');
                } else {
                    $table->string('phone_number')->nullable();
                }
            }

            if (! Schema::hasColumn('addresses', 'mobile_phone_number')) {
                if (Schema::hasColumn('addresses', 'phone_number')) {
                    $table->string('mobile_phone_number')->nullable()->after('phone_number');
                } else {
                    $table->string('mobile_phone_number')->nullable();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            if (Schema::hasColumn('addresses', 'mobile_phone_number')) {
                $table->dropColumn('mobile_phone_number');
            }

            if (Schema::hasColumn('addresses', 'phone_number')) {
                $table->dropColumn('phone_number');
            }
        });
    }
};
