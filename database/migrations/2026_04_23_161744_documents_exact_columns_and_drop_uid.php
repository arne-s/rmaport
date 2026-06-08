<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces three steps: transient "kind" column (add + drop), Exact sync columns on documents, drop uid.
     * Idempotent for environments that already ran the previous split migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            if (Schema::hasColumn('documents', 'kind')) {
                $table->dropIndex('documents_documentable_kind_index');
                $table->dropColumn('kind');
            }
        });

        Schema::table('documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('documents', 'exact_id')) {
                $table->string('exact_id', 64)->nullable()->after('documentable_id');
            }
            if (! Schema::hasColumn('documents', 'exact_synced_at')) {
                $table->timestamp('exact_synced_at')->nullable()->after('exact_id');
            }
            if (! Schema::hasColumn('documents', 'exact_error_at')) {
                $table->timestamp('exact_error_at')->nullable()->after('exact_synced_at');
            }
        });

        Schema::table('documents', function (Blueprint $table): void {
            if (Schema::hasColumn('documents', 'uid')) {
                $table->dropColumn('uid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('documents', 'uid')) {
                $table->string('uid')->nullable()->after('documentable_id');
            }
        });

        Schema::table('documents', function (Blueprint $table): void {
            if (Schema::hasColumn('documents', 'exact_error_at')) {
                $table->dropColumn('exact_error_at');
            }
            if (Schema::hasColumn('documents', 'exact_synced_at')) {
                $table->dropColumn('exact_synced_at');
            }
            if (Schema::hasColumn('documents', 'exact_id')) {
                $table->dropColumn('exact_id');
            }
        });
    }
};
