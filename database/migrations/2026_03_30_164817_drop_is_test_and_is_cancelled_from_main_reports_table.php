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
        if (! Schema::hasTable('main_reports')) {
            return;
        }

        $toDrop = [];
        if (Schema::hasColumn('main_reports', 'is_test')) {
            $toDrop[] = 'is_test';
        }
        if (Schema::hasColumn('main_reports', 'is_cancelled')) {
            $toDrop[] = 'is_cancelled';
        }
        if ($toDrop !== []) {
            Schema::table('main_reports', function (Blueprint $table) use ($toDrop): void {
                $table->dropColumn($toDrop);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('main_reports')) {
            return;
        }

        Schema::table('main_reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('main_reports', 'is_test')) {
                $table->unsignedTinyInteger('is_test')->default(0);
            }
            if (! Schema::hasColumn('main_reports', 'is_cancelled')) {
                $table->unsignedTinyInteger('is_cancelled')->nullable();
            }
        });
    }
};
