<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('fitting_attendees');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->json('fitting_note_new')->nullable()->after('fitting_appointment_id');
        });

        DB::table('orders')->whereNotNull('fitting_note')->orderBy('id')->chunkById(100, function (\Illuminate\Support\Collection $rows): void {
            foreach ($rows as $row) {
                $current = $row->fitting_note;
                $decoded = is_string($current) ? json_decode($current, true) : $current;
                $data = is_array($decoded) ? $decoded : ['general_info' => $current];
                DB::table('orders')->where('id', $row->id)->update(['fitting_note_new' => json_encode($data)]);
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('fitting_note');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('fitting_note_new', 'fitting_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('fitting_note_old')->nullable()->after('fitting_appointment_id');
        });

        DB::table('orders')->whereNotNull('fitting_note')->each(function (object $row): void {
            $data = json_decode($row->fitting_note, true);
            $text = is_array($data) && isset($data['general_info'])
                ? $data['general_info']
                : (is_string($data) ? $data : $row->fitting_note);
            DB::table('orders')->where('id', $row->id)->update(['fitting_note_old' => $text]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('fitting_note');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('fitting_note_old', 'fitting_note');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->text('fitting_attendees')->nullable()->after('fitting_note');
        });
    }
};
