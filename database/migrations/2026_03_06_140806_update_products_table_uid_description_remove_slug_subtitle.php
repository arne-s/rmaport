<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('uid')->nullable()->after('id');
        });

        foreach (DB::table('products')->select('id')->get() as $row) {
            DB::table('products')->where('id', $row->id)->update(['uid' => 'ART-' . $row->id]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('uid')->nullable(false)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'description')) {
                $table->text('description')->nullable()->after('uid');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['slug', 'subtitle']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
            $table->string('subtitle')->nullable()->after('slug');
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'description')) {
                $table->dropColumn('description');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('uid');
        });
    }
};
