<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('import_template_id');
            $table->dropColumn(['track_trace_nr', 'reference', 'shipment_date']);
        });
    }
};
