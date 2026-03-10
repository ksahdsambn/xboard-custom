<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('wallet_center_auto_renew_records')) {
            return;
        }

        Schema::create('wallet_center_auto_renew_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('setting_id')->nullable()->index();
            $table->integer('amount')->default(0);
            $table->tinyInteger('status')->default(0)->index();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('reason', 191)->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_center_auto_renew_records');
    }
};
