<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DISPLAY_NAMES = [
        'access filament panel' => 'Toegang tot het panel',
        'manage filament roles and permissions' => 'Rollen en permissies beheren',
        'manage outlook' => 'Outlook beheren',
    ];

    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('name');
        });

        foreach (DB::table('permissions')->select('id', 'name')->get() as $permission) {
            $displayName = self::DISPLAY_NAMES[$permission->name] ?? $permission->name;

            DB::table('permissions')
                ->where('id', $permission->id)
                ->update(['display_name' => $displayName]);
        }
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table): void {
            $table->dropColumn('display_name');
        });
    }
};
