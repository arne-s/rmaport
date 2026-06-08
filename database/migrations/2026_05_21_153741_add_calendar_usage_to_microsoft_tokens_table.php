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
            $table->string('calendar_usage')->nullable()->after('calendar_name');
            $table->string('calendar_display_name')->nullable()->after('calendar_usage');
        });

        if (Schema::hasTable('microsoft_appointment_type_tokens')) {
            $this->migrateAppointmentTypeTokens();
        }

        $this->dedupeCategoryMappingsByUser();

        Schema::table('microsoft_category_mappings', function (Blueprint $table): void {
            $table->unique(['microsoft_token_id', 'user_id'], 'ms_cat_token_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('microsoft_category_mappings', function (Blueprint $table): void {
            $table->dropUnique('ms_cat_token_user_unique');
        });

        Schema::table('microsoft_tokens', function (Blueprint $table): void {
            $table->dropColumn(['calendar_usage', 'calendar_display_name']);
        });
    }

    private function migrateAppointmentTypeTokens(): void
    {
        $rows = DB::table('microsoft_appointment_type_tokens')
            ->whereNotNull('microsoft_token_id')
            ->get();

        $byToken = $rows->groupBy('microsoft_token_id');

        foreach ($byToken as $tokenId => $mappings) {
            $types = $mappings->pluck('appointment_type');

            $usage = null;
            if ($types->intersect(['fitting', 'delivery'])->isNotEmpty()) {
                $usage = 'passing_delivery';
            } elseif ($types->contains('service')) {
                $usage = 'service';
            }

            if ($usage === null) {
                continue;
            }

            $displayName = null;
            foreach (['fitting', 'delivery', 'service'] as $type) {
                $row = $mappings->firstWhere('appointment_type', $type);
                if ($row !== null && filled($row->display_name ?? null)) {
                    $displayName = $row->display_name;
                    break;
                }
            }

            DB::table('microsoft_tokens')
                ->where('id', $tokenId)
                ->update([
                    'calendar_usage' => $usage,
                    'calendar_display_name' => $displayName,
                ]);
        }
    }

    private function dedupeCategoryMappingsByUser(): void
    {
        $duplicateGroups = DB::table('microsoft_category_mappings')
            ->select('microsoft_token_id', 'user_id')
            ->whereNotNull('user_id')
            ->groupBy('microsoft_token_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $ids = DB::table('microsoft_category_mappings')
                ->where('microsoft_token_id', $group->microsoft_token_id)
                ->where('user_id', $group->user_id)
                ->orderBy('id')
                ->pluck('id');

            $ids->shift();

            if ($ids->isNotEmpty()) {
                DB::table('microsoft_category_mappings')->whereIn('id', $ids)->delete();
            }
        }
    }
};
