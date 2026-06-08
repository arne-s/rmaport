<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('exact_article_group_gl_account')) {
            Schema::create('exact_article_group_gl_account', function (Blueprint $table) {
                $table->id();
                $table->foreignId('exact_article_group_id')->constrained('exact_article_groups')->cascadeOnDelete();
                $table->foreignId('exact_gl_account_id')->constrained('exact_gl_accounts')->cascadeOnDelete();
                $table->string('type');
                $table->timestamps();

                $table->unique(['exact_article_group_id', 'type'], 'article_group_gl_account_group_type_unique');
            });

            // Migrate existing gl_account_guid data into the pivot table as type 'revenue'
            $rows = DB::table('exact_article_groups')
                ->whereNotNull('gl_account_guid')
                ->where('gl_account_guid', '!=', '')
                ->get(['id', 'gl_account_guid']);

            foreach ($rows as $row) {
                $glAccount = DB::table('exact_gl_accounts')
                    ->where('guid', $row->gl_account_guid)
                    ->first();

                if ($glAccount) {
                    DB::table('exact_article_group_gl_account')->insert([
                        'exact_article_group_id' => $row->id,
                        'exact_gl_account_id' => $glAccount->id,
                        'type' => 'revenue',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasColumn('exact_article_groups', 'gl_account_name')) {
            Schema::table('exact_article_groups', function (Blueprint $table) {
                $table->dropColumn(['gl_account_name', 'gl_account_guid']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('exact_article_groups', function (Blueprint $table) {
            $table->string('gl_account_name')->default('');
            $table->string('gl_account_guid')->default('');
        });

        $pivotRows = DB::table('exact_article_group_gl_account')
            ->where('type', 'revenue')
            ->get();

        foreach ($pivotRows as $row) {
            $glAccount = DB::table('exact_gl_accounts')->find($row->exact_gl_account_id);
            if ($glAccount) {
                DB::table('exact_article_groups')
                    ->where('id', $row->exact_article_group_id)
                    ->update([
                        'gl_account_name' => $glAccount->code.' : '.$glAccount->name,
                        'gl_account_guid' => $glAccount->guid,
                    ]);
            }
        }

        Schema::dropIfExists('exact_article_group_gl_account');
    }
};
