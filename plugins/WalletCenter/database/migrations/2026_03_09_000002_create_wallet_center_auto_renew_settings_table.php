<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('wallet_center_auto_renew_settings')) {
            return;
        }

        Schema::create('wallet_center_auto_renew_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('period', 64)->nullable();
            $table->integer('renew_window_hours')->default(24);
            $table->timestamp('next_scan_at')->nullable();
            $table->timestamp('last_result_at')->nullable();
            $table->string('last_result', 32)->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_center_auto_renew_settings');
    }
};
