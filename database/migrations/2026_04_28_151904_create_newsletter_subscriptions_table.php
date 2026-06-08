<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('subscribable');
            $table->string('segment_key', 64);
            $table->string('email');
            $table->boolean('subscribed')->default(false);
            $table->timestamp('consented_at')->nullable();
            $table->string('consent_source', 64)->nullable();
            $table->boolean('needs_sync')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['subscribable_type', 'subscribable_id', 'segment_key'], 'newsletter_subscriptions_subscribable_segment_unique');
            $table->index('email');
            $table->index(['needs_sync', 'subscribed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscriptions');
    }
};
