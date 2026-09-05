<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_purchase_tokens')) {
            return;
        }

        Schema::create('mobile_app_purchase_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('platform', 32);
            $table->char('purchase_token_hash', 64);
            $table->string('product_id', 191);
            $table->string('package_name', 191);
            $table->string('environment', 16);
            $table->string('play_status', 32);
            $table->boolean('acknowledged')->default(false);
            $table->string('obfuscated_account_id', 128)->nullable();
            $table->string('external_subscription_id', 191)->nullable();
            $table->string('request_id', 36);
            $table->string('last_error', 64)->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();
            $table->unique(['platform', 'purchase_token_hash'], 'mobile_app_purchase_tokens_platform_token_uq');
            $table->unique(['platform', 'external_subscription_id'], 'mobile_app_purchase_tokens_platform_sub_uq');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_purchase_tokens');
    }
};
