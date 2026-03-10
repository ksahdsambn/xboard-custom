<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('wallet_center_topup_orders')) {
            return;
        }

        Schema::create('wallet_center_topup_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('trade_no')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->string('payment_method', 64)->nullable()->index();
            $table->string('payment_plugin_code', 64)->nullable()->index();
            $table->integer('amount')->default(0);
            $table->integer('handling_amount')->default(0);
            $table->tinyInteger('status')->default(0)->index();
            $table->string('callback_no', 128)->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->json('channel_snapshot')->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_center_topup_orders');
    }
};
