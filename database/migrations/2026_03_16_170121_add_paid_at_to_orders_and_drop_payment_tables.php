<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('paid_at')->nullable()->after('payment_id');
        });

        DB::table('orders')
            ->join('payments', 'orders.payment_id', '=', 'payments.id')
            ->whereNotNull('payments.paid_at')
            ->update(['orders.paid_at' => DB::raw('payments.paid_at')]);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });

        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_links');
    }

    public function down(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->string('payment_id')->nullable();
            $table->string('mode')->nullable();
            $table->string('description')->nullable();
            $table->string('link')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 12, 4);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('payment_link_id')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('first_reminder_at')->nullable();
            $table->dateTime('second_reminder_at')->nullable();
            $table->string('method')->nullable();
            $table->timestamps();

            $table->foreign('payment_link_id')->references('id')->on('payment_links');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable()->after('paid_at');
            $table->foreign('payment_id')->references('id')->on('payments');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
    }
};
