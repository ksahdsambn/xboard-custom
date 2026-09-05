<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_deletion_requests')) {
            return;
        }

        Schema::create('mobile_app_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status', 32);
            $table->char('confirmation_token_hash', 64);
            $table->boolean('play_subscription_warning_ack')->default(false);
            $table->string('request_id', 36);
            $table->string('environment', 16);
            $table->timestamp('retain_until')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->string('last_error', 64)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'confirmation_token_hash'], 'mobile_app_deletion_requests_user_confirm_uq');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_deletion_requests');
    }
};
