<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_templates')) {
            Schema::create('import_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('filename');
                $table->string('class');
                $table->string('type', 20)->default('file');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sources')) {
            Schema::create('sources', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('import_template_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('import_templates') && ! Schema::hasColumn('import_templates', 'source_id')) {
            Schema::table('import_templates', function (Blueprint $table): void {
                $table->foreignId('source_id')->nullable()->after('description')->constrained()->nullOnDelete();
            });
        }

        if (Schema::hasTable('imports')) {
            Schema::table('imports', function (Blueprint $table): void {
                if (! Schema::hasColumn('imports', 'import_template_id')) {
                    $table->foreignId('import_template_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
                }

                if (! Schema::hasColumn('imports', 'track_trace_nr')) {
                    $table->string('track_trace_nr')->nullable()->after('import_template_id');
                }

                if (! Schema::hasColumn('imports', 'reference')) {
                    $table->string('reference')->nullable()->after('track_trace_nr');
                }

                if (! Schema::hasColumn('imports', 'shipment_date')) {
                    $table->date('shipment_date')->nullable()->after('reference');
                }
            });
        }

        if (! Schema::hasTable('import_rows')) {
            Schema::create('import_rows', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
                $table->string('source_description')->nullable();
                $table->string('customer_nr')->nullable();
                $table->string('customer_order_id')->nullable();
                $table->string('reference')->nullable();
                $table->string('assignment_nr')->nullable();
                $table->string('ean_nr', 20)->nullable();
                $table->boolean('is_doa')->default(false);
                $table->date('purchase_date')->nullable();
                $table->date('return_date')->nullable();
                $table->text('return_reason')->nullable();
                $table->dateTime('received_at')->nullable();
                $table->string('accessories')->nullable();
                $table->timestamps();

                $table->index('reference');
                $table->index('customer_order_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('sources');

        if (Schema::hasTable('import_templates') && Schema::hasColumn('import_templates', 'source_id')) {
            Schema::table('import_templates', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('source_id');
            });
        }

        Schema::dropIfExists('import_templates');

        if (Schema::hasTable('imports')) {
            Schema::table('imports', function (Blueprint $table): void {
                if (Schema::hasColumn('imports', 'import_template_id')) {
                    $table->dropConstrainedForeignId('import_template_id');
                }

                $columns = array_values(array_filter([
                    Schema::hasColumn('imports', 'track_trace_nr') ? 'track_trace_nr' : null,
                    Schema::hasColumn('imports', 'reference') ? 'reference' : null,
                    Schema::hasColumn('imports', 'shipment_date') ? 'shipment_date' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
