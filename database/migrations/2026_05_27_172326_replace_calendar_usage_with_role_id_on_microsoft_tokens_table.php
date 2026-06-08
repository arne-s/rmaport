<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('microsoft_tokens', function (Blueprint $table): void {
            $table->foreignId('role_id')
                ->nullable()
                ->after('calendar_name')
                ->constrained('roles')
                ->nullOnDelete();
        });

        $advisorRoleId = DB::table('roles')->where('name', 'advisor')->where('guard_name', 'web')->value('id');
        $mechanicRoleId = DB::table('roles')->where('name', 'mechanic')->where('guard_name', 'web')->value('id');

        if ($advisorRoleId !== null) {
            DB::table('microsoft_tokens')
                ->where('calendar_usage', 'passing_delivery')
                ->update(['role_id' => $advisorRoleId]);
        }

        if ($mechanicRoleId !== null) {
            DB::table('microsoft_tokens')
                ->where('calendar_usage', 'service')
                ->update(['role_id' => $mechanicRoleId]);
        }

        Schema::table('microsoft_tokens', function (Blueprint $table): void {
            $table->dropColumn('calendar_usage');
            $table->unique('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('microsoft_tokens', function (Blueprint $table): void {
            $table->dropUnique(['role_id']);
            $table->string('calendar_usage')->nullable()->after('calendar_name');
        });

        $advisorRoleId = DB::table('roles')->where('name', 'advisor')->where('guard_name', 'web')->value('id');
        $mechanicRoleId = DB::table('roles')->where('name', 'mechanic')->where('guard_name', 'web')->value('id');

        if ($advisorRoleId !== null) {
            DB::table('microsoft_tokens')
                ->where('role_id', $advisorRoleId)
                ->update(['calendar_usage' => 'passing_delivery']);
        }

        if ($mechanicRoleId !== null) {
            DB::table('microsoft_tokens')
                ->where('role_id', $mechanicRoleId)
                ->update(['calendar_usage' => 'service']);
        }

        Schema::table('microsoft_tokens', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });
    }
};
