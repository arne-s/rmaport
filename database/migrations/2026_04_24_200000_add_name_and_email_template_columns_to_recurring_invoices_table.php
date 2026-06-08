<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoices', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('email_subject')->nullable();
            $table->longText('email_text')->nullable();
            $table->json('email_cc')->nullable();
            $table->json('email_bcc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'email_subject',
                'email_text',
                'email_cc',
                'email_bcc',
            ]);
        });
    }
};
