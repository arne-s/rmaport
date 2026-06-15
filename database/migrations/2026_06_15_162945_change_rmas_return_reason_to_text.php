<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rmas', 'return_reason')) {
            return;
        }

        Schema::table('rmas', function (Blueprint $table): void {
            $table->text('return_reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('rmas', 'return_reason')) {
            return;
        }

        Schema::table('rmas', function (Blueprint $table): void {
            $table->string('return_reason', 100)->nullable()->change();
        });
    }
};
