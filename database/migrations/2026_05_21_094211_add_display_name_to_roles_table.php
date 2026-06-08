<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DISPLAY_NAMES = [
        'manager' => 'Beheerder',
        'mechanic' => 'Monteur',
        'advisor' => 'Adviseur',
        'Super Admin' => 'Super Admin',
    ];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('name');
        });

        foreach (DB::table('roles')->select('id', 'name')->get() as $role) {
            $displayName = self::DISPLAY_NAMES[$role->name] ?? $role->name;

            DB::table('roles')
                ->where('id', $role->id)
                ->update(['display_name' => $displayName]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('display_name');
        });
    }
};
