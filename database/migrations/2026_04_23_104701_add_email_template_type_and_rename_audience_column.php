<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }

        Schema::table('email_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('email_templates', 'type')
                && ! Schema::hasColumn('email_templates', 'audience')) {
                $table->renameColumn('type', 'audience');
            }
        });

        Schema::table('email_templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('email_templates', 'type')) {
                $table->string('type', 32)->default('general')->after('id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }

        Schema::table('email_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('email_templates', 'type')
                && Schema::hasColumn('email_templates', 'audience')) {
                $table->dropColumn('type');
            }
        });

        Schema::table('email_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('email_templates', 'audience')
                && ! Schema::hasColumn('email_templates', 'type')) {
                $table->renameColumn('audience', 'type');
            }
        });
    }
};
