<?php

use App\Models\DeliveryNote;
use App\Support\PackingSlipDocumentSequence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->string('uid')->nullable()->after('order_id');
        });

        foreach (DeliveryNote::query()->orderBy('id')->cursor() as $deliveryNote) {
            $deliveryNote->forceFill([
                'uid' => PackingSlipDocumentSequence::next(),
            ])->saveQuietly();
        }

        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->string('uid')->nullable(false)->change();
            $table->unique('uid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropUnique(['uid']);
            $table->dropColumn('uid');
        });
    }
};
