<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_of_material_product', function (Blueprint $table) {
            $table->unsignedInteger('sort')->default(0)->after('qty');
        });

        $rows = DB::table('bill_of_material_product')
            ->orderBy('bill_of_material_id')
            ->orderBy('id')
            ->get(['id', 'bill_of_material_id']);

        $sortByBom = [];

        foreach ($rows as $row) {
            $bomId = $row->bill_of_material_id;
            $sortByBom[$bomId] = ($sortByBom[$bomId] ?? 0) + 1;
            DB::table('bill_of_material_product')
                ->where('id', $row->id)
                ->update(['sort' => $sortByBom[$bomId]]);
        }
    }

    public function down(): void
    {
        Schema::table('bill_of_material_product', function (Blueprint $table) {
            $table->dropColumn('sort');
        });
    }
};
