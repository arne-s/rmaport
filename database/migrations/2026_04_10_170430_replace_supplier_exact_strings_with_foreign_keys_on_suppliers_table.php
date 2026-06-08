<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) {
            return;
        }

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->foreignId('exact_payment_condition_id')
                ->nullable()
                ->constrained('exact_payment_conditions')
                ->nullOnDelete();
            $table->foreignId('exact_gl_account_id')
                ->nullable()
                ->constrained('exact_gl_accounts')
                ->nullOnDelete();
            $table->foreignId('exact_vat_code_id')
                ->nullable()
                ->constrained('exact_vat_codes')
                ->nullOnDelete();
        });

        foreach (DB::table('suppliers')->orderBy('id')->cursor() as $row) {
            $updates = [];

            if (Schema::hasColumn('suppliers', 'exact_payment_condition') && ! empty($row->exact_payment_condition)) {
                $id = DB::table('exact_payment_conditions')
                    ->where('code', $row->exact_payment_condition)
                    ->value('id');
                if ($id !== null) {
                    $updates['exact_payment_condition_id'] = $id;
                }
            }

            if (Schema::hasColumn('suppliers', 'exact_gl_account') && ! empty($row->exact_gl_account)) {
                $id = DB::table('exact_gl_accounts')
                    ->whereRaw('LOWER(guid) = ?', [strtolower((string) $row->exact_gl_account)])
                    ->value('id');
                if ($id !== null) {
                    $updates['exact_gl_account_id'] = $id;
                }
            }

            if (Schema::hasColumn('suppliers', 'exact_vat_code') && ! empty($row->exact_vat_code)) {
                $id = DB::table('exact_vat_codes')
                    ->where('code', $row->exact_vat_code)
                    ->whereIn('vat_transaction_type', ['P', 'B'])
                    ->value('id');
                if ($id === null) {
                    $id = DB::table('exact_vat_codes')
                        ->where('code', $row->exact_vat_code)
                        ->value('id');
                }
                if ($id !== null) {
                    $updates['exact_vat_code_id'] = $id;
                }
            }

            if ($updates !== []) {
                DB::table('suppliers')->where('id', $row->id)->update($updates);
            }
        }

        Schema::table('suppliers', function (Blueprint $table): void {
            if (Schema::hasColumn('suppliers', 'exact_payment_condition')) {
                $table->dropColumn('exact_payment_condition');
            }
            if (Schema::hasColumn('suppliers', 'exact_gl_account')) {
                $table->dropColumn('exact_gl_account');
            }
            if (Schema::hasColumn('suppliers', 'exact_vat_code')) {
                $table->dropColumn('exact_vat_code');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('suppliers')) {
            return;
        }

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('exact_payment_condition')->nullable();
            $table->string('exact_gl_account')->nullable();
            $table->string('exact_vat_code')->nullable();
        });

        foreach (DB::table('suppliers')->orderBy('id')->cursor() as $row) {
            $updates = [];
            if (! empty($row->exact_payment_condition_id)) {
                $code = DB::table('exact_payment_conditions')->where('id', $row->exact_payment_condition_id)->value('code');
                if ($code !== null) {
                    $updates['exact_payment_condition'] = $code;
                }
            }
            if (! empty($row->exact_gl_account_id)) {
                $guid = DB::table('exact_gl_accounts')->where('id', $row->exact_gl_account_id)->value('guid');
                if ($guid !== null) {
                    $updates['exact_gl_account'] = $guid;
                }
            }
            if (! empty($row->exact_vat_code_id)) {
                $code = DB::table('exact_vat_codes')->where('id', $row->exact_vat_code_id)->value('code');
                if ($code !== null) {
                    $updates['exact_vat_code'] = $code;
                }
            }
            if ($updates !== []) {
                DB::table('suppliers')->where('id', $row->id)->update($updates);
            }
        }

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropForeign(['exact_payment_condition_id']);
            $table->dropForeign(['exact_gl_account_id']);
            $table->dropForeign(['exact_vat_code_id']);
            $table->dropColumn([
                'exact_payment_condition_id',
                'exact_gl_account_id',
                'exact_vat_code_id',
            ]);
        });
    }
};
