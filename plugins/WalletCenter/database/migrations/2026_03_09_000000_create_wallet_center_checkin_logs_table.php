<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('wallet_center_checkin_logs')) {
            return;
        }

        Schema::create('wallet_center_checkin_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->date('claim_date')->index();
            $table->integer('reward_amount')->default(0);
            $table->string('status', 32)->default('pending')->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_center_checkin_logs');
    }
};
